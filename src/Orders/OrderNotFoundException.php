<?php
/**
 * Exception for a missing order.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Orders;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Missing order exception. REST controllers map it to HTTP 404.
 *
 * @since 1.0.0
 */
final class OrderNotFoundException extends OrderException {

	/**
	 * Create an exception for an order ID.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The order id that could not be found.
	 * @return self
	 */
	public static function forId( int $id ): self {
		return new self( sprintf( 'Order %d was not found.', $id ) );
	}

	/**
	 * Create an exception for an order UUID.
	 *
	 * @since 1.0.0
	 *
	 * @param string $uuid The order uuid that could not be found.
	 * @return self
	 */
	public static function forUuid( string $uuid ): self {
		return new self( sprintf( 'Order "%s" was not found.', $uuid ) );
	}
}
