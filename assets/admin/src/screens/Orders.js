/**
 * Filter and list orders.
 */

/**
 * External dependencies
 */
import { useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Notice, SearchControl, SelectControl } from '@wordpress/components';
import { useQuery } from '@tanstack/react-query';

/**
 * Internal dependencies
 */
import { getList } from '../api/client';
import { clampPage, useHashQuery } from '../app/useHashQuery';
import EmptyState from '../components/EmptyState';
import Pagination from '../components/Pagination';
import ScreenHeader from '../components/ScreenHeader';
import { SkeletonTable } from '../components/Skeleton';
import SortableHeader, {
	useSortableColumns,
} from '../components/SortableHeader';
import StatusBadge from '../components/StatusBadge';
import { formatMinor } from '../utils/currency';
import {
	formatOrderDate,
	orderProductSummary,
	orderTypeLabel,
} from '../utils/orderPresentation';
import { orderStatusLabel } from '../utils/orderStatus';
import { firstReadPending, listState } from '../utils/listState';

/**
 * Rows to request per page.
 *
 * @type {number}
 */
const PER_PAGE = 20;

/**
 * Maximum product options in the filter.
 *
 * @type {number}
 */
const FILTER_PRODUCTS = 100;

/**
 * Status filters in display order.
 *
 * @return {Array<{label: string, value: string}>} The status filter options.
 */
function statusOptions() {
	return [
		{ label: __( 'All statuses', 'tiermint' ), value: '' },
		{ label: __( 'Pending', 'tiermint' ), value: 'pending' },
		{ label: __( 'Completed', 'tiermint' ), value: 'completed' },
		{ label: __( 'Failed', 'tiermint' ), value: 'failed' },
		{ label: __( 'Abandoned', 'tiermint' ), value: 'abandoned' },
		{ label: __( 'Refunded', 'tiermint' ), value: 'refunded' },
		{
			label: __( 'Partially refunded', 'tiermint' ),
			value: 'partially_refunded',
		},
	];
}

/**
 * Build product filter options.
 *
 * Keep the active id when its product is not in the loaded page.
 *
 * @param {Array}  items    The products to offer.
 * @param {string} activeId The product id the view is filtered by, if any.
 * @return {Array<{label: string, value: string}>} The filter options.
 */
function productOptions( items, activeId ) {
	const options = ( Array.isArray( items ) ? items : [] ).map(
		( product ) => ( {
			label: product.name,
			value: String( product.id ),
		} )
	);

	if (
		activeId !== '' &&
		! options.some( ( option ) => option.value === activeId )
	) {
		options.unshift( {
			label: sprintf(
				/* translators: %s: a product id, shown when the product's name is not in the filter list. */
				__( 'Product #%s', 'tiermint' ),
				activeId
			),
			value: activeId,
		} );
	}

	return [
		{ label: __( 'All products', 'tiermint' ), value: '' },
		...options,
	];
}

/**
 * Render one order row.
 *
 * @param {Object}  props              Component props.
 * @param {Object}  props.order        The order row from the list endpoint.
 * @param {boolean} props.canEditUsers Whether this admin may open the user editor.
 * @return {Element} A table row element.
 */
function OrderRow( { order, canEditUsers } ) {
	// A refund is either an explicit refund-type row or any negative total.
	const isRefund = order.type === 'refund' || order.total < 0;
	const className = isRefund
		? 'tiermint-order-row tiermint-order-row--refund'
		: 'tiermint-order-row';

	// The name if the customer record carries one, else the email the order was
	// placed with, which every order has.
	const buyer = order.customer_name || order.email;

	return (
		<tr className={ className }>
			<td>
				<a href={ `#/orders/${ order.id }` }>{ `#${ order.id }` }</a>
			</td>
			<td>{ formatOrderDate( order.created_at ) }</td>
			<td>
				{ /* Link only users this admin may edit. */ }
				{ order.customer_user_id && canEditUsers ? (
					<a
						href={ `user-edit.php?user_id=${ order.customer_user_id }` }
					>
						{ buyer }
					</a>
				) : (
					buyer
				) }
			</td>
			<td>{ orderProductSummary( order.items ) }</td>
			<td>
				{ /* Keep the status selector used by end-to-end tests. */ }
				<StatusBadge
					status={ order.status }
					label={ orderStatusLabel( order.status ) }
					className="tiermint-status"
				/>
			</td>
			<td>{ orderTypeLabel( order.type ) }</td>
			<td className="tiermint-order-total">
				{ formatMinor( order.total, order.currency ) }
			</td>
			<td>{ order.gateway }</td>
		</tr>
	);
}

/**
 * Render the orders list screen.
 *
 * @param {Object} props      Component props.
 * @param {Object} props.boot The normalized boot object.
 * @return {Element} The orders screen element.
 */
