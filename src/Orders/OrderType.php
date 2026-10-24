<?php
/**
 * Order type enum.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Orders;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Stored order types.
 * Refund rows have negative totals and reference the purchase.
 *
 * @since 1.0.0
 */
enum OrderType: string {

	/**
	 * First purchase.
	 */
	case Purchase = 'purchase';

	/**
	 * Renewal of an earlier purchase.
	 */
	case Renewal = 'renewal';

	/**
	 * Negative refund row linked by `parent_id`.
	 */
	case Refund = 'refund';
}
