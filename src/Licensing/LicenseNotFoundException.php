<?php
/**
 * Exception for a missing license.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Licensing;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Thrown when an operation targets a missing license.
 *
 * The REST layer returns a generic translated `404` response.
 *
 * @since 1.0.0
 */
final class LicenseNotFoundException extends LicenseException {

	/**
	 * Build a missing-license error for an ID.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The license id that could not be found.
	 * @return self
	 */
	public static function forId( int $id ): self {
		return new self( sprintf( 'License %d was not found.', $id ) );
	}

	/**
	 * Build a missing-license error for a key.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key The license key that could not be found.
	 * @return self
	 */
	public static function forKey( string $key ): self {
		return new self( sprintf( 'License key "%s" was not found.', $key ) );
	}
}
