<?php
/**
 * Duplicate license key persistence failure.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Licensing;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Raised when a license key already exists.
 *
 * The key generator retries with a new key.
 *
 * @since 1.0.0
 */
final class DuplicateLicenseKeyException extends LicensePersistenceException {

	/**
	 * Build a duplicate-key error.
	 *
	 * @since 1.0.0
	 *
	 * @param string $licenseKey The key that collided.
	 * @return self The exception to throw.
	 */
	public static function forKey( string $licenseKey ): self {
		return new self( sprintf( 'A license with the key "%s" already exists.', $licenseKey ) );
	}
}
