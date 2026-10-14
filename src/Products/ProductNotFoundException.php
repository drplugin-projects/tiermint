<?php
/**
 * Exception for a missing product.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Products;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Missing product.
 *
 * @since 1.0.0
 */
final class ProductNotFoundException extends ProductException {

	/**
	 * Build the exception for a specific missing product id.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The product id that could not be found.
	 * @return self
	 */
	public static function forId( int $id ): self {
		return new self( sprintf( 'Product %d was not found.', $id ) );
	}
}
