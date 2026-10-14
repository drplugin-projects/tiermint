<?php
/**
 * The product full-description component.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend\Components;

use DrPlugin\WP\TierMint\Products\Product;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the selected product's full description.
 *
 * @since 1.0.0
 */
final class ProductDescription extends AbstractProductComponent {

	/**
	 * The component slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'product-description';
	}

	/**
	 * The full description for the template, or null when empty.
	 *
	 * @param Product              $product The product to render.
	 * @param array<string, mixed> $attrs   Resolved attribute values (unused here).
	 * @return array{description: string|null}
	 */
	protected function viewData( Product $product, array $attrs ): array {
		unset( $attrs );

		return array(
			'description' => $this->richField( $product->description() ),
		);
	}

	/**
	 * The block is degenerate when the product has no description.
	 *
	 * @param array<string, mixed> $data The reduced view data.
	 * @return bool
	 */
	protected function viewDataIsEmpty( array $data ): bool {
		return null === ( $data['description'] ?? null );
	}

	/**
	 * Sample description for an editor preview.
	 *
	 * @param Product              $product The resolved product (unused; the copy is fixed).
	 * @param array<string, mixed> $attrs   Resolved attribute values (unused here).
	 * @return array{description: string}
	 */
	protected function sampleViewData( Product $product, array $attrs ): array {
		unset( $product, $attrs );

		return array(
			'description' => '<p>' . esc_html__( 'This is where your full product description appears. Explain what the product does, who it is for, and what a customer gets when they buy it.', 'tiermint' ) . '</p>'
				. '<p>' . esc_html__( 'Write as much detail as you like here: features, requirements, and support terms all read well in this space.', 'tiermint' ) . '</p>',
		);
	}
}
