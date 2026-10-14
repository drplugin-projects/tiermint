<?php
/**
 * Reads the discount combination rule and the strikethrough display flag.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Discounts;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Reads discount combination and catalog display settings.
 * Invalid or missing values use the existing defaults.
 *
 * @since 1.0.0
 */
final class DiscountSettings {

	/**
	 * The settings option holding both subtrees.
	 *
	 * @var string
	 */
	private const SETTINGS_OPTION = 'tiermint_settings';

	/**
	 * A code replaces an automatic discount by default.
	 *
	 * @var string
	 */
	public const COMBINATION_CODE_REPLACES_AUTO = 'code_replaces_auto';

	/**
	 * A code stacks with an automatic discount.
	 *
	 * @var string
	 */
	public const COMBINATION_STACK = 'stack';

	/**
	 * Return a valid code combination setting.
	 *
	 * @since 1.0.0
	 *
	 * @return string One of the COMBINATION_* constants.
	 */
	public function codeCombination(): string {
		$value = (string) $this->value( 'discounts', 'code_combination', self::COMBINATION_CODE_REPLACES_AUTO );

		return in_array( $value, array( self::COMBINATION_CODE_REPLACES_AUTO, self::COMBINATION_STACK ), true )
			? $value
			: self::COMBINATION_CODE_REPLACES_AUTO;
	}

	/**
	 * Whether a typed code stacks with a matching automatic discount.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function stacks(): bool {
		return self::COMBINATION_STACK === $this->codeCombination();
	}

	/**
	 * Whether catalog prices show an automatic-sale strikethrough.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function strikethroughEnabled(): bool {
		$settings = get_option( self::SETTINGS_OPTION );

		if ( is_array( $settings ) && isset( $settings['storefront']['discount_strikethrough'] ) ) {
			return (bool) $settings['storefront']['discount_strikethrough'];
		}

		return true;
	}

	/**
	 * Read a raw value from a settings subtree, or a default when absent.
	 *
	 * @since 1.0.0
	 *
	 * @param string $subtree  The top-level settings key (e.g. `discounts`).
	 * @param string $key      The key under that subtree.
	 * @param mixed  $fallback The value when either segment is absent.
	 * @return mixed
	 */
	private function value( string $subtree, string $key, $fallback ) {
		$settings = get_option( self::SETTINGS_OPTION );

		if ( is_array( $settings ) && isset( $settings[ $subtree ][ $key ] ) ) {
			return $settings[ $subtree ][ $key ];
		}

		return $fallback;
	}
}
