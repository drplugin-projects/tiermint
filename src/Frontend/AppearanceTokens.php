<?php
/**
 * Emits the stored storefront appearance overrides as an inline token block.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend;

use DrPlugin\WP\TierMint\Core\Hookable;

defined( 'ABSPATH' ) || exit;

/**
 * Adds appearance overrides to the shared token stylesheet.
 *
 * @since 1.0.0
 */
class AppearanceTokens implements Hookable {

	/**
	 * The option holding all non-secret settings.
	 *
	 * @var string
	 */
	private const OPTION = 'tiermint_settings';

	/**
	 * The appearance validator and token deriver.
	 *
	 * @var Appearance
	 */
	private Appearance $appearance;

	/**
	 * Wire the emitter with the shared appearance logic.
	 *
	 * @param Appearance $appearance The appearance validator and token deriver.
	 */
	public function __construct( Appearance $appearance ) {
		$this->appearance = $appearance;
	}

	/**
	 * Register hooks after the token stylesheet is registered.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'init', array( $this, 'addInlineTokens' ), 20 );
	}

	/**
	 * Attach the override block to the tokens handle, or nothing when no valid
	 * override is stored.
	 *
	 * @return void
	 */
	public function addInlineTokens(): void {
		$css = $this->appearance->css( $this->storedOverrides() );

		if ( '' === $css ) {
			return;
		}

		wp_add_inline_style( AssetManager::TOKENS_HANDLE, $css );
	}

	/**
	 * The stored appearance overrides, or an empty array when none exist.
	 *
	 * @return array<string, mixed> The `appearance` sub-array of the option.
	 */
	private function storedOverrides(): array {
		$settings = get_option( self::OPTION );

		if ( ! is_array( $settings ) || ! isset( $settings['appearance'] ) || ! is_array( $settings['appearance'] ) ) {
			return array();
		}

		return $settings['appearance'];
	}
}
