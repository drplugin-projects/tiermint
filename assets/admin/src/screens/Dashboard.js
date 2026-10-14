/**
 * Dashboard with sales, links and latest orders.
 *
 * Extension links stay out of the metric grid because they have no component.
 */

/**
 * External dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { useQuery } from '@tanstack/react-query';

/**
 * Internal dependencies
 */
import { get, getList } from '../api/client';
import { useDashboardPanels } from '../app/dashboardPanels';
import ScreenHeader from '../components/ScreenHeader';
import { SkeletonTable } from '../components/Skeleton';
import StatusBadge from '../components/StatusBadge';
import { formatMinor } from '../utils/currency';
import {
	formatOrderDate,
	orderProductSummary,
	orderTypeLabel,
} from '../utils/orderPresentation';
import { firstReadPending } from '../utils/listState';
import { orderStatusLabel } from '../utils/orderStatus';

/**
 * Quick-link cards in display order.
 *
 * @type {Array<{path: string, capability: string, label: string, description: string}>}
 */
const QUICK_LINKS = [
	{
		path: '#/products',
		capability: 'manage_tiermint',
		label: __( 'Manage Products', 'tiermint' ),
		description: __(
			'Manage your digital products, prices, and releases.',
			'tiermint'
		),
	},
	{
		path: '#/orders',
		capability: 'manage_tiermint',
		label: __( 'Manage Orders', 'tiermint' ),
		description: __( 'Review orders, receipts, and refunds.', 'tiermint' ),
	},
	{
		path: '#/licenses',
		capability: 'manage_tiermint',
		label: __( 'Manage Licenses', 'tiermint' ),
		description: __(
			'Look up license keys and their activations.',
			'tiermint'
		),
	},
	{
		path: '#/discounts',
		capability: 'manage_tiermint',
		label: __( 'Manage Discounts', 'tiermint' ),
		description: __( 'Create and manage discount codes.', 'tiermint' ),
	},
	{
		path: '#/reports',
		capability: 'view_tiermint_reports',
		label: __( 'View Reports', 'tiermint' ),
		description: __( 'See sales totals and trends over time.', 'tiermint' ),
	},
	{
		path: '#/settings',
		capability: 'manage_tiermint_settings',
		label: __( 'Store Settings', 'tiermint' ),
		description: __(
			'Configure store details, currency, and gateways.',
			'tiermint'
		),
	},
];

/**
 * The fixed number of cards in the free sales summary.
 *
 * @type {number}
 */
const SALES_TILE_COUNT = 4;

/**
 * Check a capability in the boot map.
 *
 * @param {Object} caps       The `caps` map from the boot object.
 * @param {string} capability The capability key to check.
 * @return {boolean} True when access is granted.
 */
function hasCapability( caps, capability ) {
	return Boolean( caps && caps[ capability ] );
}

/**
 * Check whether an extension heading link is usable.
 *
 * @param {*} link The candidate heading link.
 * @return {boolean} True when the link has a non-empty href and label.
 */
function isHeadingLink( link ) {
	return Boolean(
		link &&
			typeof link === 'object' &&
			typeof link.href === 'string' &&
			link.href !== '' &&
			typeof link.label === 'string' &&
			link.label !== ''
	);
}

/**
 * Render a latest-order row.
 *
 * @param {Object}  props              Component props.
 * @param {Object}  props.order        The order row from the list endpoint.
 * @param {boolean} props.canEditUsers Whether this admin may open the user editor.
 * @return {Element} A table row element.
 */
function LatestOrderRow( { order, canEditUsers } ) {
	// The name if the customer record carries one, else the email the order was
	// placed with, which every order has.
	const buyer = order.customer_name || order.email;

	return (
		<tr>
			<td>
				<a href={ `#/orders/${ order.id }` }>{ `#${ order.id }` }</a>
			</td>
			<td>{ formatOrderDate( order.created_at ) }</td>
			<td>
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
				<StatusBadge
					status={ order.status }
					label={ orderStatusLabel( order.status ) }
				/>
			</td>
			<td className="tiermint-col--secondary">
				{ orderTypeLabel( order.type ) }
			</td>
			<td>{ formatMinor( order.total, order.currency ) }</td>
			<td className="tiermint-col--secondary">{ order.gateway }</td>
		</tr>
	);
}

