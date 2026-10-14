<?php
/**
 * Handlers bridging verified Stripe webhook events to the Orders domain.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Payments\Stripe;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Orders\Order;
use DrPlugin\WP\TierMint\Orders\OrderService;
use DrPlugin\WP\TierMint\Orders\OrderStatus;
use DrPlugin\WP\TierMint\Payments\GatewayEvent;
use DrPlugin\WP\TierMint\Payments\RefundCoordinator;

/**
 * Handles Stripe payment and refund events for local orders.
 * Foreign events are ignored so Stripe does not retry them.
 *
 * @since 1.0.0
 */
class StripeEventHandlers {

	/**
	 * Wire the handlers with the order service and the refund coordinator.
	 *
	 * @since 1.0.0
	 *
	 * @param OrderService      $orders  The orders domain service.
	 * @param RefundCoordinator $refunds The gateway/ledger refund coordinator.
	 */
	public function __construct(
		private readonly OrderService $orders,
		private readonly RefundCoordinator $refunds
	) {
	}

	/**
	 * The event-type dispatch map the WebhookProcessor consumes for the 'stripe'
	 * gateway.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, callable(GatewayEvent): bool>
	 */
	public function handlers(): array {
		return array(
			'payment_intent.succeeded'      => array( $this, 'onPaymentIntentSucceeded' ),
			'payment_intent.payment_failed' => array( $this, 'onPaymentIntentFailed' ),
			'charge.refunded'               => array( $this, 'onChargeRefunded' ),
		);
	}

	/**
	 * Complete the paid order and restore a missing gateway reference.
	 *
	 * @since 1.0.0
	 *
	 * @param GatewayEvent $event The verified payment_intent.succeeded event.
	 * @return bool True when the order was completed (or already was), false when
	 *              the event is not addressed to this install.
	 */
	public function onPaymentIntentSucceeded( GatewayEvent $event ): bool {
		$intent = $event->object();
		$order  = $this->resolveOrder( $intent );

		if ( null === $order || null === $order->id() ) {
			return false;
		}

		if ( $order->isCompleted() ) {
			return true;
		}

		$intentId = trim( (string) ( $intent['id'] ?? '' ) );

		if ( null === $order->gatewayTxnId() && '' !== $intentId ) {
			$order = $this->orders->attachGateway( (int) $order->id(), 'stripe', $intentId );
		}

		$this->warnOnAmountMismatch( $order, $intent );

		$this->orders->markCompleted( (int) $order->id() );

		return true;
	}

	/**
	 * Log a paid intent that differs from its order without blocking fulfilment.
	 *
	 * @since 1.0.0
	 *
	 * @param Order                $order  The resolved order.
	 * @param array<string, mixed> $intent The PaymentIntent payload.
	 * @return void
	 */
	private function warnOnAmountMismatch( Order $order, array $intent ): void {
		$amount   = isset( $intent['amount'] ) ? (int) $intent['amount'] : null;
		$currency = isset( $intent['currency'] ) ? strtoupper( trim( (string) $intent['currency'] ) ) : '';

		$amountMismatch   = null !== $amount && $amount !== $order->total();
		$currencyMismatch = '' !== $currency && $currency !== $order->currency();

		if ( ! $amountMismatch && ! $currencyMismatch ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Deliberate anomaly log: a signed PaymentIntent that does not match its order is rare and worth surfacing; completion still proceeds so a paid customer is never blocked.
		error_log(
			sprintf(
				'TierMint: PaymentIntent %s reports %s %s but order %d is %d %s; completing anyway.',
				trim( (string) ( $intent['id'] ?? '' ) ),
				null === $amount ? 'an unknown amount' : (string) $amount,
				'' === $currency ? 'in an unknown currency' : $currency,
				(int) $order->id(),
				$order->total(),
				$order->currency()
			)
		);
	}

	/**
	 * Mark a pending payment as failed without changing a completed order.
	 *
	 * @since 1.0.0
	 *
	 * @param GatewayEvent $event The verified payment_intent.payment_failed event.
	 * @return bool True when handled (including the no-op cases), false when the
	 *              event is not addressed to this install.
	 */
	public function onPaymentIntentFailed( GatewayEvent $event ): bool {
		$intent = $event->object();
		$order  = $this->resolveOrder( $intent );

		if ( null === $order || null === $order->id() ) {
			return false;
		}

		if ( ! in_array( $order->status(), array( OrderStatus::Pending, OrderStatus::Failed ), true ) ) {
			return true;
		}

		$reason = trim( (string) ( $intent['last_payment_error']['message'] ?? '' ) );

		$this->orders->markFailed( (int) $order->id(), '' !== $reason ? $reason : 'payment_failed' );

		return true;
	}

	/**
	 * Reconcile a Stripe refund with its local order.
	 *
	 * @since 1.0.0
	 *
	 * @param GatewayEvent $event The verified charge.refunded event.
	 * @return bool True when reconciled, false when the charge does not resolve to
	 *              an order of this install.
	 */
	public function onChargeRefunded( GatewayEvent $event ): bool {
		$charge   = $event->object();
		$intentId = trim( (string) ( $charge['payment_intent'] ?? '' ) );

		if ( '' === $intentId ) {
			return false;
		}

		$order = $this->orders->getByGatewayTxnId( 'stripe', $intentId );

		if ( null === $order || null === $order->id() ) {
			return false;
		}

		return $this->refunds->reconcile( $order, $charge );
	}

	/**
	 * Resolve an order only when the Stripe metadata belongs to this site.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $intent The PaymentIntent payload (data.object).
	 * @return Order|null The order, or null when the intent is not addressed here.
	 */
	private function resolveOrder( array $intent ): ?Order {
		$metadata = $intent['metadata'] ?? array();
		$metadata = is_array( $metadata ) ? $metadata : array();

		$orderId = (int) ( $metadata['tiermint_order_id'] ?? 0 );
		$uuid    = trim( (string) ( $metadata['tiermint_order_uuid'] ?? '' ) );

		if ( $orderId <= 0 && '' === $uuid ) {
			return null;
		}

		$order = $orderId > 0 ? $this->orders->get( $orderId ) : null;

		if ( null === $order && '' !== $uuid ) {
			$order = $this->orders->getByUuid( $uuid );
		}

		if ( null === $order ) {
			return null;
		}

		if ( '' !== $uuid && $order->uuid() !== $uuid ) {
			return null;
		}

		return $order;
	}
}
