/**
 * Filter and list licenses with masked keys.
 */

/**
 * External dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Notice, SearchControl, SelectControl } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { getList, post } from '../api/client';
import { clampPage, useHashQuery } from '../app/useHashQuery';
import BulkActionsBar, {
	BulkResultNotice,
	SelectAllHeader,
	SelectRowCell,
	useRowSelection,
} from '../components/BulkActionsBar';
import EmptyState from '../components/EmptyState';
import Pagination from '../components/Pagination';
import { listState } from '../utils/listState';
import ScreenHeader from '../components/ScreenHeader';
import { SkeletonTable } from '../components/Skeleton';
import SortableHeader, {
	useSortableColumns,
} from '../components/SortableHeader';
import StatusBadge from '../components/StatusBadge';

/**
 * Rows requested per page.
 *
 * @type {number}
 */
const PER_PAGE = 20;

/**
 * The status filter options, including the "all statuses" default.
 *
 * @type {Array<{label: string, value: string}>}
 */
const STATUS_FILTER_OPTIONS = [
	{ label: __( 'All statuses', 'tiermint' ), value: '' },
	{ label: __( 'Active', 'tiermint' ), value: 'active' },
	{ label: __( 'Expired', 'tiermint' ), value: 'expired' },
	{ label: __( 'Disabled', 'tiermint' ), value: 'disabled' },
];

/**
 * License status labels.
 *
 * @type {Object<string, string>}
 */
const STATUS_LABELS = {
	active: __( 'Active', 'tiermint' ),
	expired: __( 'Expired', 'tiermint' ),
	disabled: __( 'Disabled', 'tiermint' ),
};

/**
 * Format a date as stable YYYY-MM-DD text.
 *
 * @param {?string} dateString The ISO date-time string, or a falsy value for
 *                             a license that never expires.
 * @return {string} The formatted date, or a "never expires" label.
 */
function formatDate( dateString ) {
	if ( ! dateString ) {
		return __( 'Never', 'tiermint' );
	}
	const date = new Date( dateString );
	if ( Number.isNaN( date.getTime() ) ) {
		return dateString;
	}
	return date.toISOString().slice( 0, 10 );
}

/**
 * Format activation usage with an unlimited fallback.
 *
 * @param {number}  count The current activation count.
 * @param {?number} limit The activation limit, or a falsy value for
 *                        unlimited.
 * @return {string} The formatted usage string, e.g. "2 / 3".
 */
function formatUsage( count, limit ) {
	return sprintf(
		/* translators: 1: number of activations used, 2: the activation limit (or an infinity symbol for unlimited). */
		__( '%1$d / %2$s', 'tiermint' ),
		count,
		limit ? String( limit ) : '∞'
	);
}

/**
 * Get the enabled batch actions.
 *
 * @return {Array<Object>} The entries for the bulk-actions bar.
 */
function bulkActions() {
	return [
		{ value: 'disable', label: __( 'Disable', 'tiermint' ) },
		{ value: 'enable', label: __( 'Enable', 'tiermint' ) },
	];
}

/**
 * The sentence a finished batch opens its notice with.
 *
 * @param {string} action The action that ran.
 * @param {number} count  How many licenses it got through.
 * @return {string} The sentence, empty when none of them landed.
 */
function bulkSummary( action, count ) {
	if ( count === 0 ) {
		return '';
	}

	if ( action === 'disable' ) {
		return sprintf(
			/* translators: %d: how many licenses were disabled. */
			_n(
				'%d license disabled.',
				'%d licenses disabled.',
				count,
				'tiermint'
			),
			count
		);
	}

	return sprintf(
		/* translators: %d: how many licenses were enabled. */
		_n( '%d license enabled.', '%d licenses enabled.', count, 'tiermint' ),
		count
	);
}

/**
 * A referent the endpoint could not name, printed as the id it stores.
 *
 * @param {number} id The stored id.
 * @return {string} The id in the list's `#id` form.
 */
function idFallback( id ) {
	return sprintf(
		/* translators: %d: a numeric record id. */
		__( '#%d', 'tiermint' ),
		id
	);
}

/**
 * The customer cell: the owner's email, linked to that customer on the
 * customers list, or the stored id when the customer record is gone.
 *
 * @param {Object} props         Component props.
 * @param {Object} props.license The license row.
 * @return {Element} The cell contents.
 */
function CustomerCell( { license } ) {
	if ( ! license.customer_email ) {
		return <>{ idFallback( license.customer_id ) }</>;
	}

	return (
		<a
			href={ `#/customers?search=${ encodeURIComponent(
				license.customer_email
			) }` }
		>
			{ license.customer_email }
		</a>
	);
}

/**
 * Render the licenses list screen.
 *
 * @return {Element} The licenses screen element.
 */
