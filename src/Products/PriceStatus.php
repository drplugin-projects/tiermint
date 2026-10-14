<?php
/**
 * Price tier status enum.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Products;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Stored price tier state.
 *
 * Archived tiers remain valid for past orders and licenses.
 *
 * @since 1.0.0
 */
enum PriceStatus: string {

	/**
	 * A live tier, offered for purchase and eligible to be the default tier.
	 */
	case Active = 'active';

	/**
	 * A retired tier, preserved for existing orders/licenses but not offered.
	 */
	case Archived = 'archived';
}
