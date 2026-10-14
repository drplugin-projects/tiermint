<?php
/**
 * Exception for a duplicate discount code.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Discounts;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Thrown when a discount code already exists, ignoring case.
 *
 * @since 1.0.0
 */
final class DuplicateDiscountCodeException extends DiscountValidationException {

	/**
	 * Build the exception for a duplicate code.
	 *
	 * @since 1.0.0
	 *
	 * @param string $code The discount code that is already taken.
	 * @return self
	 */
	public static function forCode( string $code ): self {
		/* translators: %s: the discount code. */
		$message = sprintf( __( 'A discount with the code "%s" already exists.', 'tiermint' ), $code );

		return self::withErrors( array( $message ) );
	}
}