export default function Licenses() {
	const { page, params, setPage, setFilter, setFilters, replacePage } =
		useHashQuery( {
			search: '',
			status: '',
			orderby: 'created_at',
			order: 'desc',
		} );
	const { search, status, orderby, order } = params;
	const sort = useSortableColumns( { orderby, order, setFilters } );

	const listQuery = useQuery( {
		queryKey: [ 'licenses', { page, search, status, orderby, order } ],
		queryFn: () =>
			getList( '/licenses', {
				page,
				per_page: PER_PAGE,
				search: search || undefined,
				status: status || undefined,
				orderby,
				order,
			} ),
	} );

	/**
	 * Update the search term and jump back to page one, since the previous
	 * page number may no longer exist under the new filter.
	 *
	 * @param {string} value The new search term.
	 * @return {void}
	 */
	function handleSearchChange( value ) {
		setFilter( 'search', value );
	}

	/**
	 * Update the status filter and jump back to page one.
	 *
	 * @param {string} value The new status filter value.
	 * @return {void}
	 */
	function handleStatusChange( value ) {
		setFilter( 'status', value );
	}

	const { items, total, totalPages, waiting, inHand } =
		listState( listQuery );

	const queryClient = useQueryClient();
	const [ bulkResult, setBulkResult ] = useState( null );
	const [ bulkError, setBulkError ] = useState( '' );

	// The rows a selection and a result are about: change any of it and they are
	// other rows, whatever their ids.
	const rowsKey = JSON.stringify( [ page, search, status, orderby, order ] );

	const selection = useRowSelection( {
		ids: items.map( ( row ) => row.id ),
		resetKey: rowsKey,
	} );

	const bulkMutation = useMutation( {
		mutationFn: ( { action, ids } ) =>
			post( '/licenses/batch', { ids, action } ),
		// Keep the request view for callbacks after navigation.
		onMutate: () => {
			setBulkResult( null );
			setBulkError( '' );

			return { rowsKey };
		},
		onSuccess: ( result, { action }, context ) => {
			const failed = Array.isArray( result.failed ) ? result.failed : [];
			const succeeded = Array.isArray( result.succeeded )
				? result.succeeded
				: [];

			// Both lists show the changed status.
			queryClient.invalidateQueries( { queryKey: [ 'licenses' ] } );
			queryClient.invalidateQueries( { queryKey: [ 'license' ] } );

			// Do not change notices or selection after navigation.
			if ( context?.rowsKey !== rowsKey ) {
				return;
			}

			setBulkResult( {
				action,
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

	// Results belong only to the view that started the batch.
	useEffect( () => {
		setBulkResult( null );
		setBulkError( '' );
	}, [ rowsKey ] );

	return (
		<section className="tiermint-admin-screen">
			<ScreenHeader title={ __( 'Licenses', 'tiermint' ) } />

			<div className="tiermint-toolbar tiermint-licenses-filters">
				<SearchControl
					__nextHasNoMarginBottom
					label={ __( 'Search licenses', 'tiermint' ) }
					value={ search }
					placeholder={ __(
						'Search by license key or email',
						'tiermint'
					) }
					onChange={ handleSearchChange }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					hideLabelFromVision
					label={ __( 'Status', 'tiermint' ) }
					value={ status }
					options={ STATUS_FILTER_OPTIONS }
					onChange={ handleStatusChange }
				/>
			</div>

			{ listQuery.isError && (
				<Notice status="error" isDismissible={ false }>
					{ listQuery.error.message }
				</Notice>
			) }

			{ /* This reports a batch-wide refusal. */ }
			{ bulkError && (
				<Notice status="error" onRemove={ () => setBulkError( '' ) }>
					{ bulkError }
				</Notice>
			) }

			{ bulkResult && (
				<BulkResultNotice
					summary={ bulkSummary(
						bulkResult.action,
						bulkResult.succeeded.length
					) }
					failed={ bulkResult.failed }
					labelFor={ ( id ) =>
						items.find( ( row ) => row.id === id )?.license_key
					}
					onDismiss={ () => setBulkResult( null ) }
				/>
			) }

			{ waiting && <SkeletonTable columns={ 7 } /> }

			{ inHand && items.length === 0 && (
				<EmptyState
					message={
						search || status
							? __(
									'No licenses match these filters.',
									'tiermint'
							  )
							: __(
									'No licenses yet. A license is issued for every purchase of a licensed product.',
									'tiermint'
							  )
					}
				/>
			) }

			{ items.length > 0 && (
				<BulkActionsBar
					label={ __( 'Bulk actions for licenses', 'tiermint' ) }
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
				<table className="tiermint-licenses-table wp-list-table widefat striped">
					<thead>
						<tr>
							<SelectAllHeader selection={ selection } />
							<th scope="col">
								{ __( 'License key', 'tiermint' ) }
							</th>
							<th scope="col">{ __( 'Status', 'tiermint' ) }</th>
							<th scope="col">{ __( 'Product', 'tiermint' ) }</th>
							<th scope="col">
								{ __( 'Customer', 'tiermint' ) }
							</th>
							<SortableHeader
								column="expires_at"
								label={ __( 'Expires', 'tiermint' ) }
								sort={ sort }
							/>
							<th scope="col">
								{ __( 'Activations', 'tiermint' ) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ items.map( ( license ) => (
							<tr key={ license.id }>
								<SelectRowCell
									selection={ selection }
									id={ license.id }
									label={ license.license_key }
								/>
								<td>
									<a
										href={ `#/licenses/${ license.id }` }
										className="tiermint-mono"
									>
										{ license.license_key }
									</a>
								</td>
								<td>
									<StatusBadge
										status={ license.status }
										label={
											STATUS_LABELS[ license.status ] ||
											license.status
										}
									/>
								</td>
								<td>
									{ license.product_name ||
										idFallback( license.product_id ) }
								</td>
								<td>
									<CustomerCell license={ license } />
								</td>
								<td>{ formatDate( license.expires_at ) }</td>
								<td>
									{ formatUsage(
										license.activation_count,
										license.activation_limit
									) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			{ /* Keep the pager visible while a new page loads. */ }
			<Pagination
				page={ page }
				total={ total }
				totalPages={ totalPages }
				pending={ waiting }
				onChange={ setPage }
			/>
		</section>
	);
}

/**
 * Route registry entry for the licenses screen.
 *
 * @type {{path: string, title: string, capability: string, component: Function}}
 */
export const route = {
	path: '#/licenses',
	title: __( 'Licenses', 'tiermint' ),
	capability: 'manage_tiermint',
	component: Licenses,
};
