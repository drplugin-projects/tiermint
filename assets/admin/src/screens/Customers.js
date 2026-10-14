/**
 * Customers screen.
 *
 * Customer list at `#/customers`.
 *
 * Supports search, sorting, pagination and bulk deletion.
 */

/**
 * External dependencies
 */
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	Button,
	ComboboxControl,
	Modal,
	Notice,
	SearchControl,
	TextControl,
} from '@wordpress/components';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

/**
 * Internal dependencies
 */
import { get, getList, post, put } from '../api/client';
import BulkActionsBar, {
	BulkResultNotice,
	SelectAllHeader,
	SelectRowCell,
	useRowSelection,
} from '../components/BulkActionsBar';
import EmptyState from '../components/EmptyState';
import { clampPage, useHashQuery } from '../app/useHashQuery';
import Pagination from '../components/Pagination';
import { listState } from '../utils/listState';
import ScreenHeader from '../components/ScreenHeader';
import { SkeletonTable } from '../components/Skeleton';
import SortableHeader, {
	useSortableColumns,
} from '../components/SortableHeader';

/**
 * Rows fetched per page.
 *
 * @type {number}
 */
const PER_PAGE = 20;

/**
 * Client-side email shape check. The server remains authoritative.
 *
 * @type {RegExp}
 */
const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/**
 * How long the user search waits after the last keystroke, in milliseconds.
 *
 * @type {number}
 */
const SEARCH_DEBOUNCE = 300;

/**
 * Converts the assigned-user value to an API user ID.
 *
 * @param {string|number|null} raw The raw field value.
 * @return {number} The normalized user id, 0 meaning guest.
 */
function normalizeUserId( raw ) {
	const trimmed = String( raw ?? '' ).trim();
	if ( trimmed === '' ) {
		return 0;
	}
	const parsed = parseInt( trimmed, 10 );
	return Number.isNaN( parsed ) ? 0 : parsed;
}

/**
 * Formats an account picker label.
 *
 * @param {string} name  The account's display name.
 * @param {string} email The account's email address.
 * @return {string} The option label.
 */
function accountLabel( name, email ) {
	return sprintf(
		/* translators: 1: a primary label, 2: a secondary detail shown in parentheses. */
		__( '%1$s (%2$s)', 'tiermint' ),
		name,
		email
	);
}

/**
 * Gets the selected account label when search results omit it.
 *
 * @param {string}      selected The selected user id, as a string.
 * @param {Object|null} customer The customer being edited, or null when adding.
 * @param {Object|null} picked   The option chosen during this edit, if any.
 * @return {string|null} The label, or null when only the id is known.
 */
function selectedLabel( selected, customer, picked ) {
	if ( picked && picked.value === selected ) {
		return picked.label;
	}

	if (
		customer &&
		String( customer.user_id ) === selected &&
		customer.user_name
	) {
		return accountLabel( customer.user_name, customer.user_email );
	}

	return null;
}

/**
 * Builds picker options and keeps the selected account visible.
 *
 * @param {Array}         results  The rows the search endpoint returned.
 * @param {string|number} current  The currently selected user id, if any.
 * @param {Object|null}   customer The customer being edited, or null when adding.
 * @param {Object|null}   picked   The option chosen during this edit, if any.
 * @return {Array<{label: string, value: string}>} The combobox options.
 */
function userOptions( results, current, customer, picked ) {
	const options = ( Array.isArray( results ) ? results : [] ).map(
		( user ) => ( {
			label: accountLabel( user.name, user.email ),
			value: String( user.id ),
		} )
	);

	const selected = String( current ?? '' );

	if (
		selected === '' ||
		options.some( ( option ) => option.value === selected )
	) {
		return options;
	}

	options.unshift( {
		label:
			selectedLabel( selected, customer, picked ) ??
			sprintf(
				/* translators: %s: a WordPress user id, shown when the account behind it no longer exists. */
				__( 'User #%s', 'tiermint' ),
				selected
			),
		value: selected,
	} );

	return options;
}

/**
 * The question a bulk delete asks before it runs.
 *
 * @param {number} count How many customers are selected.
 * @return {string} The question.
 */
