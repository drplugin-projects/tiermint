<?php
/**
 * Exception for a forbidden order status transition.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Orders;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Rejected order status transition.
 *
 * @since 1.0.0
 */
final class OrderTransitionException extends OrderException {

	/**
	 * Build the exception describing the rejected transition.
	 *
	 * @since 1.0.0
	 *
	 * @param OrderStatus $from The order's current status.
	 * @param OrderStatus $to   The status the transition tried to reach.
	 * @return self
	 */
	public static function between( OrderStatus $from, OrderStatus $to ): self {
		/* translators: 1: the order's current status, 2: the requested order status. */
		return new self( sprintf( __( 'Cannot change order status from "%1$s" to "%2$s".', 'tiermint' ), $from->value, $to->value ) );
	}
}
