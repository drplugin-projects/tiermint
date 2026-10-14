<?php
/**
 * Duplicate release version persistence failure.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Updates;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Raised when a release version already exists for a product.
 *
 * @since 1.0.0
 */
final class DuplicateReleaseVersionException extends ReleasePersistenceException {

	/**
	 * Builds a duplicate-version failure.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $productId The owning product id.
	 * @param string $version   The version that collided.
	 * @return self The exception to throw.
	 */
	public static function forProductAndVersion( int $productId, string $version ): self {
		return new self( sprintf( 'Release version "%s" already exists for product %d.', $version, $productId ) );
	}
}
