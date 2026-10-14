/**
 * Order detail screen (`#/orders/:id`).
 *
 * Shows one order and its guarded refund flow.
 */

/**
 * External dependencies
 */
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Modal, Notice, TextControl } from '@wordpress/components';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';

/**
 * Internal dependencies
 */
import { get, post } from '../api/client';
import { useOrderDetailActions } from '../app/orderDetailActions';
import ScreenHeader from '../components/ScreenHeader';
import { SkeletonForm } from '../components/Skeleton';
import StatusBadge from '../components/StatusBadge';
import { joinList } from '../utils/list';
import { firstReadPending } from '../utils/listState';
import { formatMinor, decimalToMinor, minorToDecimal } from '../utils/currency';
import { orderStatusLabel } from '../utils/orderStatus';

/**
 * Order-list link for every screen state.
 *
 * @type {{href: string, label: string}}
 */
const BACK_TO_ORDERS = {
	href: '#/orders',
	label: __( 'Back to orders', 'tiermint' ),
};

/**
 * Creates an idempotency key for one refund submission.
 *
 * @return {string} A fresh, unique idempotency key.
 */
function makeIdempotencyKey() {
	const webCrypto = typeof window !== 'undefined' ? window.crypto : undefined;
	if ( webCrypto && typeof webCrypto.randomUUID === 'function' ) {
		return webCrypto.randomUUID();
	}

	return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(
		/[xy]/g,
		( char ) => {
			const rand = Math.floor( Math.random() * 16 );
			const value = char === 'x' ? rand : ( rand % 4 ) + 8;
			return value.toString( 16 );
		}
	);
}

/**
 * Flattens a WordPress `data.errors` map.
 *
 * @param {?Object} errors The per-field error map, if any.
 * @return {string[]} The collected messages (possibly empty).
 */
function collectFieldMessages( errors ) {
	if ( ! errors || typeof errors !== 'object' ) {
		return [];
	}

	const messages = [];
	Object.keys( errors ).forEach( ( key ) => {
		const value = errors[ key ];
		if ( Array.isArray( value ) ) {
			value.forEach( ( entry ) => messages.push( String( entry ) ) );
		} else {
			messages.push( String( value ) );
		}
	} );
	return messages;
}

/**
 * Maps a refund error to user-facing messages.
 *
 * @param {?Object} error The normalized error object.
 * @return {string[]} One or more messages to display.
 */
function refundErrorMessages( error ) {
	if ( ! error ) {
		return [];
	}

	const generic = __( 'The refund could not be processed.', 'tiermint' );

	switch ( error.code ) {
		case 'tiermint_invalid_params': {
			const fields = collectFieldMessages( error.errors );
			return fields.length > 0 ? fields : [ error.message || generic ];
		}
		case 'tiermint_refund_in_progress':
			return [
				__(
					'Another refund for this order is still being recorded. Wait a moment and try again.',
					'tiermint'
				),
			];
		case 'tiermint_gateway_not_configured':
			return [
				__(
					'Nothing was charged or refunded: the payment gateway is not configured.',
					'tiermint'
				),
			];
		case 'tiermint_gateway_error':
			return [
				__(
					"The payment gateway did not confirm this refund, so the money may or may not have moved. Do not retry it. The store reconciles the refund automatically when the gateway's webhook arrives.",
					'tiermint'
				),
			];
		default:
			return [ error.message || generic ];
	}
}

/**
 * Human label for an order type, falling back to the raw value.
 *
 * @param {string} type The order type slug.
 * @return {string} A display label.
 */
function typeLabel( type ) {
	const map = {
		purchase: __( 'Purchase', 'tiermint' ),
		renewal: __( 'Renewal', 'tiermint' ),
		upgrade: __( 'Upgrade', 'tiermint' ),
		refund: __( 'Refund', 'tiermint' ),
	};
	return map[ type ] || type;
}

/**
 * Format an ISO timestamp as a locale date-time, leaving an empty string for a
 * missing value.
 *
 * @param {?string} iso The ISO 8601 timestamp, or a falsy value.
 * @return {string} A locale string, or an empty string.
 */
function formatDateTime( iso ) {
	if ( ! iso ) {
		return '';
	}
	const date = new Date( iso );
	return Number.isNaN( date.getTime() ) ? iso : date.toLocaleString();
}

/**
 * Join a stored billing address into one line, dropping the parts the buyer
 * left blank.
 *
 * @param {?Object} address The billing address record, or a falsy value.
 * @return {string} The joined address, or an empty string.
 */
