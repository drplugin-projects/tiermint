<?php
/**
 * Exception for a missing discount.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Discounts;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Thrown when {@see DiscountService} cannot find a discount id.
 *
 * @since 1.0.0
 */
final class DiscountNotFoundException extends DiscountException {

	/**
	 * Build the exception for a specific missing discount id.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The discount id that could not be found.
	 * @return self
	 */
	public static function forId( int $id ): self {
		return new self( sprintf( 'Discount %d was not found.', $id ) );
	}
}
