<?php
/**
 * Product lifecycle status enum.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Products;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Stored product lifecycle state.
 *
 * {@see ProductService} controls state transitions.
 *
 * @since 1.0.0
 */
enum ProductStatus: string {

	/**
	 * A work-in-progress product, hidden from the storefront and not purchasable.
	 */
	case Draft = 'draft';

	/**
	 * A live product, visible in the storefront and purchasable.
	 */
	case Published = 'published';

	/**
	 * A retired product, kept for existing orders/licenses but off the storefront.
	 */
	case Archived = 'archived';
}
