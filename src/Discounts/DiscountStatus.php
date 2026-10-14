<?php
/**
 * Discount lifecycle status enum.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Discounts;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Defines a discount's stored availability state.
 *
 * @since 1.0.0
 */
enum DiscountStatus: string {

	/**
	 * Available for eligible checkouts.
	 */
	case Active = 'active';

	/**
	 * Not available for checkout.
	 */
	case Inactive = 'inactive';
}
