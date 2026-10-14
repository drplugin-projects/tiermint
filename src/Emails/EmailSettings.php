<?php
/**
 * Typed read accessor for the transactional email settings.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Emails;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Reads transactional email settings.
 * Defaults allow email without configuration.
 *
 * @since 1.0.0
 */
class EmailSettings {

	/**
	 * Option name of the shared, autoloaded settings array.
	 *
	 * @var string
	 */
	private const SETTINGS_OPTION = 'tiermint_settings';

	/**
	 * Get the configured sender name or the site title.
	 *
	 * @since 1.0.0
	 *
	 * @return string A non-empty from name.
	 */
	public function fromName(): string {
		$stored = $this->emails()['from_name'] ?? '';
		$stored = is_string( $stored ) ? trim( $stored ) : '';

		return '' !== $stored ? $stored : (string) get_bloginfo( 'name' );
	}

	/**
	 * Get the configured sender address or the admin address.
	 *
	 * @since 1.0.0
	 *
	 * @return string A from address (the admin email when unconfigured).
	 */
	public function fromAddress(): string {
		$stored = $this->emails()['from_address'] ?? '';
		$stored = is_string( $stored ) ? trim( $stored ) : '';

		return '' !== $stored ? $stored : (string) get_option( 'admin_email' );
	}

	/**
	 * Check whether an email is enabled. Missing settings enable it.
	 *
	 * @since 1.0.0
	 *
	 * @param string $emailId The email id, e.g. 'purchase_receipt'.
	 * @return bool True when the email should be sent.
	 */
	public function isEnabled( string $emailId ): bool {
		$emails = $this->emails();
		$key    = 'enabled_' . $emailId;

		if ( ! array_key_exists( $key, $emails ) ) {
			return true;
		}

		return $this->toBool( $emails[ $key ] );
	}

	/**
	 * Read the email settings array.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed> The stored email settings, or an empty array.
	 */
	private function emails(): array {
		$settings = get_option( self::SETTINGS_OPTION, array() );

		if ( ! is_array( $settings ) ) {
			return array();
		}

		$emails = $settings['emails'] ?? array();

		return is_array( $emails ) ? $emails : array();
	}

	/**
	 * Convert a stored setting to a boolean.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value The stored value.
	 * @return bool The coerced boolean.
	 */
	private function toBool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) ) {
			return 0 !== $value;
		}

		if ( is_string( $value ) ) {
			$normalized = strtolower( trim( $value ) );

			if ( in_array( $normalized, array( '1', 'true', 'yes', 'on' ), true ) ) {
				return true;
			}

			if ( in_array( $normalized, array( '0', 'false', 'no', 'off', '' ), true ) ) {
				return false;
			}
		}

		return (bool) $value;
	}
}
