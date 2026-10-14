<?php
/**
 * Exception for a missing customer.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Customers;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Raised for an absent customer. REST maps it to HTTP 404.
 *
 * @since 1.0.0
 */
final class CustomerNotFoundException extends CustomerException {

	/**
	 * Build the exception for a specific missing customer id.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The customer id that could not be found.
	 * @return self
	 */
	public static function forId( int $id ): self {
		return new self( sprintf( 'Customer %d was not found.', $id ) );
	}
}
