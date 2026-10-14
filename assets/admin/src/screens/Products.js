/**
 * Filter and list products.
 */

/**
 * External dependencies
 */
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
	Button,
	SearchControl,
	SelectControl,
	Notice,
} from '@wordpress/components';

/**
 * Internal dependencies
 */
import { get, getList, post } from '../api/client';
import { clampPage, useHashQuery } from '../app/useHashQuery';
import BulkActionsBar, {
	BulkResultNotice,
	SelectAllHeader,
	SelectRowCell,
	useRowSelection,
} from '../components/BulkActionsBar';
import EmptyState from '../components/EmptyState';
import Pagination from '../components/Pagination';
import ScreenHeader from '../components/ScreenHeader';
import { SkeletonTable } from '../components/Skeleton';
import SortableHeader, {
	useSortableColumns,
} from '../components/SortableHeader';
import StatusBadge from '../components/StatusBadge';
import { buildHash } from '../router/hashQuery';
import { formatMinor } from '../utils/currency';
import { joinList, listSeparator } from '../utils/list';
import { firstReadPending, listState } from '../utils/listState';

/**
 * The number of products shown per page.
 *
 * @type {number}
 */
const PER_PAGE = 20;

/**
 * Format product prices from minor units.
 * {@link formatMinor} so a JPY 500 reads as 500, not 5.
 *
 * @param {Array}  prices   The product's price rows.
 * @param {string} currency The store currency code.
 * @return {string} The prices written out in one line, or a "no price" label.
 */
function priceSummary( prices, currency ) {
	if ( ! Array.isArray( prices ) || prices.length === 0 ) {
		return __( 'No price', 'tiermint' );
	}
	return joinList(
		prices.map( ( price ) => formatMinor( price.amount, currency ) )
	);
}

/**
 * Map a product status to its human-readable label.
 *
 * @param {string} status The raw status value.
 * @return {string} The translated status label.
 */
function statusLabel( status ) {
	switch ( status ) {
		case 'published':
			return __( 'Published', 'tiermint' );
		case 'archived':
			return __( 'Archived', 'tiermint' );
		default:
			return __( 'Draft', 'tiermint' );
	}
}

/**
 * Map a product type to its human-readable label.
 *
 * @param {string} type The raw type value.
 * @return {string} The translated type label.
 */
function typeLabel( type ) {
	return type === 'bundle'
		? __( 'Bundle', 'tiermint' )
		: __( 'Digital', 'tiermint' );
}

/**
 * The question a bulk delete asks before it runs.
 *
 * @param {number} count How many products are selected.
 * @return {string} The question.
 */
