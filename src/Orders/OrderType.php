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
	 * Pro renewal.
	 */
	case Renewal = 'renewal';

	/**
	 * Pro upgrade.
	 */
	case Upgrade = 'upgrade';

	/**
	 * Negative refund row linked by `parent_id`.
	 */
	case Refund = 'refund';
}
