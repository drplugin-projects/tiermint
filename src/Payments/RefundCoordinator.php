<?php
/**
 * Coordinates gateway refunds and the local ledger.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Payments;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Orders\Order;
use DrPlugin\WP\TierMint\Orders\OrderNotFoundException;
use DrPlugin\WP\TierMint\Orders\OrderRepository;
use DrPlugin\WP\TierMint\Orders\OrderValidationException;
use DrPlugin\WP\TierMint\Orders\RefundService;

/**
 * Coordinates gateway refunds and the local ledger.
 *
 * @since 1.0.0
 */
class RefundCoordinator {

	/**
	 * Wire the refund dependencies.
	 *
	 * @since 1.0.0
	 *
	 * @param OrderRepository $orders   The orders repository (refund sums and txn-id lookups).
	 * @param RefundService   $refunds  The local refund ledger service.
	 * @param GatewayRegistry $gateways The gateway registry.
	 * @param RefundLock      $lock     The per-order refund serialization lock.
	 */
	public function __construct(
		private readonly OrderRepository $orders,
		private readonly RefundService $refunds,
		private readonly GatewayRegistry $gateways,
		private readonly RefundLock $lock
	) {
	}

	/**
	 * Refund an order through its gateway, then record the local refund.
	 *
	 * Validate before gateway calls. Manual orders use the local ledger.
	 *
	 * @since 1.0.0
	 *
	 * @param int                  $orderId The purchase order to refund.
	 * @param int                  $amount  The amount to refund, in minor units (positive).
	 * @param array<string, mixed> $context Optional request context (`ip_address`, and an
	 *                                       optional `idempotency_key` to override the default).
	 * @return Order The reloaded purchase order in its new refund status. Validation
	 *               failures (missing order, non-refundable order, bad amount) propagate
	 *               from RefundService::assertRefundable(); gateway failures
	 *               (GatewayConfigurationException, GatewayRequestException) propagate from
	 *               the gateway call, in which case nothing was recorded locally; a lock
	 *               timeout surfaces as RefundLockException.
	 *
	 * @throws OrderNotFoundException When the purchase vanishes between the gateway push and the reload.
	 * @throws RefundLockException    When another refund for the same order holds the lock.
	 */
	public function refund( int $orderId, int $amount, array $context = array() ): Order {
		return $this->lock->withOrderLock(
			$orderId,
			fn (): Order => $this->doRefund( $orderId, $amount, $context )
		);
	}

	/**
	 * The refund body, run while holding the per-order lock (see {@see refund()}).
	 *
	 * @since 1.0.0
	 *
	 * @param int                  $orderId The purchase order to refund.
	 * @param int                  $amount  The amount to refund, in minor units (positive).
	 * @param array<string, mixed> $context Optional request context.
	 * @return Order The reloaded purchase order in its new refund status.
	 *
	 * @throws OrderNotFoundException When the purchase vanishes between the gateway push and the reload.
	 */
	private function doRefund( int $orderId, int $amount, array $context ): Order {
		$order = $this->refunds->assertRefundable( $orderId, $amount );

		$gatewayId = $order->gateway();

		if ( null === $gatewayId || null === $order->gatewayTxnId() || ! $this->gateways->has( $gatewayId ) ) {
			return $this->refunds->refund( $orderId, $amount, $context );
		}

		$gateway = $this->gateways->get( $gatewayId );

		if ( ! $gateway->supports( 'refunds' ) ) {
			return $this->refunds->refund( $orderId, $amount, $context );
		}

		$result = $gateway->refund( $order, $amount, $this->idempotencyKey( $orderId, $amount, $context ) );

		// Skip a refund already recorded by its gateway id.
		if ( null !== $this->orders->findByGatewayTxnId( $gatewayId, $result->transactionId() ) ) {
			return $this->reloadOrFail( $orderId );
		}

		// A default-key retry can duplicate an id-less webhook refund.
		if ( '' === $this->suppliedKey( $context ) && $this->orders->hasUnattributedRefundOfAmount( $orderId, $amount ) ) {
			return $this->reloadOrFail( $orderId );
		}

		return $this->refunds->refund(
			$orderId,
			$amount,
			array_merge( $context, array( 'gateway_txn_id' => $result->transactionId() ) )
		);
	}

