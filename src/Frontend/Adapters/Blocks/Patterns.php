<?php
/**
 * Registers the TierMint block pattern category and starter patterns.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend\Adapters\Blocks;

use DrPlugin\WP\TierMint\Core\Hookable;

defined( 'ABSPATH' ) || exit;

/**
 * Registers TierMint starter patterns from the patterns directory.
 *
 * Invalid pattern files do not stop the site.
 *
 * @since 1.0.0
 */
class Patterns implements Hookable {

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'init', array( $this, 'registerPatterns' ) );
	}

	/**
	 * Register the TierMint pattern category and every shipped pattern.
	 *
	 * @return void
	 */
	public function registerPatterns(): void {
		register_block_pattern_category(
			Registrar::CATEGORY_SLUG,
			array( 'label' => __( 'TierMint', 'tiermint' ) )
		);

		$files = glob( $this->patternsBasePath() . '*.php' );

		foreach ( is_array( $files ) ? $files : array() as $file ) {
			$properties = include $file;

			if ( ! is_array( $properties )
				|| '' === (string) ( $properties['title'] ?? '' )
				|| '' === (string) ( $properties['content'] ?? '' ) ) {
				continue;
			}

			$properties['categories'] = array_values(
				array_unique(
					array_merge(
						(array) ( $properties['categories'] ?? array() ),
						array( Registrar::CATEGORY_SLUG )
					)
				)
			);

			register_block_pattern(
				'tiermint/' . basename( $file, '.php' ),
				$properties
			);
		}
	}

	/**
	 * Get the pattern directory.
	 *
	 * @return string
	 */
	protected function patternsBasePath(): string {
		return TIERMINT_PATH . 'patterns/';
	}
}
