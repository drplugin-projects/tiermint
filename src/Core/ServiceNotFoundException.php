<?php
/**
 * Exception thrown when a service id is not bound in the container.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Core;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use InvalidArgumentException;

/**
 * Raised for an unbound service id.
 *
 * Uses SPL to avoid the psr/container runtime dependency.
 *
 * @since 1.0.0
 */
final class ServiceNotFoundException extends InvalidArgumentException {

	/**
	 * Build an exception for a specific unbound service id.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id The service id that could not be resolved.
	 * @return self
	 */
	public static function forId( string $id ): self {
		return new self(
			sprintf( 'Service "%s" is not bound in the TierMint container.', $id )
		);
	}
}