function formatBillingAddress( address ) {
	if ( ! address || 'object' !== typeof address ) {
		return '';
	}

	const parts = [
		address.address_1,
		address.address_2,
		address.city,
		address.state,
		address.postcode,
	];

	return joinList(
		parts.filter(
			( part ) => 'string' === typeof part && '' !== part.trim()
		)
	);
}

/**
 * The refund modal: amount entry, an explicit confirmation that restates the
 * amount, and the guarded submission.
 *
 * @param {Object}   props            Component props.
 * @param {Object}   props.order      The order being refunded (currency, total).
 * @param {string}   props.orderId    The order id used in the REST path and cache key.
 * @param {Function} props.onClose    Called to dismiss the modal without refunding.
 * @param {Function} props.onRefunded Called with the updated order after success.
 * @return {Element} The modal element.
 */
function RefundModal( { order, orderId, onClose, onRefunded } ) {
	const [ phase, setPhase ] = useState( 'form' );
	const [ amountInput, setAmountInput ] = useState(
		minorToDecimal( order.total, order.currency )
	);

	const refund = useMutation( {
		/**
		 * Send the refund with a caller-provided amount and idempotency key.
		 *
		 * @param {{amount: number, idempotencyKey: string}} variables The refund inputs.
		 * @return {Promise<Object>} The updated order returned by the endpoint.
		 */
		mutationFn: ( variables ) =>
			post( `/orders/${ orderId }/refund`, {
				amount: variables.amount,
				idempotency_key: variables.idempotencyKey,
			} ),
		/**
		 * Hand the updated order back to the parent on success.
		 *
		 * @param {Object} updated The updated order from the endpoint.
		 * @return {void}
		 */
		onSuccess: ( updated ) => onRefunded( updated ),
	} );

	// decimalToMinor is zero-decimal aware and returns an integer (0 for an
	// unparseable input), so the validity gate reduces to a minimum of 1 minor
	// unit; an empty or garbage amount yields 0 and stays invalid.
	const minorUnits = decimalToMinor( amountInput, order.currency );
	const amountValid = Number.isFinite( minorUnits ) && minorUnits >= 1;

	// After an uncertain gateway error the outcome is unknown, so block any
	// further attempt from this modal: the store reconciles via the webhook.
	const gatewayUncertain =
		refund.isError &&
		!! refund.error &&
		refund.error.code === 'tiermint_gateway_error';

	/**
	 * Submit the refund, minting a brand-new idempotency key for this click.
	 *
	 * @return {void}
	 */
	const submit = () => {
		refund.mutate( {
			amount: minorUnits,
			idempotencyKey: makeIdempotencyKey(),
		} );
	};

	return (
		<Modal
			className="tiermint-modal tiermint-modal--confirm"
			title={ __( 'Refund order', 'tiermint' ) }
			onRequestClose={ onClose }
		>
			{ phase === 'form' && (
				<div className="tiermint-refund-form">
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						type="number"
						label={ __( 'Refund amount', 'tiermint' ) }
						value={ amountInput }
						onChange={ setAmountInput }
						help={ sprintf(
							/* translators: %s: ISO 4217 currency code. */
							__( 'Amount to refund, in %s.', 'tiermint' ),
							order.currency
						) }
					/>
					<div className="tiermint-modal-actions">
						<Button variant="tertiary" onClick={ onClose }>
							{ __( 'Cancel', 'tiermint' ) }
						</Button>
						<Button
							variant="primary"
							disabled={ ! amountValid }
							onClick={ () => setPhase( 'confirm' ) }
						>
							{ __( 'Review refund', 'tiermint' ) }
						</Button>
					</div>
				</div>
			) }

			{ phase === 'confirm' && (
				<div className="tiermint-refund-confirm">
					<p className="tiermint-refund-restate">
						{ sprintf(
							/* translators: 1: formatted amount, 2: ISO 4217 currency code. */
							__(
								'You are about to refund %1$s (%2$s) to the customer. This cannot be undone.',
								'tiermint'
							),
							formatMinor( minorUnits, order.currency ),
							order.currency
						) }
					</p>

					{ refund.isError && (
						<Notice status="error" isDismissible={ false }>
							{ refundErrorMessages( refund.error ).map(
								( message ) => (
									<p key={ message }>{ message }</p>
								)
							) }
						</Notice>
					) }

					<div className="tiermint-modal-actions">
						<Button
							variant="tertiary"
							disabled={ refund.isPending }
							onClick={ () => setPhase( 'form' ) }
						>
							{ __( 'Back', 'tiermint' ) }
						</Button>
						<Button
							variant="primary"
							isBusy={ refund.isPending }
							disabled={ refund.isPending || gatewayUncertain }
							onClick={ submit }
						>
							{ __( 'Confirm refund', 'tiermint' ) }
						</Button>
					</div>
				</div>
			) }
		</Modal>
	);
}

