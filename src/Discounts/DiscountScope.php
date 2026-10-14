<?php
/**
 * Automatic-discount scope enum.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Discounts;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Defines the scope of an automatic discount.
 *
 * @since 1.0.0
 */
enum DiscountScope: string {

	/**
	 * All products.
	 */
	case All = 'all';

	/**
	 * Products in `product_ids`.
	 */
	case Products = 'products';

	/**
	 * Products directly assigned to `category_ids`.
	 */
	case Categories = 'categories';
}
