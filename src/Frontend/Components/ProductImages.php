<?php
/**
 * The product main image component.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend\Components;

use DrPlugin\WP\TierMint\Frontend\Components\Support\SamplePreview;
use DrPlugin\WP\TierMint\Products\Product;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the selected product's main image.
 *
 * @since 1.0.0
 */
final class ProductImages extends AbstractProductComponent {

	/**
	 * The image size rendered for the main image.
	 *
	 * @var string
	 */
	private const IMAGE_SIZE = 'large';

	/**
	 * The component slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'product-images';
	}

	/**
	 * The main image markup for the template, or null when there is none.
	 *
	 * @param Product              $product The product to render.
	 * @param array<string, mixed> $attrs   Resolved attribute values (unused here).
	 * @return array{image: string|null}
	 */
	protected function viewData( Product $product, array $attrs ): array {
		unset( $attrs );

		return array(
			'image' => $this->image( (int) ( $product->settings()['main_image'] ?? 0 ) ),
		);
	}

	/**
	 * The block is degenerate when the product has no resolvable main image.
	 *
	 * @param array<string, mixed> $data The reduced view data.
	 * @return bool
	 */
	protected function viewDataIsEmpty( array $data ): bool {
		return null === ( $data['image'] ?? null );
	}

	/**
	 * Sample image data for an editor preview.
	 *
	 * @param Product              $product The resolved product (unused; the tile is fixed).
	 * @param array<string, mixed> $attrs   Resolved attribute values (unused here).
	 * @return array{image: string}
	 */
	protected function sampleViewData( Product $product, array $attrs ): array {
		unset( $product, $attrs );

		return array(
			'image' => SamplePreview::placeholderTile( 'tiermint-product-images__img', __( 'Sample product image', 'tiermint' ) ),
		);
	}

	/**
	 * Resolve an attachment to responsive image markup.
	 *
	 * @param int $id The attachment id.
	 * @return string|null
	 */
	private function image( int $id ): ?string {
		if ( $id <= 0 ) {
			return null;
		}

		$html = wp_get_attachment_image( $id, self::IMAGE_SIZE, false, array( 'class' => 'tiermint-product-images__img' ) );

		return is_string( $html ) && '' !== $html ? $html : null;
	}
}
