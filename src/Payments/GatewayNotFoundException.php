<?php
/**
 * Exception for a payment gateway id that is not registered.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Payments;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Raised for an unknown gateway id.
 *
 * Controllers return its translated message.
 *
 * @since 1.0.0
 */
final class GatewayNotFoundException extends PaymentException {

	/**
	 * Build the standard message for an unknown gateway id.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id The gateway id that failed to resolve.
	 * @return self
	 */
	public static function forId( string $id ): self {
		return new self(
			sprintf(
				/* translators: %s: the requested payment gateway id, e.g. "stripe". */
				__( 'Unknown payment gateway: %s.', 'tiermint' ),
				$id
			)
		);
	}
}