/**
 * Render the order detail screen.
 *
 * @param {Object} props        Component props.
 * @param {Object} props.params The matched route params; `params.id` is the order id.
 * @param {Object} [props.boot] The normalized boot object; its `caps` gate the
 *                              extension actions.
 * @return {Element} The order detail screen element.
 */
export default function OrderDetail( { params, boot } ) {
	const orderId = params.id;
	const queryClient = useQueryClient();
	const [ modalOpen, setModalOpen ] = useState( false );

	// Extension-contributed header actions (Pro's invoice buttons). Filtered by
	// capability here; each renders as `<Action order={ order } />` below.
	const extensionActions = useOrderDetailActions( boot?.caps );

	const query = useQuery( {
		queryKey: [ 'order', orderId ],
		queryFn: () => get( `/orders/${ orderId }` ),
	} );

	/**
	 * Updates the refund result, invalidates related lists and closes the modal.
	 *
	 * @param {Object} updated The updated order from the refund response.
	 * @return {void}
	 */
	const handleRefunded = ( updated ) => {
		queryClient.setQueryData( [ 'order', orderId ], updated );
		[
			[ 'order', orderId ],
			[ 'orders' ],
			[ 'dashboard' ],
			[ 'license' ],
			[ 'licenses' ],
			[ 'reports' ],
		].forEach( ( queryKey ) =>
			queryClient.invalidateQueries( { queryKey } )
		);
		setModalOpen( false );
	};

	// Match the shared first-read gate and guard missing query data.
	if ( firstReadPending( query ) || ( ! query.isError && ! query.data ) ) {
		return (
			<section className="tiermint-admin-screen">
				<ScreenHeader
					title={ __( 'Order', 'tiermint' ) }
					backLink={ BACK_TO_ORDERS }
				/>
				<SkeletonForm fields={ 5 } />
			</section>
		);
	}

	// A header here too, for its back link: a stale link to a deleted order
	// would otherwise dead-end on a notice with nothing to click.
	if ( query.isError ) {
		return (
			<section className="tiermint-admin-screen">
				<ScreenHeader
					title={ __( 'Order', 'tiermint' ) }
					backLink={ BACK_TO_ORDERS }
				/>
				<Notice status="error" isDismissible={ false }>
					{ query.error && query.error.message
						? query.error.message
						: __( 'The order could not be loaded.', 'tiermint' ) }
				</Notice>
			</section>
		);
	}

	const order = query.data;
	const items = Array.isArray( order.items ) ? order.items : [];
	const canRefund =
		order.status === 'completed' || order.status === 'partially_refunded';

	return (
		<section className="tiermint-admin-screen tiermint-order-detail">
			<ScreenHeader
				title={ sprintf(
					/* translators: %d: the order id. */
					__( 'Order #%d', 'tiermint' ),
					order.id
				) }
				backLink={ BACK_TO_ORDERS }
				actions={
					canRefund || extensionActions.length > 0 ? (
						<>
							{ canRefund && (
								<Button
									variant="primary"
									__next40pxDefaultSize
									onClick={ () => setModalOpen( true ) }
								>
									{ __( 'Refund', 'tiermint' ) }
								</Button>
							) }
							{ extensionActions.map(
								( { id, component: Action } ) => (
									<Action key={ id } order={ order } />
								)
							) }
						</>
					) : undefined
				}
			/>

			{ /* Keep order facts below the header's side-by-side layout. */ }
			<div className="tiermint-panel">
				<dl className="tiermint-order-meta">
					<div>
						<dt>{ __( 'Reference', 'tiermint' ) }</dt>
						<dd>{ order.uuid }</dd>
					</div>
					<div>
						<dt>{ __( 'Status', 'tiermint' ) }</dt>
						<dd>
							<StatusBadge
								status={ order.status }
								label={ orderStatusLabel( order.status ) }
								className="tiermint-status"
							/>
						</dd>
					</div>
					<div>
						<dt>{ __( 'Type', 'tiermint' ) }</dt>
						<dd>{ typeLabel( order.type ) }</dd>
					</div>
					<div>
						{ /* Plain text: there is no per-customer filter view to
						   deep-link to yet, so a link would mislead. */ }
						<dt>{ __( 'Customer', 'tiermint' ) }</dt>
						<dd>{ order.email }</dd>
					</div>
					<div>
						<dt>{ __( 'Gateway', 'tiermint' ) }</dt>
						<dd>{ order.gateway }</dd>
					</div>
					{ order.gateway_txn_id && (
						<div>
							<dt>{ __( 'Transaction', 'tiermint' ) }</dt>
							<dd>{ order.gateway_txn_id }</dd>
						</div>
					) }
					{ order.discount_code && (
						<div>
							<dt>{ __( 'Discount code', 'tiermint' ) }</dt>
							<dd>{ order.discount_code }</dd>
						</div>
					) }
					<div>
						<dt>{ __( 'Created', 'tiermint' ) }</dt>
						<dd>{ formatDateTime( order.created_at ) }</dd>
					</div>
					<div>
						<dt>{ __( 'Completed', 'tiermint' ) }</dt>
						<dd>{ formatDateTime( order.completed_at ) }</dd>
					</div>
					{ order.country && (
						<div>
							<dt>{ __( 'Billing country', 'tiermint' ) }</dt>
							<dd>{ order.country }</dd>
						</div>
					) }
					{ order.terms_accepted_at && (
						<div>
							<dt>{ __( 'Terms accepted', 'tiermint' ) }</dt>
							<dd>
								{ formatDateTime( order.terms_accepted_at ) }
							</dd>
						</div>
					) }
					{ order.billing_address && (
						<div>
							<dt>{ __( 'Billing address', 'tiermint' ) }</dt>
							<dd>
								{ formatBillingAddress(
									order.billing_address
								) }
							</dd>
						</div>
					) }
				</dl>
			</div>

			<h2>{ __( 'Items', 'tiermint' ) }</h2>

			<table className="tiermint-order-items wp-list-table widefat striped">
				<thead>
					<tr>
						<th>{ __( 'Product', 'tiermint' ) }</th>
						<th>{ __( 'Price', 'tiermint' ) }</th>
						<th>{ __( 'Qty', 'tiermint' ) }</th>
						<th>{ __( 'Subtotal', 'tiermint' ) }</th>
						<th>{ __( 'Discount', 'tiermint' ) }</th>
						<th>{ __( 'Tax', 'tiermint' ) }</th>
						<th>{ __( 'Total', 'tiermint' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ items.map( ( item, index ) => (
						<tr key={ `${ item.product_name }-${ index }` }>
							<td>{ item.product_name }</td>
							<td>{ item.price_name }</td>
							<td>{ item.quantity }</td>
							<td>
								{ formatMinor( item.subtotal, order.currency ) }
							</td>
							<td>
								{ formatMinor( item.discount, order.currency ) }
							</td>
							<td>{ formatMinor( item.tax, order.currency ) }</td>
							<td>
								{ formatMinor( item.total, order.currency ) }
							</td>
						</tr>
					) ) }
				</tbody>
			</table>

			<div className="tiermint-panel tiermint-order-totals-panel">
				<dl className="tiermint-order-totals">
					<div>
						<dt>{ __( 'Subtotal', 'tiermint' ) }</dt>
						<dd>
							{ formatMinor( order.subtotal, order.currency ) }
						</dd>
					</div>
					<div>
						<dt>{ __( 'Discount', 'tiermint' ) }</dt>
						<dd>
							{ formatMinor(
								order.discount_total,
								order.currency
							) }
						</dd>
					</div>
					<div>
						<dt>{ __( 'Tax', 'tiermint' ) }</dt>
						<dd>
							{ formatMinor( order.tax_total, order.currency ) }
						</dd>
					</div>
					<div>
						<dt>{ __( 'Total', 'tiermint' ) }</dt>
						<dd>{ formatMinor( order.total, order.currency ) }</dd>
					</div>
				</dl>
			</div>

			{ modalOpen && (
				<RefundModal
					order={ order }
					orderId={ orderId }
					onClose={ () => setModalOpen( false ) }
					onRefunded={ handleRefunded }
				/>
			) }
		</section>
	);
}

/**
 * Route registry entry for the order detail screen.
 *
 * @type {{path: string, title: string, capability: string, component: Function}}
 */
export const route = {
	path: '#/orders/:id',
	title: __( 'Order detail', 'tiermint' ),
	capability: 'manage_tiermint',
	component: OrderDetail,
};