	/**
	 * Record gateway refunds missing from the local ledger.
	 *
	 * Record itemized refunds, then any remaining gateway total.
	 *
	 * @since 1.0.0
	 *
	 * @param Order                $order  The purchase order the charge belongs to.
	 * @param array<string, mixed> $charge The Stripe charge payload (amount_refunded, refunds.data).
	 * @return bool True: the event was addressed to this install and is now reconciled.
	 *
	 * @throws \DrPlugin\WP\TierMint\Orders\OrderPersistenceException When a ledger write fails
	 *         (the processor marks the event failed so the gateway retries).
	 * @throws RefundLockException When another refund for the same order holds the lock.
	 */
	public function reconcile( Order $order, array $charge ): bool {
		return $this->lock->withOrderLock(
			(int) $order->id(),
			fn (): bool => $this->doReconcile( $order, $charge )
		);
	}

	/**
	 * The reconciliation body, run while holding the per-order lock (see {@see reconcile()}).
	 *
	 * @since 1.0.0
	 *
	 * @param Order                $order  The purchase order the charge belongs to.
	 * @param array<string, mixed> $charge The Stripe charge payload.
	 * @return bool Always true once reconciled.
	 */
	private function doReconcile( Order $order, array $charge ): bool {
		$orderId  = (int) $order->id();
		$recorded = $this->orders->sumRefundedForOrder( $orderId );
		$reported = (int) ( $charge['amount_refunded'] ?? 0 );

		if ( $reported <= $recorded ) {
			return true;
		}

		$entries     = $charge['refunds']['data'] ?? null;
		$newlyBooked = 0;
		$gatewayId   = (string) $order->gateway();

		if ( is_array( $entries ) ) {
			foreach ( $entries as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}

				$refundId = trim( (string) ( $entry['id'] ?? '' ) );
				$amount   = (int) ( $entry['amount'] ?? 0 );

				if ( '' === $refundId || $amount <= 0 ) {
					continue;
				}

				if ( null !== $this->orders->findByGatewayTxnId( $gatewayId, $refundId ) ) {
					continue;
				}

				// Do not exceed the gateway's refunded total.
				if ( $recorded + $newlyBooked + $amount > $reported ) {
					continue;
				}

				if ( $this->recordEntry( $orderId, $amount, $refundId ) ) {
					$newlyBooked += $amount;
				}
			}
		}

		// Record an id-less row for any remaining gateway total.
		$shortfall = $reported - $recorded - $newlyBooked;

		if ( $shortfall > 0 ) {
			$this->recordEntry( $orderId, $shortfall, null );
		}

		return true;
	}

	/**
	 * Record a reconciled refund, ignoring an already used balance.
	 *
	 * @since 1.0.0
	 *
	 * @param int         $orderId  The purchase order id.
	 * @param int         $amount   The refund amount in minor units.
	 * @param string|null $refundId The gateway refund transaction id, or null.
	 * @return bool True when the row was recorded, false when the ledger rejected it
	 *              (its balance was already consumed by a racing writer).
	 */
	private function recordEntry( int $orderId, int $amount, ?string $refundId ): bool {
		$context = null === $refundId ? array() : array( 'gateway_txn_id' => $refundId );

		try {
			$this->refunds->refund( $orderId, $amount, $context );

			return true;
		} catch ( OrderValidationException ) {
			// A competing writer used this balance.
			return false;
		}
	}

	/**
	 * Get a gateway idempotency key for a refund.
	 *
	 * Use a supplied key or a stable order-and-amount key.
	 *
	 * @since 1.0.0
	 *
	 * @param int                  $orderId The order being refunded.
	 * @param int                  $amount  The refund amount in minor units.
	 * @param array<string, mixed> $context The request context.
	 * @return string The idempotency key.
	 */
	private function idempotencyKey( int $orderId, int $amount, array $context ): string {
		$supplied = $this->suppliedKey( $context );

		if ( '' !== $supplied ) {
			return $supplied;
		}

		return sprintf( 'tiermint-refund-%d-%d', $orderId, $amount );
	}

	/**
	 * Get the trimmed request idempotency key, if supplied.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $context The request context.
	 * @return string The supplied key, or '' when absent.
	 */
	private function suppliedKey( array $context ): string {
		return isset( $context['idempotency_key'] ) ? trim( (string) $context['idempotency_key'] ) : '';
	}

	/**
	 * Reload a purchase order after a duplicate refund.
	 *
	 * @since 1.0.0
	 *
	 * @param int $orderId The purchase order id.
	 * @return Order The reloaded order.
	 *
	 * @throws OrderNotFoundException When the order cannot be reloaded.
	 */
	private function reloadOrFail( int $orderId ): Order {
		$reloaded = $this->orders->find( $orderId );

		if ( null === $reloaded ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw OrderNotFoundException::forId( $orderId );
		}

		return $reloaded;
	}
}