function deleteConfirmText( count ) {
	return sprintf(
		/* translators: %d: how many customers are about to be deleted. */
		_n(
			'Delete %d customer permanently? This cannot be undone.',
			'Delete %d customers permanently? This cannot be undone.',
			count,
			'tiermint'
		),
		count
	);
}

/**
 * The bulk actions this list offers, matching the batch route's whitelist.
 *
 * @return {Array<Object>} The entries for the bulk-actions bar.
 */
function bulkActions() {
	return [
		{
			value: 'delete',
			label: __( 'Delete', 'tiermint' ),
			isDestructive: true,
			confirmText: deleteConfirmText,
		},
	];
}

/**
 * The sentence a finished batch opens its notice with.
 *
 * @param {number} count How many customers were deleted.
 * @return {string} The sentence, empty when none of them were.
 */
function bulkSummary( count ) {
	if ( count === 0 ) {
		return '';
	}

	return sprintf(
		/* translators: %d: how many customers were deleted. */
		_n(
			'%d customer deleted.',
			'%d customers deleted.',
			count,
			'tiermint'
		),
		count
	);
}

/**
 * Gets display messages from a normalized REST error.
 *
 * @param {{message: string, errors: (Object|null)}} error The normalized
 *                                                         client error.
 * @return {string[]} The messages to display.
 */
function errorMessages( error ) {
	if ( ! error ) {
		return [];
	}
	if ( error.errors && typeof error.errors === 'object' ) {
		return Object.values( error.errors ).flatMap( ( value ) =>
			Array.isArray( value ) ? value : [ String( value ) ]
		);
	}
	return [ error.message ];
}

/**
 * Format an ISO timestamp as a locale date string for table display.
 *
 * @param {string} isoDate The ISO 8601 timestamp.
 * @return {string} A locale-formatted date, or the raw value if unparsable.
 */
function formatDate( isoDate ) {
	const date = new Date( isoDate );
	return Number.isNaN( date.getTime() ) ? isoDate : date.toLocaleDateString();
}

/**
 * Customer payload.
 *
 * @param {Object|null}                                   customer Customer.
 * @param {{email: string, name: string, userId: string}} form     Fields.
 * @return {Object} Payload.
 */
function buildPayload( customer, form ) {
	const normalizedUserId = normalizeUserId( form.userId );

	if ( ! customer ) {
		const payload = { email: form.email };
		if ( form.name.trim() !== '' ) {
			payload.name = form.name;
		}
		if ( normalizedUserId !== 0 ) {
			payload.user_id = normalizedUserId;
		}
		return payload;
	}

	const payload = {};
	if ( form.email !== customer.email ) {
		payload.email = form.email;
	}
	if ( form.name !== ( customer.name || '' ) ) {
		payload.name = form.name;
	}
	if ( normalizedUserId !== ( customer.user_id || 0 ) ) {
		payload.user_id = normalizedUserId;
	}
	return payload;
}

/**
 * Shows an account link only when the admin can edit that WordPress user.
 *
 * @param {Object}  props              Component props.
 * @param {Object}  props.customer     The customer row.
 * @param {boolean} props.canEditUsers Whether this admin may open the user editor.
 * @return {Element} The cell contents.
 */
function CustomerType( { customer, canEditUsers } ) {
	if ( ! customer.user_id ) {
		return <>{ __( 'Guest', 'tiermint' ) }</>;
	}

	const label = __( 'Registered', 'tiermint' );

	if ( ! canEditUsers ) {
		return <>{ label }</>;
	}

	return (
		<a
			href={ `user-edit.php?user_id=${ customer.user_id }` }
			aria-label={ sprintf(
				/* translators: %s: a customer's email address. */
				__( 'Edit the WordPress user for %s', 'tiermint' ),
				customer.email
			) }
		>
			{ label }
		</a>
	);
}

/**
 * The Add/Edit customer modal.
 *
 * @param {Object}      props          Component props.
 * @param {Object|null} props.customer The customer being edited, or null
 *                                     to add a new one.
 * @param {Function}    props.onClose  Called once the modal should close.
 * @return {Element} The modal element.
 */
