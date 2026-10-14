<?php
/**
 * Read accessor for the Elementor adapter's on/off toggle.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend\Adapters\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the Elementor integration setting.
 *
 * The adapter stays enabled unless the setting disables it.
 *
 * @since 1.0.0
 */
class ElementorSettings {

	/**
	 * Option name of the shared, autoloaded settings array.
	 *
	 * @var string
	 */
	private const SETTINGS_OPTION = 'tiermint_settings';

	/**
	 * Whether the Elementor adapter should register its widgets and tags.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True unless the toggle is explicitly off.
	 */
	public function elementorEnabled(): bool {
		$settings = get_option( self::SETTINGS_OPTION, array() );

		if ( ! is_array( $settings ) ) {
			return true;
		}

		$integrations = $settings['integrations'] ?? array();

		if ( ! is_array( $integrations ) || ! array_key_exists( 'elementor', $integrations ) ) {
			return true;
		}

		return $this->toBool( $integrations['elementor'] );
	}

	/**
	 * Convert a stored toggle value to a boolean.
	 *
	 * @param mixed $value The stored value.
	 * @return bool
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
