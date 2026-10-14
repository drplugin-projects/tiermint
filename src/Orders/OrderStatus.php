<?php
/**
 * Order lifecycle status enum.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Orders;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Stored order lifecycle states.
 * {@see OrderStateMachine} controls transitions.
 *
 * @since 1.0.0
 */
enum OrderStatus: string {

	/**
	 * Created but unpaid.
	 */
	case Pending = 'pending';

	/**
	 * Payment confirmed. Triggers `tiermint_order_completed`.
	 */
	case Completed = 'completed';

	/**
	 * Payment failed. A late webhook can still complete it.
	 */
	case Failed = 'failed';

	/**
	 * Fully refunded: the entire order total has been returned.
	 */
	case Refunded = 'refunded';

	/**
	 * Some payment returned. More refunds can fully refund it.
	 */
	case PartiallyRefunded = 'partially_refunded';

	/**
	 * Unpaid pending order closed by cleanup.
	 */
	case Abandoned = 'abandoned';
}