function CustomerModal( { customer, onClose } ) {
	const queryClient = useQueryClient();
	const [ email, setEmail ] = useState( customer ? customer.email : '' );
	const [ name, setName ] = useState( customer ? customer.name || '' : '' );
	const [ userId, setUserId ] = useState(
		customer && customer.user_id ? String( customer.user_id ) : ''
	);
	const [ submitted, setSubmitted ] = useState( false );

	// What the admin has typed into the picker, and the settled version of it
	// the search actually runs on.
	const [ userTerm, setUserTerm ] = useState( '' );
	const [ searchTerm, setSearchTerm ] = useState( '' );
	// The option chosen during this edit, so the field keeps naming it after the
	// search results it came from have gone.
	const [ picked, setPicked ] = useState( null );

	useEffect( () => {
		const timer = setTimeout(
			() => setSearchTerm( userTerm.trim() ),
			SEARCH_DEBOUNCE
		);

		return () => clearTimeout( timer );
	}, [ userTerm ] );

	const userQuery = useQuery( {
		queryKey: [ 'users', 'search', searchTerm ],
		queryFn: () => get( '/users/search', { term: searchTerm } ),
		enabled: searchTerm !== '',
	} );

	const mutation = useMutation( {
		mutationFn: ( payload ) =>
			customer
				? put( `/customers/${ customer.id }`, payload )
				: post( '/customers', payload ),
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: [ 'customers' ] } );

			if ( customer ) {
				// Customer updates also refresh order and license names.
				queryClient.invalidateQueries( { queryKey: [ 'orders' ] } );
				queryClient.invalidateQueries( { queryKey: [ 'licenses' ] } );
				queryClient.invalidateQueries( { queryKey: [ 'license' ] } );
			}

			onClose();
		},
	} );

	const isEmailValid = EMAIL_PATTERN.test( email );
	const userChoices = userOptions( userQuery.data, userId, customer, picked );

	/**
	 * Validate the form and, if valid, submit the dirty-fields payload.
	 *
	 * @return {void}
	 */
	function handleSave() {
		setSubmitted( true );
		if ( ! isEmailValid ) {
			return;
		}
		mutation.mutate( buildPayload( customer, { email, name, userId } ) );
	}

	return (
		<Modal
			className="tiermint-modal"
			title={
				customer
					? __( 'Edit customer', 'tiermint' )
					: __( 'Add customer', 'tiermint' )
			}
			onRequestClose={ onClose }
		>
			<div className="tiermint-form-stack">
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Email', 'tiermint' ) }
					type="email"
					required
					value={ email }
					onChange={ setEmail }
				/>
				{ submitted && ! isEmailValid && (
					<Notice status="error" isDismissible={ false }>
						{ __( 'Enter a valid email address.', 'tiermint' ) }
					</Notice>
				) }
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Name', 'tiermint' ) }
					value={ name }
					onChange={ setName }
				/>
				<ComboboxControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Assigned user', 'tiermint' ) }
					value={ userId === '' ? null : userId }
					options={ userChoices }
					/* Puts a spinner in the field while the search is in
					   flight. Without it the control asserts "No items found"
					   over the whole round trip, which reads as an answer. */
					isLoading={ userQuery.isFetching }
					onFilterValueChange={ setUserTerm }
					onChange={ ( value ) => {
						setUserId( value === null ? '' : value );
						setPicked(
							userChoices.find(
								( option ) => option.value === value
							) ?? null
						);
					} }
					help={ __(
						'Search by name or email. Leave this empty to keep the customer a guest.',
						'tiermint'
					) }
				/>
				{ mutation.isError && (
					<Notice status="error" isDismissible={ false }>
						<ul>
							{ errorMessages( mutation.error ).map(
								( message ) => (
									<li key={ message }>{ message }</li>
								)
							) }
						</ul>
					</Notice>
				) }
			</div>
			<div className="tiermint-modal-actions">
				<Button variant="tertiary" onClick={ onClose }>
					{ __( 'Cancel', 'tiermint' ) }
				</Button>
				<Button
					variant="primary"
					onClick={ handleSave }
					isBusy={ mutation.isPending }
					disabled={ mutation.isPending }
				>
					{ __( 'Save', 'tiermint' ) }
				</Button>
			</div>
		</Modal>
	);
}

/**
 * Render the customers screen: search, a sortable/paginated list table, and
 * the Add/Edit modal.
 *
 * @param {Object} props      Component props.
 * @param {Object} props.boot The normalized boot object.
 * @return {Element} The customers screen element.
 */