/**
 * Render the dashboard screen.
 *
 * @param {Object} props      Component props.
 * @param {Object} props.boot The normalized boot object.
 * @return {Element} The dashboard screen element.
 */
export default function Dashboard( { boot } ) {
	const caps = ( boot && boot.caps ) || {};
	const currency =
		( boot && boot.settings && boot.settings.currency ) || 'USD';
	const canManage = hasCapability( caps, 'manage_tiermint' );
	const canViewReports = hasCapability( caps, 'view_tiermint_reports' );
	const canEditUsers = hasCapability( caps, 'edit_users' );
	const dashboardPanels = useDashboardPanels( caps );

	// Link panels have no component and stay out of the metric grid.
	const metricPanels = dashboardPanels.filter(
		( entry ) => entry.slot !== 'link'
	);
	const linkPanels = dashboardPanels.filter(
		( entry ) => entry.slot === 'link'
	);
	const headingLinks = metricPanels
		.filter( ( entry ) => isHeadingLink( entry.headingLink ) )
		.map( ( entry ) => ( { ...entry.headingLink, id: entry.id } ) );

	const ordersQuery = useQuery( {
		queryKey: [ 'dashboard', 'latest-orders' ],
		queryFn: () =>
			getList( '/orders', {
				per_page: 5,
				orderby: 'created_at',
				order: 'desc',
			} ),
		enabled: canManage,
	} );

	const salesQuery = useQuery( {
		queryKey: [ 'dashboard', 'sales', 'last-30-days' ],
		// No range args: the server defaults to the last 30 days.
		queryFn: () => get( '/reports/sales' ),
		enabled: canViewReports,
	} );

	const visibleLinks = QUICK_LINKS.filter( ( link ) =>
		hasCapability( caps, link.capability )
	);

	const latestOrders =
		canManage && ! ordersQuery.isError && ordersQuery.data
			? ordersQuery.data.items
			: null;
	const sales = canViewReports && salesQuery.data ? salesQuery.data : null;
	const salesCurrency = sales ? sales.currency || currency : currency;
	const salesPending = firstReadPending( salesQuery );
	const excludedCurrencies =
		sales && sales.excluded && typeof sales.excluded === 'object'
			? Object.keys( sales.excluded )
			: [];

	const welcomeHeading = sprintf(
		/* translators: %s: the store/product name. */
		__( 'Welcome to %s', 'tiermint' ),
		'TierMint'
	);

	return (
		<section className="tiermint-admin-screen tiermint-admin-dashboard">
			<ScreenHeader title={ __( 'Dashboard', 'tiermint' ) } />

			<div className="tiermint-admin-card tiermint-admin-dashboard-welcome">
				<h2>{ welcomeHeading }</h2>
				<p>
					{ __(
						'Here is a quick overview of your store.',
						'tiermint'
					) }
				</p>
			</div>

			{ ( sales || salesPending || metricPanels.length > 0 ) && (
				<section className="tiermint-admin-dashboard-sales">
					<div className="tiermint-admin-dashboard-section-heading">
						<div>
							<h2>{ __( 'Store overview', 'tiermint' ) }</h2>
							{ ( sales || salesPending ) && (
								<p>
									{ __(
										'Sales figures cover the last 30 days.',
										'tiermint'
									) }
								</p>
							) }
						</div>
						{ ( canViewReports || headingLinks.length > 0 ) && (
							<div className="tiermint-admin-dashboard-heading-links">
								{ canViewReports && (
									<a href="#/reports">
										{ __( 'View report', 'tiermint' ) }
									</a>
								) }
								{ headingLinks.map( ( link ) => (
									<a key={ link.id } href={ link.href }>
										{ link.label }
									</a>
								) ) }
							</div>
						) }
					</div>
					{ sales && excludedCurrencies.length > 0 && (
						<p className="tiermint-admin-dashboard-currency-note">
							{ sprintf(
								/* translators: %s: store currency code, for example USD. */
								__(
									'These figures cover %s only. Other currencies are available in Reports.',
									'tiermint'
								),
								salesCurrency
							) }
						</p>
					) }
					<div className="tiermint-admin-dashboard-metrics">
						{ salesPending && (
							// Keep grid cards while exposing the skeleton state.
							<div className="tiermint-skeleton tiermint-skeleton--metrics">
								<span
									className="screen-reader-text"
									role="status"
								>
									{ __(
										'Loading sales figures',
										'tiermint'
									) }
								</span>
								{ Array.from( {
									length: SALES_TILE_COUNT,
								} ).map( ( _value, index ) => (
									<div
										key={ index }
										className="tiermint-skeleton__card tiermint-admin-dashboard-metric--loading"
										aria-hidden="true"
									>
										<div className="tiermint-skeleton__label" />
										<div className="tiermint-skeleton__value" />
									</div>
								) ) }
							</div>
						) }
						{ ! salesPending && sales && sales.totals && (
							<>
								<div className="tiermint-admin-card tiermint-admin-dashboard-metric">
									<span>
										{ __( 'Gross revenue', 'tiermint' ) }
									</span>
									<strong>
										{ formatMinor(
											sales.totals.gross_revenue,
											salesCurrency
										) }
									</strong>
								</div>
								<div className="tiermint-admin-card tiermint-admin-dashboard-metric">
									<span>{ __( 'Refunds', 'tiermint' ) }</span>
									<strong>
										{ formatMinor(
											sales.totals.refund_total,
											salesCurrency
										) }
									</strong>
								</div>
								<div className="tiermint-admin-card tiermint-admin-dashboard-metric">
									<span>
										{ __( 'Net revenue', 'tiermint' ) }
									</span>
									<strong>
										{ formatMinor(
											sales.totals.net_revenue,
											salesCurrency
										) }
									</strong>
								</div>
								<div className="tiermint-admin-card tiermint-admin-dashboard-metric">
									<span>{ __( 'Orders', 'tiermint' ) }</span>
									<strong>
										{ sales.totals.order_count }
									</strong>
								</div>
							</>
						) }
						{ metricPanels.map( ( entry ) => {
							const Panel = entry.component;
							return <Panel key={ entry.id } boot={ boot } />;
						} ) }
					</div>
				</section>
			) }

			<div className="tiermint-admin-dashboard-links">
				{ visibleLinks.map( ( link ) => (
					<div className="tiermint-admin-card" key={ link.path }>
						<h2>
							<a href={ link.path }>{ link.label }</a>
						</h2>
						<p>{ link.description }</p>
					</div>
				) ) }
				{ linkPanels.map( ( entry ) => (
					<div className="tiermint-admin-card" key={ entry.id }>
						<h2>
							<a href={ entry.path }>{ entry.label }</a>
						</h2>
						<p>{ entry.description }</p>
					</div>
				) ) }
			</div>

			{ /* Keep the latest-orders card stable while it loads. */ }
			{ firstReadPending( ordersQuery ) && (
				<div className="tiermint-admin-card tiermint-admin-dashboard-latest-orders">
					<h2>{ __( 'Latest orders', 'tiermint' ) }</h2>
					<SkeletonTable rows={ 5 } columns={ 8 } />
				</div>
			) }

			{ latestOrders && (
				<div className="tiermint-admin-card tiermint-admin-dashboard-latest-orders">
					<h2>{ __( 'Latest orders', 'tiermint' ) }</h2>
					<table className="tiermint-admin-table wp-list-table widefat striped">
						<thead>
							<tr>
								<th>{ __( 'Order', 'tiermint' ) }</th>
								<th>{ __( 'Date', 'tiermint' ) }</th>
								<th>{ __( 'Customer', 'tiermint' ) }</th>
								<th>{ __( 'Product', 'tiermint' ) }</th>
								<th>{ __( 'Status', 'tiermint' ) }</th>
								<th className="tiermint-col--secondary">
									{ __( 'Type', 'tiermint' ) }
								</th>
								<th>{ __( 'Total', 'tiermint' ) }</th>
								<th className="tiermint-col--secondary">
									{ __( 'Gateway', 'tiermint' ) }
								</th>
							</tr>
						</thead>
						<tbody>
							{ latestOrders.map( ( order ) => (
								<LatestOrderRow
									key={ order.id }
									order={ order }
									canEditUsers={ canEditUsers }
								/>
							) ) }
						</tbody>
					</table>
				</div>
			) }
		</section>
	);
}

/**
 * Route registry entry for the dashboard screen.
 *
 * @type {{path: string, title: string, capability: string, component: Function}}
 */
export const route = {
	path: '#/',
	title: __( 'Dashboard', 'tiermint' ),
	capability: 'manage_tiermint',
	component: Dashboard,
};