function deleteConfirmText( count ) {
	return sprintf(
		/* translators: %d: how many products are about to be deleted. */
		_n(
			'Delete %d product permanently? This cannot be undone.',
			'Delete %d products permanently? This cannot be undone.',
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
		{ value: 'publish', label: __( 'Publish', 'tiermint' ) },
		{ value: 'unpublish', label: __( 'Move to draft', 'tiermint' ) },
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
 * @param {string} action The action that ran.
 * @param {number} count  How many products it got through.
 * @return {string} The sentence, empty when none of them landed.
 */
function bulkSummary( action, count ) {
	if ( count === 0 ) {
		return '';
	}

	if ( action === 'publish' ) {
		return sprintf(
			/* translators: %d: how many products were published. */
			_n(
				'%d product published.',
				'%d products published.',
				count,
				'tiermint'
			),
			count
		);
	}

	if ( action === 'unpublish' ) {
		return sprintf(
			/* translators: %d: how many products were moved to draft. */
			_n(
				'%d product moved to draft.',
				'%d products moved to draft.',
				count,
				'tiermint'
			),
			count
		);
	}

	return sprintf(
		/* translators: %d: how many products were deleted. */
		_n( '%d product deleted.', '%d products deleted.', count, 'tiermint' ),
		count
	);
}

/**
 * Format an ISO date string as a short localized date.
 *
 * @param {string} value The ISO date string.
 * @return {string} The formatted date, or an empty string when unparseable.
 */
function formatDate( value ) {
	if ( ! value ) {
		return '';
	}
	const date = new Date( value );
	return Number.isNaN( date.getTime() ) ? '' : date.toLocaleDateString();
}

/**
 * Render category links that reset the list to page one.
 *
 * Show categories without a slug as plain text.
 *
 * @param {Object} props            Component props.
 * @param {Array}  props.categories The row's categories, each `{id, name, slug}`.
 * @return {Array} The links, or an empty list when the row carries none.
 */
function CategoryLinks( { categories } ) {
	const items = Array.isArray( categories ) ? categories : [];

	return items.map( ( category, index ) => (
		<span key={ category.id ?? `i${ index }` }>
			{ index > 0 && listSeparator() }
			{ category.slug ? (
				<a
					href={ buildHash( '#/products', {
						category: category.slug,
					} ) }
				>
					{ category.name }
				</a>
			) : (
				category.name
			) }
		</span>
	) );
}

/**
 * Build the option list for a term filter, prefixed with an "all" entry.
 *
 * @param {Array}  terms   The term objects from the terms endpoint.
 * @param {string} allText The label for the leading "all" option.
 * @return {Array<{label: string, value: string}>} The select options.
 */
function termOptions( terms, allText ) {
	return [
		{ label: allText, value: '' },
		...( Array.isArray( terms ) ? terms : [] ).map( ( term ) => ( {
			label: term.name,
			value: term.slug,
		} ) ),
	];
}

/**
 * Build an inert option for an unavailable term list.
 * Only when there are no terms in hand. See {@link termsInHand}: this is not
 * the state a failed refetch produces.
 *
 * @param {string} unavailableText The label naming what could not be loaded.
 * @return {Array<{label: string, value: string}>} A single, inert option.
 */
function unavailableOptions( unavailableText ) {
	return [ { label: unavailableText, value: '' } ];
}

/**
 * Check whether a term query has usable data.
 *
 * A failed refetch can keep the last good list.
 *
 * @param {Object} query The React Query result for a terms read.
 * @return {boolean} True when the query holds a term array to render.
 */
function termsInHand( query ) {
	return Array.isArray( query.data );
}

/**
 * Render the products list screen.
 *
 * @param {Object} props      Component props.
 * @param {Object} props.boot The normalized boot object (used for currency).
 * @return {Element} The products screen element.
 */
export default function Products( { boot } ) {
	const currency =
		( boot && boot.settings && boot.settings.currency ) || 'USD';

	const { page, params, setPage, setFilter, setFilters, replacePage } =
		useHashQuery( {
			search: '',
			status: '',
			category: '',
			tag: '',
			orderby: 'created_at',
			order: 'desc',
		} );
	const { search, status, category, tag, orderby, order } = params;
	const sort = useSortableColumns( { orderby, order, setFilters } );

	// Filter terms for the dropdowns; both are small, unpaginated lists.
	const categoriesQuery = useQuery( {
		queryKey: [ 'terms', 'category' ],
		queryFn: () => get( '/terms', { taxonomy: 'category' } ),
	} );
	const tagsQuery = useQuery( {
		queryKey: [ 'terms', 'tag' ],
		queryFn: () => get( '/terms', { taxonomy: 'tag' } ),
	} );

	const listQuery = useQuery( {
		queryKey: [
			'products',
			{ page, search, status, category, tag, orderby, order },
		],
		queryFn: () =>
			getList( '/products', {
				page,
				per_page: PER_PAGE,
				// Drop empty filters so the client omits them from the query.
				search: search || undefined,
				status: status || undefined,
				category: category || undefined,
				tag: tag || undefined,
				orderby,
				order,
			} ),
	} );

	const { items, total, totalPages, waiting, inHand } =
		listState( listQuery );
	const filtered = Boolean( search || status || category || tag );

	const queryClient = useQueryClient();
	const [ bulkResult, setBulkResult ] = useState( null );
	const [ bulkError, setBulkError ] = useState( '' );

	// Reset selection when the displayed rows change.
	const rowsKey = JSON.stringify( [
		page,
		search,
		status,
		category,
		tag,
		orderby,
		order,
	] );

	const selection = useRowSelection( {
		ids: items.map( ( row ) => row.id ),
		resetKey: rowsKey,
	} );

	const bulkMutation = useMutation( {
		mutationFn: ( { action, ids } ) =>
			post( '/products/batch', { ids, action } ),
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

			// Refresh product lists, editors and pickers.
			queryClient.invalidateQueries( { queryKey: [ 'products' ] } );
			queryClient.invalidateQueries( { queryKey: [ 'product' ] } );

			// Deleted products can change license labels.
			if ( action === 'delete' ) {
				queryClient.invalidateQueries( { queryKey: [ 'licenses' ] } );
			}

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
		clampPage( {
			page,
			totalPages,
			ready: inHand,
			onClamp: replacePage,
		} );
	}, [ page, totalPages, inHand, replacePage ] );

	// Results belong only to the view that started the batch.
	useEffect( () => {
		setBulkResult( null );
		setBulkError( '' );
	}, [ rowsKey ] );

	const newProductButton = (
		<Button variant="primary" href="#/products/new">
			{ __( 'New product', 'tiermint' ) }
		</Button>
	);

	return (
		<section className="tiermint-admin-screen">
			<ScreenHeader
				title={ __( 'Products', 'tiermint' ) }
				actions={ newProductButton }
			/>

			<div className="tiermint-toolbar">
				<SearchControl
					__nextHasNoMarginBottom
					label={ __( 'Search products', 'tiermint' ) }
					value={ search }
					onChange={ ( value ) => setFilter( 'search', value ) }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					hideLabelFromVision
					label={ __( 'Status', 'tiermint' ) }
					value={ status }
					options={ [
						{ label: __( 'All statuses', 'tiermint' ), value: '' },
						{ label: __( 'Draft', 'tiermint' ), value: 'draft' },
						{
							label: __( 'Published', 'tiermint' ),
							value: 'published',
						},
						{
							label: __( 'Archived', 'tiermint' ),
							value: 'archived',
						},
					] }
					onChange={ ( value ) => setFilter( 'status', value ) }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					hideLabelFromVision
					/* Keep a populated filter usable after a failed refetch. */
					disabled={
						! termsInHand( categoriesQuery ) &&
						( firstReadPending( categoriesQuery ) ||
							categoriesQuery.isError )
					}
					label={ __( 'Category', 'tiermint' ) }
					value={ category }
					options={
						! termsInHand( categoriesQuery ) &&
						categoriesQuery.isError
							? unavailableOptions(
									__( 'Categories unavailable', 'tiermint' )
							  )
							: termOptions(
									categoriesQuery.data,
									__( 'All categories', 'tiermint' )
							  )
					}
					onChange={ ( value ) => setFilter( 'category', value ) }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					hideLabelFromVision
					disabled={
						! termsInHand( tagsQuery ) &&
						( firstReadPending( tagsQuery ) || tagsQuery.isError )
					}
					label={ __( 'Tag', 'tiermint' ) }
					value={ tag }
					options={
						! termsInHand( tagsQuery ) && tagsQuery.isError
							? unavailableOptions(
									__( 'Tags unavailable', 'tiermint' )
							  )
							: termOptions(
									tagsQuery.data,
									__( 'All tags', 'tiermint' )
							  )
					}
					onChange={ ( value ) => setFilter( 'tag', value ) }
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
						items.find( ( row ) => row.id === id )?.name
					}
					onDismiss={ () => setBulkResult( null ) }
				/>
			) }

			{ waiting && <SkeletonTable columns={ 7 } /> }

			{ /* Show empty state only after a successful read. */ }
			{ inHand && items.length === 0 && (
				<EmptyState
					message={
						filtered
							? __(
									'No products match these filters.',
									'tiermint'
							  )
							: __(
									'No products yet. Products you create appear here.',
									'tiermint'
							  )
					}
					action={ filtered ? undefined : newProductButton }
				/>
			) }

			{ items.length > 0 && (
				<BulkActionsBar
					label={ __( 'Bulk actions for products', 'tiermint' ) }
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
				<table className="wp-list-table widefat striped">
					<thead>
						<tr>
							<SelectAllHeader selection={ selection } />
							<SortableHeader
								column="name"
								label={ __( 'Name', 'tiermint' ) }
								sort={ sort }
							/>
							<th scope="col">{ __( 'Status', 'tiermint' ) }</th>
							<th scope="col">{ __( 'Type', 'tiermint' ) }</th>
							<th scope="col">{ __( 'Price', 'tiermint' ) }</th>
							<th scope="col">
								{ __( 'Categories', 'tiermint' ) }
							</th>
							<SortableHeader
								column="created_at"
								label={ __( 'Created', 'tiermint' ) }
								sort={ sort }
							/>
						</tr>
					</thead>
					<tbody>
						{ items.map( ( row ) => (
							<tr key={ row.id }>
								<SelectRowCell
									selection={ selection }
									id={ row.id }
									label={ row.name }
								/>
								<td>
									<a href={ `#/products/${ row.id }` }>
										{ row.name }
									</a>
								</td>
								<td>
									<StatusBadge
										status={ row.status }
										label={ statusLabel( row.status ) }
									/>
								</td>
								<td>{ typeLabel( row.type ) }</td>
								<td>
									{ priceSummary( row.prices, currency ) }
								</td>
								<td>
									<CategoryLinks
										categories={ row.categories }
									/>
								</td>
								<td>{ formatDate( row.created_at ) }</td>
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
 * Route registry entry for the products screen.
 *
 * @type {{path: string, title: string, capability: string, component: Function}}
 */
export const route = {
	path: '#/products',
	title: __( 'Products', 'tiermint' ),
	capability: 'manage_tiermint',
	component: Products,
};