export default function Customers( { boot } ) {
	const canEditUsers = Boolean( boot?.caps?.edit_users );

	const { page, params, setPage, setFilter, setFilters, replacePage } =
		useHashQuery( {
			search: '',
			orderby: 'created_at',
			order: 'desc',
		} );
	const { search, orderby, order } = params;
	// undefined: modal closed. null: add mode. object: edit mode for that row.
	const [ modalCustomer, setModalCustomer ] = useState( undefined );

	const currency =
		( boot && boot.settings && boot.settings.currency ) || 'USD';
	const formatter = useMemo(
		() =>
			new Intl.NumberFormat( undefined, { style: 'currency', currency } ),
		[ currency ]
	);

	const query = useQuery( {
		queryKey: [ 'customers', { page, search, orderby, order } ],
		queryFn: () =>
			getList( '/customers', {
				page,
				per_page: PER_PAGE,
				search: search || undefined,
				orderby,
				order,
			} ),
	} );

	/**
	 * Update the search term and jump back to the first page of results.
	 *
	 * @param {string} value The new search term.
	 * @return {void}
	 */
	function handleSearchChange( value ) {
		setFilter( 'search', value );
	}

	const sort = useSortableColumns( { orderby, order, setFilters } );

	const { items, total, totalPages, waiting, inHand } = listState( query );

	const queryClient = useQueryClient();
	const [ bulkResult, setBulkResult ] = useState( null );
	const [ bulkError, setBulkError ] = useState( '' );

	// The rows a selection and a result are about: change any of it and they are
	// other rows, whatever their ids.
	const rowsKey = JSON.stringify( [ page, search, orderby, order ] );

	const selection = useRowSelection( {
		ids: items.map( ( row ) => row.id ),
		resetKey: rowsKey,
	} );

	const bulkMutation = useMutation( {
		mutationFn: ( { action, ids } ) =>
			post( '/customers/batch', { ids, action } ),
		// The key the batch was fired against. A read of `rowsKey` in the
		// callbacks below would be the one in force when the answer lands, and
		// the merchant is free to change the view while a batch is out.
		onMutate: () => {
			setBulkResult( null );
			setBulkError( '' );

			return { rowsKey };
		},
		onSuccess: ( result, variables, context ) => {
			const failed = Array.isArray( result.failed ) ? result.failed : [];
			const succeeded = Array.isArray( result.succeeded )
				? result.succeeded
				: [];

			queryClient.invalidateQueries( { queryKey: [ 'customers' ] } );

			// A deletion also refreshes order and license names.
			queryClient.invalidateQueries( { queryKey: [ 'orders' ] } );
			queryClient.invalidateQueries( { queryKey: [ 'licenses' ] } );
			queryClient.invalidateQueries( { queryKey: [ 'license' ] } );

			// Skip stale batch notices after the list view changes.
			if ( context?.rowsKey !== rowsKey ) {
				return;
			}

			setBulkResult( {
				failed,
				succeeded,
			} );
			selection.drop( succeeded );
		},
		onError: ( error, variables, context ) => {
			if ( context?.rowsKey !== rowsKey ) {
				return;
			}

			setBulkError( error.message );
		},
	} );

	useEffect( () => {
		clampPage( { page, totalPages, ready: inHand, onClamp: replacePage } );
	}, [ page, totalPages, inHand, replacePage ] );

	// The result and the error belong to the rows they ran on, so they go when
	// those rows do. Hiding them instead would only bring them back the moment
	// the merchant returned to the same view.
	useEffect( () => {
		setBulkResult( null );
		setBulkError( '' );
	}, [ rowsKey ] );

	return (
		<section className="tiermint-admin-screen tiermint-admin-customers">
			<ScreenHeader
				title={ __( 'Customers', 'tiermint' ) }
				actions={
					<Button
						variant="primary"
						onClick={ () => setModalCustomer( null ) }
					>
						{ __( 'Add customer', 'tiermint' ) }
					</Button>
				}
			/>

			<div className="tiermint-toolbar">
				<SearchControl
					__nextHasNoMarginBottom
					label={ __( 'Search customers', 'tiermint' ) }
					placeholder={ __( 'Search by name or email', 'tiermint' ) }
					value={ search }
					onChange={ handleSearchChange }
				/>
			</div>

			{ waiting && <SkeletonTable columns={ 8 } /> }
			{ query.isError && (
				<Notice status="error" isDismissible={ false }>
					{ query.error.message }
				</Notice>
			) }

			{ /* A batch the server refused as a whole, which is a different thing
			     from the per-id refusals the notice below reports. */ }
			{ bulkError && (
				<Notice status="error" onRemove={ () => setBulkError( '' ) }>
					{ bulkError }
				</Notice>
			) }

			{ bulkResult && (
				<BulkResultNotice
					summary={ bulkSummary( bulkResult.succeeded.length ) }
					failed={ bulkResult.failed }
					labelFor={ ( id ) =>
						items.find( ( row ) => row.id === id )?.email
					}
					onDismiss={ () => setBulkResult( null ) }
				/>
			) }

			{ inHand && items.length === 0 && (
				<EmptyState
					message={
						search
							? __(
									'No customers match this search.',
									'tiermint'
							  )
							: __(
									'No customers yet. A customer record is created with the first purchase.',
									'tiermint'
							  )
					}
					action={
						search ? undefined : (
							<Button
								variant="primary"
								onClick={ () => setModalCustomer( null ) }
							>
								{ __( 'Add customer', 'tiermint' ) }
							</Button>
						)
					}
				/>
			) }

			{ items.length > 0 && (
				<BulkActionsBar
					label={ __( 'Bulk actions for customers', 'tiermint' ) }
					actions={ bulkActions() }
					count={ selection.count }
					pending={ bulkMutation.isPending }
					onApply={ ( action ) =>
						bulkMutation.mutate( {
							action,
							ids: selection.selected,
						} )
					}
				/>
			) }

			{ items.length > 0 && (
				<table className="tiermint-admin-table wp-list-table widefat striped">
					<thead>
						<tr>
							<SelectAllHeader selection={ selection } />
							<SortableHeader
								column="email"
								label={ __( 'Email', 'tiermint' ) }
								sort={ sort }
							/>
							<SortableHeader
								column="name"
								label={ __( 'Name', 'tiermint' ) }
								sort={ sort }
							/>
							<th scope="col">{ __( 'Type', 'tiermint' ) }</th>
							<th scope="col">
								{ __( 'Purchases', 'tiermint' ) }
							</th>
							<th scope="col">
								{ __( 'Lifetime total', 'tiermint' ) }
							</th>
							<SortableHeader
								column="created_at"
								label={ __( 'Created', 'tiermint' ) }
								sort={ sort }
							/>
							<th scope="col">{ __( 'Actions', 'tiermint' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ items.map( ( customer ) => (
							<tr key={ customer.id }>
								<SelectRowCell
									selection={ selection }
									id={ customer.id }
									label={ customer.email }
								/>
								<td>{ customer.email }</td>
								<td>{ customer.name || '' }</td>
								<td>
									<CustomerType
										customer={ customer }
										canEditUsers={ canEditUsers }
									/>
								</td>
								<td>{ customer.purchase_count }</td>
								<td>
									{ formatter.format(
										( customer.purchase_total || 0 ) / 100
									) }
								</td>
								<td>{ formatDate( customer.created_at ) }</td>
								<td>
									<Button
										variant="secondary"
										onClick={ () =>
											setModalCustomer( customer )
										}
									>
										{ __( 'Edit', 'tiermint' ) }
									</Button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			{ /* Outside the table's gate: a page turn empties `items` for the
			     length of the read, and a pager that goes with them takes the
			     button the merchant just pressed off the screen. */ }
			<Pagination
				page={ page }
				total={ total }
				totalPages={ totalPages }
				pending={ waiting }
				onChange={ setPage }
			/>

			{ modalCustomer !== undefined && (
				<CustomerModal
					key={ modalCustomer ? modalCustomer.id : 'new' }
					customer={ modalCustomer }
					onClose={ () => setModalCustomer( undefined ) }
				/>
			) }
		</section>
	);
}

/**
 * Route registry entry for the customers screen.
 *
 * @type {{path: string, title: string, capability: string, component: Function}}
 */
export const route = {
	path: '#/customers',
	title: __( 'Customers', 'tiermint' ),
	capability: 'manage_tiermint',
	component: Customers,
};
