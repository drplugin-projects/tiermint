<?php
/**
 * Checkout payment service.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Payments;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Orders\Order;
use DrPlugin\WP\TierMint\Orders\OrderService;

/**
 * Creates a pending order and gateway payment session.
 *
 * Uses server pricing. Webhooks complete orders.
 *
 * @since 1.0.0
 */
class CheckoutService {

	/**
	 * Wire the service with the orders domain and the gateway registry.
	 *
	 * @since 1.0.0
	 *
	 * @param OrderService    $orders   The orders domain service.
	 * @param GatewayRegistry $gateways The gateway registry.
	 */
	public function __construct(
		private readonly OrderService $orders,
		private readonly GatewayRegistry $gateways
	) {
	}

	/**
	 * Create an order and payment session.
	 *
	 * Check configuration first. Fail the order on gateway errors.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $gatewayId The gateway to pay through, e.g. 'stripe'.
	 * @param array<string, mixed> $buyer     Buyer identity for CustomerService::findOrCreate
	 *                                        (`email`, optional `user_id`, `name`).
	 * @param array<string, mixed> $context   Optional request context (`ip_address`, `country`).
	 * @return array{order: Order, session: PaymentSession, notices: array<int, string>} The
	 *               stamped pending order, the session, and the buyer-facing advisories the
	 *               charge-time seam produced (empty when nothing is hooked). The notices
	 *               travel with the result because the REST controller sees only this array,
	 *               and without them a total a tax extension moved is indistinguishable from
	 *               a catalog price change. GatewayNotFoundException propagates from the
	 *               registry for an unknown id, and OrderValidationException from
	 *               OrderService for an empty or unpurchasable cart.
	 *
	 * @throws GatewayConfigurationException When the gateway is not configured (thrown
	 *                                       before the cart is consumed).
	 * @throws PaymentException              When the gateway call fails after the order
	 *                                       was created (the order is marked failed first,
	 *                                       then the gateway exception is rethrown).
	 */
	public function createIntent( string $gatewayId, array $buyer, array $context = array() ): array {
		$gateway = $this->gateways->get( $gatewayId );

		if ( ! $gateway->isConfigured() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new GatewayConfigurationException( __( 'This payment method is not available right now.', 'tiermint' ) );
		}

		$notices   = array();
		$breakdown = array();
		$order     = $this->orders->createFromCart( $buyer, $context, $notices, $breakdown );

		try {
			$session = $gateway->createPayment( $order );
		} catch ( PaymentException $e ) {
			// Keep the gateway reason for failure listeners.
			$this->orders->markFailed( (int) $order->id(), 'gateway_error: ' . $e->getMessage() );

			throw $e;
		}

		$order = $this->orders->attachGateway( (int) $order->id(), $gateway->id(), $session->transactionId() );

		return array(
			'order'    => $order,
			'session'  => $session,
			'notices'  => $notices,
			// Return applied discount components for the checkout summary.
			'discount' => $breakdown,
		);
	}

	/**
	 * Price the current cart without creating an order or payment session.
	 *
	 * Only {@see createIntent()} decides the charged total.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $context Optional request context (`ip_address`,
	 *                                       `country`, `discount_code`, plus extension keys).
	 * @return array<string, mixed> The priced quote. OrderValidationException propagates
	 *               from OrderService for an empty or unpurchasable cart.
	 */
	public function quote( array $context = array() ): array {
		return $this->orders->quote( $context );
	}

	/**
	 * Find a checkout order by its public UUID.
	 *
	 * @since 1.0.0
	 *
	 * @param string $uuid The order uuid from the checkout session.
	 * @return Order|null The order, or null when unknown.
	 */
	public function status( string $uuid ): ?Order {
		return $this->orders->getByUuid( $uuid );
	}
}
