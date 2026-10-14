<?php
/**
 * Exception for a missing release.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Updates;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Raised when a requested release does not exist.
 *
 * @since 1.0.0
 */
final class ReleaseNotFoundException extends ReleaseException {

	/**
	 * Builds a missing-release failure by ID.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The release id that could not be found.
	 * @return self
	 */
	public static function forId( int $id ): self {
		return new self( sprintf( 'Release %d was not found.', $id ) );
	}

	/**
	 * Builds a missing-release failure by product and version.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $productId The owning product id.
	 * @param string $version   The version that could not be found.
	 * @return self
	 */
	public static function forProductAndVersion( int $productId, string $version ): self {
		return new self( sprintf( 'Release version "%s" was not found for product %d.', $version, $productId ) );
	}
}
