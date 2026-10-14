/**
 * Sales report with a date range and paged daily data.
 */

/**
 * External dependencies
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	Notice,
	TextControl,
} from '@wordpress/components';
import { useQuery } from '@tanstack/react-query';

/**
 * Internal dependencies
 */
import { get } from '../api/client';
import { clampPage, useHashQuery } from '../app/useHashQuery';
import Pagination from '../components/Pagination';
import ScreenHeader from '../components/ScreenHeader';
import ExportMenu from '../components/ExportMenu';
import { SkeletonCards } from '../components/Skeleton';
import { formatMinor } from '../utils/currency';
import { firstReadPending } from '../utils/listState';

/**
 * Default totals before report data loads.
 *
 * @type {{gross_revenue: number, refund_total: number, net_revenue: number}}
 */
const EMPTY_TOTALS = { gross_revenue: 0, refund_total: 0, net_revenue: 0 };

/**
 * Daily rows shown per page.
 *
 * @type {number}
 */
const DAYS_PER_PAGE = 31;

/**
 * Render the sales reports screen: a date-range filter, summary cards, and a
 * per-day breakdown table.
 *
 * @param {Object} props      Component props.
 * @param {Object} props.boot The normalized boot object.
 * @return {Element} The reports screen element.
 */
export default function Reports( { boot } ) {
	const { page, params, setPage, setFilters, replacePage } = useHashQuery( {
		from: '',
		to: '',
	} );
	const { from: appliedFrom, to: appliedTo } = params;

	// Fetch only when the admin applies the drafted range.
	const [ draftFrom, setDraftFrom ] = useState( appliedFrom );
	const [ draftTo, setDraftTo ] = useState( appliedTo );

	// Keep fields in sync with Back and Forward navigation.
	useEffect( () => {
		setDraftFrom( appliedFrom );
		setDraftTo( appliedTo );
	}, [ appliedFrom, appliedTo ] );

	const query = useQuery( {
		queryKey: [ 'reports', 'sales', appliedFrom, appliedTo ],
		queryFn: () =>
			get( '/reports/sales', {
				from: appliedFrom || undefined,
				to: appliedTo || undefined,
			} ),
	} );

	// Report currency overrides the boot fallback.
	const currency =
		( query.data && query.data.currency ) ||
		( boot && boot.settings && boot.settings.currency ) ||
		'USD';

	/**
	 * Copy the draft date range into the applied range, triggering a refetch.
	 *
	 * @return {void}
	 */
	function handleApply() {
		setFilters( { from: draftFrom, to: draftTo } );
	}

	const totals = ( query.data && query.data.totals ) || EMPTY_TOTALS;
	const series = ( query.data && query.data.series ) || [];

	const totalPages = Math.ceil( series.length / DAYS_PER_PAGE );
	const reading = firstReadPending( query );

	// Draw a valid page before the URL correction runs.
	const shown = reading ? page : Math.min( page, Math.max( totalPages, 1 ) );
	const days = series.slice(
		( shown - 1 ) * DAYS_PER_PAGE,
		shown * DAYS_PER_PAGE
	);

	useEffect( () => {
		clampPage( {
			page,
			totalPages,
			ready: query.isSuccess,
			onClamp: replacePage,
		} );
	}, [ page, totalPages, query.isSuccess, replacePage ] );

	// Show currencies excluded from store-currency totals.
	const excluded = ( query.data && query.data.excluded ) || {};
	const excludedCodes = Object.keys( excluded );

	// Order exports include customer email and need manager access.
	const canManage = Boolean( boot && boot.caps && boot.caps.manage_tiermint );
	const exports = [
		{
			id: 'sales',
			label: __( 'Sales (CSV)', 'tiermint' ),
			path: '/reports/sales/export',
			params: {
				from: appliedFrom || undefined,
				to: appliedTo || undefined,
			},
			filename: 'tiermint-sales.csv',
		},
		canManage && {
			id: 'orders',
			label: __( 'Orders (CSV)', 'tiermint' ),
			path: '/reports/orders/export',
			params: {
				from: appliedFrom || undefined,
				to: appliedTo || undefined,
			},
			filename: 'tiermint-orders.csv',
		},
	].filter( Boolean );

	return (
		<section className="tiermint-admin-screen tiermint-admin-reports">
			<ScreenHeader title={ __( 'Sales reports', 'tiermint' ) } />

			<div className="tiermint-admin-reports-filters">
				<div className="tiermint-toolbar">
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'From', 'tiermint' ) }
						type="date"
						value={ draftFrom }
						onChange={ setDraftFrom }
					/>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'To', 'tiermint' ) }
						type="date"
						value={ draftTo }
						onChange={ setDraftTo }
					/>
					<Button
						variant="primary"
						__next40pxDefaultSize
						onClick={ handleApply }
					>
						{ __( 'Apply', 'tiermint' ) }
					</Button>
					<ExportMenu items={ exports } />
				</div>
				{ /* This help applies to both date fields. */ }
				<p className="tiermint-toolbar__help">
					{ __(
						'Dates are UTC calendar days. Leave both fields blank for the last 30 days.',
						'tiermint'
					) }
				</p>
			</div>

			{ /* Show summary-card placeholders while the report loads. */ }
			{ reading && <SkeletonCards cards={ 3 } /> }
			{ query.isError && (
				<Notice status="error" isDismissible={ false }>
					{ query.error.message }
				</Notice>
			) }

			{ ! reading && ! query.isError && (
				<>
					{ excludedCodes.length > 0 && (
						<Notice status="warning" isDismissible={ false }>
							<p>
								{ __(
									'These totals cover the store currency only. Orders and refunds taken in other currencies are reported separately below.',
									'tiermint'
								) }
							</p>
							<ul className="tiermint-admin-reports-excluded">
								{ Object.entries( excluded ).map(
									( [ code, amounts ] ) => (
										<li key={ code }>
											{ sprintf(
												/* translators: 1: currency code. 2: gross amount in that currency. 3: refunded amount in that currency. */
												__(
													'%1$s: %2$s gross, %3$s refunded',
													'tiermint'
												),
												code,
												formatMinor(
													amounts.gross_revenue,
													code
												),
												formatMinor(
													amounts.refund_total,
													code
												)
											) }
										</li>
									)
								) }
							</ul>
						</Notice>
					) }

					<div className="tiermint-admin-reports-summary">
						<Card>
							<CardBody>
								<h2>{ __( 'Gross', 'tiermint' ) }</h2>
								<p>
									{ formatMinor(
										totals.gross_revenue,
										currency
									) }
								</p>
							</CardBody>
						</Card>
						<Card>
							<CardBody>
								<h2>{ __( 'Refunds', 'tiermint' ) }</h2>
								<p>
									{ formatMinor(
										totals.refund_total,
										currency
									) }
								</p>
							</CardBody>
						</Card>
						<Card>
							<CardBody>
								<h2>{ __( 'Net', 'tiermint' ) }</h2>
								<p>
									{ formatMinor(
										totals.net_revenue,
										currency
									) }
								</p>
							</CardBody>
						</Card>
					</div>

					<table className="tiermint-admin-table wp-list-table widefat striped">
						<thead>
							<tr>
								<th>{ __( 'Date', 'tiermint' ) }</th>
								<th>{ __( 'Orders', 'tiermint' ) }</th>
								<th>{ __( 'Refunds', 'tiermint' ) }</th>
								<th>{ __( 'Gross', 'tiermint' ) }</th>
								<th>{ __( 'Refund total', 'tiermint' ) }</th>
								<th>{ __( 'Net', 'tiermint' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ days.map( ( day ) => (
								<tr key={ day.date }>
									<td>{ day.date }</td>
									<td>{ day.order_count }</td>
									<td>{ day.refund_count }</td>
									<td>
										{ formatMinor(
											day.gross_revenue,
											currency
										) }
									</td>
									<td>
										{ formatMinor(
											day.refund_total,
											currency
										) }
									</td>
									<td>
										{ formatMinor(
											day.net_revenue,
											currency
										) }
									</td>
								</tr>
							) ) }
							{ series.length === 0 && (
								<tr>
									<td colSpan={ 6 }>
										{ __(
											'No sales in this period.',
											'tiermint'
										) }
									</td>
								</tr>
							) }
						</tbody>
					</table>
				</>
			) }

			{ /* Keep the pager during a range change, not after an error. */ }
			{ ! query.isError && (
				<Pagination
					page={ shown }
					totalPages={ totalPages }
					pending={ reading }
					onChange={ setPage }
				/>
			) }
		</section>
	);
}

/**
 * Route registry entry for the reports screen.
 *
 * @type {{path: string, title: string, capability: string, component: Function}}
 */
export const route = {
	path: '#/reports',
	title: __( 'Reports', 'tiermint' ),
	capability: 'view_tiermint_reports',
	component: Reports,
};
