<?php
/**
 * License key generator.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Licensing;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Generates random license keys in four uppercase hexadecimal groups.
 *
 * A filter may change the format, but its result must fit the stored column.
 *
 * @since 1.0.0
 */
class KeyGenerator {

	/**
	 * Maximum accepted key length.
	 *
	 * @var int
	 */
	private const MAX_KEY_LENGTH = 35;

	/**
	 * Generate and validate one license key.
	 *
	 * @since 1.0.0
	 *
	 * @return string A license key of at most 35 characters.
	 *
	 * @throws LicenseValidationException When a filter empties the key or makes it longer than 35 characters.
	 */
	public function generate(): string {
		// Use 128 bits of random data.
		$hex = strtoupper( bin2hex( random_bytes( 16 ) ) );

		// Group the key for display and entry.
		$key = implode( '-', str_split( $hex, 8 ) );

		/**
		 * Filters a generated license key.
		 *
		 * The result must fit the `license_key` CHAR(35) column.
		 *
		 * @since 1.0.0
		 *
		 * @param string $key The default grouped-hex key (35 characters).
		 */
		$key = apply_filters( 'tiermint_license_key', $key );

		$key = (string) $key;

		if ( '' === $key ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new LicenseValidationException( __( 'A license key cannot be empty.', 'tiermint' ) );
		}

		if ( strlen( $key ) > self::MAX_KEY_LENGTH ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new LicenseValidationException( __( 'A license key cannot be longer than 35 characters.', 'tiermint' ) );
		}

		return $key;
	}
}
