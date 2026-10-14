<?php
/**
 * The product screenshots gallery component.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend\Components;

use DrPlugin\WP\TierMint\Frontend\Components\Support\SamplePreview;
use DrPlugin\WP\TierMint\Frontend\Components\Support\ScreenshotGallery;
use DrPlugin\WP\TierMint\Products\Product;

defined( 'ABSPATH' ) || exit;

/**
 * Renders product screenshots as a linked thumbnail grid.
 *
 * Missing attachments are omitted.
 *
 * @since 1.0.0
 */
final class ProductScreenshots extends AbstractProductComponent {

	/**
	 * The component slug (template name, asset-handle suffix, shortcode base).
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'product-screenshots';
	}

	/**
	 * The resolved screenshots for the template.
	 *
	 * @param Product              $product The product to render.
	 * @param array<string, mixed> $attrs   Resolved attribute values (unused here).
	 * @return array{screenshots: array<int, array{full: string, thumb: string, alt: string, caption: string}>}
	 */
	protected function viewData( Product $product, array $attrs ): array {
		unset( $attrs );

		return array(
			'screenshots' => ScreenshotGallery::fromIds( $product->settings()['screenshots'] ?? array() ),
		);
	}

	/**
	 * The block is degenerate when the product has no screenshots.
	 *
	 * @param array<string, mixed> $data The reduced view data.
	 * @return bool
	 */
	protected function viewDataIsEmpty( array $data ): bool {
		return array() === ( $data['screenshots'] ?? array() );
	}

	/**
	 * Placeholder tiles for the editor.
	 *
	 * @param Product              $product The resolved product (unused; the tiles are fixed).
	 * @param array<string, mixed> $attrs   Resolved attribute values (unused here).
	 * @return array{screenshots: array<int, array{full: string, thumb: string, alt: string, caption: string}>}
	 */
	protected function sampleViewData( Product $product, array $attrs ): array {
		unset( $product, $attrs );

		$tile = static function ( string $label ): array {
			return array(
				'full'    => '#',
				'thumb'   => SamplePreview::placeholderTile( 'tiermint-product-screenshots__img', $label ),
				'alt'     => $label,
				'caption' => $label,
			);
		};

		return array(
			'screenshots' => array(
				$tile( __( 'Sample screenshot one', 'tiermint' ) ),
				$tile( __( 'Sample screenshot two', 'tiermint' ) ),
			),
		);
	}
}
