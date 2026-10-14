<?php
/**
 * Refund domain service: full and partial order refunds.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Orders;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Records refund orders and updates the purchase status.
 *
 * Gateway calls, reconciliation and license changes use separate hooks. Not final for
 * gateway tests.
 *
 * @since 1.0.0
 */
class RefundService {

	/**
	 * The repository orders are read from and written through.
	 *
	 * @var OrderRepository
	 */
	private OrderRepository $orders;

	/**
	 * The state machine that decides which status transitions are legal.
	 *
	 * @var OrderStateMachine
	 */
	private OrderStateMachine $machine;

	/**
	 * Wire the service with its repository and the state machine.
	 *
	 * @since 1.0.0
	 *
	 * @param OrderRepository   $orders  The orders repository.
	 * @param OrderStateMachine $machine The status transition guard.
	 */
	public function __construct( OrderRepository $orders, OrderStateMachine $machine ) {
		$this->orders  = $orders;
		$this->machine = $machine;
	}

	/**
	 * Record a full or partial refund for an order.
	 *
	 * If the parent update fails, remove the new refund row before rethrowing. The refund
	 * hook fires only after both writes succeed.
	 *
	 * @since 1.0.0
	 *
	 * @param int                  $orderId The purchase order to refund.
	 * @param int                  $amount  The amount to refund, in minor units (positive).
	 * @param array<string, mixed> $context Optional request context (`ip_address`, and `gateway_txn_id`
	 *                                      to stamp the gateway's refund transaction id on the ledger row).
	 * @return Order The reloaded purchase order in its new refunded status. Validation
	 *               failures propagate from {@see assertRefundable()} (a missing order,
	 *               a non-refundable order, or a bad amount).
	 *
	 * @throws OrderNotFoundException    When the purchase vanishes between the update and the reload.
	 * @throws OrderPersistenceException When a write fails (the ledger row is compensated).
	 */
	public function refund( int $orderId, int $amount, array $context = array() ): Order {
		$order = $this->assertRefundable( $orderId, $amount );

		$alreadyRefunded = $this->orders->sumRefundedForOrder( $orderId );

		$newStatus = ( $alreadyRefunded + $amount ) >= $order->total()
			? OrderStatus::Refunded
			: OrderStatus::PartiallyRefunded;

		$this->machine->assertCanTransition( $order->status(), $newStatus );

		$ledgerId = $this->orders->create( $this->refundLedger( $order, $amount, $context ) );

		try {
			$this->orders->update( $order->withStatus( $newStatus ) );
		} catch ( OrderPersistenceException $e ) {
			// Remove the new row so a retry cannot record the refund twice.
			$this->orders->delete( $ledgerId );

			throw $e;
		}

		$saved = $this->orders->find( $orderId );
		// The hook needs a saved order, so a missing row is an update failure.
		if ( null === $saved ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer/validation message, surfaced as a structured error, never echoed as HTML.
			throw OrderNotFoundException::forId( $orderId );
		}

		/**
		 * Fires after a refund and its purchase status are saved.
		 *
		 * @since 1.0.0
		 *
		 * @param Order $order          The refunded order, reloaded in its new status.
		 * @param int   $amountRefunded The amount refunded in this operation, in minor units.
		 */
		do_action( 'tiermint_order_refunded', $saved, $amount );

		return $saved;
	}

	/**
	 * Validate a refund before a gateway call.
	 *
	 * This does not reserve the balance. refund() validates again before it writes.
	 *
	 * @since 1.0.0
	 *
	 * @param int $orderId The purchase order to validate a refund against.
	 * @param int $amount  The amount to refund, in minor units (positive).
	 * @return Order The loaded, refundable purchase order.
	 *
	 * @throws OrderNotFoundException   When no order has the given id.
	 * @throws OrderValidationException When the order is not refundable, or the amount is
	 *                                  non-positive or exceeds the refundable balance.
	 */
	public function assertRefundable( int $orderId, int $amount ): Order {
		$order = $this->orders->find( $orderId );

		if ( null === $order ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer/validation message, surfaced as a structured 404, never echoed as HTML.
			throw OrderNotFoundException::forId( $orderId );
		}

		// Refund rows are completed, but must never be refunded again.
		if ( OrderType::Refund === $order->type() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured validation message, surfaced as an API error, never echoed as HTML.
			throw OrderValidationException::withErrors( array( __( 'A refund cannot itself be refunded.', 'tiermint' ) ) );
		}

		if ( ! $order->isRefundable() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured validation message, surfaced as an API error, never echoed as HTML.
			throw OrderValidationException::withErrors( array( __( 'Only a completed order can be refunded.', 'tiermint' ) ) );
		}

		if ( $amount <= 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured validation message, surfaced as an API error, never echoed as HTML.
			throw OrderValidationException::withErrors( array( __( 'A refund amount must be greater than zero.', 'tiermint' ) ) );
		}

		$alreadyRefunded = $this->orders->sumRefundedForOrder( $orderId );
		$remaining       = $order->total() - $alreadyRefunded;

		if ( $amount > $remaining ) {
			/* translators: 1: the requested refund amount in minor units, 2: the remaining refundable balance in minor units. */
			$message = sprintf( __( 'The refund amount (%1$d) exceeds the remaining refundable balance (%2$d).', 'tiermint' ), $amount, $remaining );

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured validation message, surfaced as an API error, never echoed as HTML.
			throw OrderValidationException::withErrors( array( $message ) );
		}

		return $order;
	}

	/**
	 * Build the negative refund order linked to the purchase.
	 *
	 * @since 1.0.0
	 *
	 * @param Order                $order   The purchase being refunded.
	 * @param int                  $amount  The positive refund amount in minor units.
	 * @param array<string, mixed> $context Optional request context (`ip_address`, `gateway_txn_id`).
	 * @return Order The refund ledger order (unsaved).
	 */
	private function refundLedger( Order $order, int $amount, array $context ): Order {
		$ip = isset( $context['ip_address'] ) && '' !== trim( (string) $context['ip_address'] )
			? trim( (string) $context['ip_address'] )
			: null;

		// A webhook uses this id to find an already recorded refund.
		$gatewayTxnId = isset( $context['gateway_txn_id'] ) && '' !== trim( (string) $context['gateway_txn_id'] )
			? trim( (string) $context['gateway_txn_id'] )
			: null;

		return new Order(
			null,
			OrderUuid::generate(),
			OrderStatus::Completed,
			$order->customerId(),
			$order->email(),
			$order->currency(),
			-$amount,
			0,
			0,
			-$amount,
			$order->gateway(),
			$gatewayTxnId,
			null,
			null,
			OrderType::Refund,
			$order->id(),
			$ip,
			$order->country(),
			null,
			gmdate( 'Y-m-d H:i:s' ),
			null,
			array()
		);
	}
}