export default function Orders( { boot } ) {
	const canEditUsers = Boolean( boot?.caps?.edit_users );

	const { page, params, setPage, setFilter, setFilters, replacePage } =
		useHashQuery( {
			status: '',
			search: '',
			product_id: '',
			orderby: 'created_at',
			order: 'desc',
		} );
	const { status, search, product_id: productId, orderby, order } = params;
	const sort = useSortableColumns( { orderby, order, setFilters } );

	const query = useQuery( {
		queryKey: [
			'orders',
			{ page, status, search, productId, orderby, order },
		],
		queryFn: () =>
			getList( '/orders', {
				page,
				per_page: PER_PAGE,
				status: status || undefined,
				search: search || undefined,
				product_id: productId || undefined,
				orderby,
				order,
			} ),
	} );

	// Use a separate, name-sorted query for the filter.
	const productsQuery = useQuery( {
		queryKey: [ 'products', 'filter' ],
		queryFn: () =>
			getList( '/products', {
				per_page: FILTER_PRODUCTS,
				orderby: 'name',
				order: 'asc',
			} ),
	} );

	// A failed refetch can still leave product data in hand.
	const productsInHand = Array.isArray( productsQuery.data?.items );
	const unreadableProducts = ! productsInHand && productsQuery.isError;

	/**
	 * Switch the status filter and jump back to the first page.
	 *
	 * @param {string} value The chosen status value.
	 * @return {void}
	 */
	const onStatusChange = ( value ) => setFilter( 'status', value );

	/**
	 * Update the search term and jump back to the first page.
	 *
	 * @param {string} value The search text.
	 * @return {void}
	 */
	const onSearchChange = ( value ) => setFilter( 'search', value );

	/**
	 * Switch the product filter and jump back to the first page.
	 *
	 * @param {string} value The chosen product id, or an empty string for all.
	 * @return {void}
	 */
	const onProductChange = ( value ) => setFilter( 'product_id', value );

	const { items, total, totalPages, waiting, inHand } = listState( query );

	useEffect( () => {
		clampPage( { page, totalPages, ready: inHand, onClamp: replacePage } );
	}, [ page, totalPages, inHand, replacePage ] );

	return (
		<section className="tiermint-admin-screen">
			<ScreenHeader title={ __( 'Orders', 'tiermint' ) } />

			<div className="tiermint-toolbar tiermint-orders-toolbar">
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					hideLabelFromVision
					label={ __( 'Status', 'tiermint' ) }
					value={ status }
					options={ statusOptions() }
					onChange={ onStatusChange }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					hideLabelFromVision
					/* Keep an active filter usable after a failed read. */
					disabled={
						productId === '' &&
						( unreadableProducts ||
							( ! productsInHand &&
								firstReadPending( productsQuery ) ) )
					}
					label={ __( 'Product', 'tiermint' ) }
					value={ productId }
					options={
						unreadableProducts && productId === ''
							? [
									{
										label: __(
											'Products unavailable',
											'tiermint'
										),
										value: '',
									},
							  ]
							: productOptions(
									productsQuery.data?.items,
									productId
							  )
					}
					onChange={ onProductChange }
				/>
				<SearchControl
					__nextHasNoMarginBottom
					label={ __( 'Search orders', 'tiermint' ) }
					value={ search }
					onChange={ onSearchChange }
				/>
			</div>

			{ waiting && <SkeletonTable columns={ 8 } /> }

			{ query.isError && (
				<Notice status="error" isDismissible={ false }>
					{ query.error && query.error.message
						? query.error.message
						: __( 'The orders could not be loaded.', 'tiermint' ) }
				</Notice>
			) }

			{ inHand && items.length === 0 && (
				<EmptyState
					message={
						status || search || productId
							? __( 'No orders match these filters.', 'tiermint' )
							: __(
									'No orders yet. Completed and pending purchases appear here.',
									'tiermint'
							  )
					}
				/>
			) }

			{ items.length > 0 && (
				<table className="tiermint-orders-table wp-list-table widefat striped">
					<thead>
						<tr>
							<SortableHeader
								column="id"
								label={ __( 'Order', 'tiermint' ) }
								sort={ sort }
							/>
							<SortableHeader
								column="created_at"
								label={ __( 'Date', 'tiermint' ) }
								sort={ sort }
							/>
							<th>{ __( 'Customer', 'tiermint' ) }</th>
							<th>{ __( 'Product', 'tiermint' ) }</th>
							<th>{ __( 'Status', 'tiermint' ) }</th>
							<th>{ __( 'Type', 'tiermint' ) }</th>
							<SortableHeader
								column="total"
								label={ __( 'Total', 'tiermint' ) }
								sort={ sort }
								className="tiermint-order-total"
							/>
							<th>{ __( 'Gateway', 'tiermint' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ items.map( ( row ) => (
							<OrderRow
								key={ row.id }
								order={ row }
								canEditUsers={ canEditUsers }
							/>
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
 * Route registry entry for the orders screen.
 *
 * @type {{path: string, title: string, capability: string, component: Function}}
 */
export const route = {
	path: '#/orders',
	title: __( 'Orders', 'tiermint' ),
	capability: 'manage_tiermint',
	component: Orders,
};
