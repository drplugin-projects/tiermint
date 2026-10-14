<?php
/**
 * Base class for the routed-product front-end components.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend\Components;

use DrPlugin\WP\TierMint\Frontend\AbstractComponent;
use DrPlugin\WP\TierMint\Frontend\RenderContext;
use DrPlugin\WP\TierMint\Products\Product;
use DrPlugin\WP\TierMint\Products\ProductService;
use DrPlugin\WP\TierMint\Products\ProductStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Base for components that render a routed or selected published product.
 *
 * The editor shows a placeholder when no product resolves.
 *
 * @since 1.0.0
 */
abstract class AbstractProductComponent extends AbstractComponent {

	/**
	 * The catalog service used to load a product by id.
	 *
	 * @var ProductService
	 */
	protected ProductService $products;

	/**
	 * Wire the catalog service.
	 *
	 * @param ProductService $products The catalog service.
	 */
	public function __construct( ProductService $products ) {
		$this->products = $products;
	}

	/**
	 * The attribute schema: a single product to render, routed by default.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function schema(): array {
		return array(
			'product_id' => array(
				'type'        => 'integer',
				'default'     => 0,
				'control'     => 'product_select',
				// Zero means the product routed to the details page.
				'routed'      => true,
				'label'       => __( 'Product', 'tiermint' ),
				'description' => __( 'The product to show. Leave at 0 to render the product the details page is opened on.', 'tiermint' ),
			),
		);
	}

	/**
	 * Render the resolved product, an editor placeholder, or nothing.
	 *
	 * @param array<string, mixed> $attrs Resolved attribute values.
	 * @param RenderContext        $ctx   Per-request front-end state.
	 * @return string Escaped HTML.
	 */
	public function render( array $attrs, RenderContext $ctx ): string {
		$product = $this->resolveProduct( (int) ( $attrs['product_id'] ?? 0 ), $ctx );

		if ( null === $product ) {
			return $ctx->isEditorPreview() ? $this->editorPlaceholder() : '';
		}

		$data = $this->viewData( $product, $attrs );

		// Use sample content only for an empty editor preview.
		if ( $ctx->isEditorPreview() && $this->viewDataIsEmpty( $data ) ) {
			return $this->renderSample( $this->sampleViewData( $product, $attrs ) );
		}

		return $this->renderTemplate( $data );
	}

	/**
	 * Whether the editor preview needs sample content.
	 *
	 * @param array<string, mixed> $data The reduced view data.
	 * @return bool True when the data is empty enough to warrant a sample.
	 */
	protected function viewDataIsEmpty( array $data ): bool {
		return false;
	}

	/**
	 * Sample view data for an empty editor preview.
	 *
	 * @param Product              $product The resolved (sample or routed) product.
	 * @param array<string, mixed> $attrs   Resolved attribute values.
	 * @return array<string, mixed>
	 */
	protected function sampleViewData( Product $product, array $attrs ): array {
		return $this->viewData( $product, $attrs );
	}

	/**
	 * Resolve a selected product or the routed product.
	 *
	 * @param int           $productId The requested product id, or 0 for routed.
	 * @param RenderContext $ctx       Per-request front-end state.
	 * @return Product|null The published product, or null.
	 */
	protected function resolveProduct( int $productId, RenderContext $ctx ): ?Product {
		$product = $productId > 0 ? $this->products->get( $productId ) : $ctx->product();

		if ( null === $product || ProductStatus::Published !== $product->status() ) {
			return null;
		}

		return $product;
	}

	/**
	 * Reduce a product to the flat, escape-ready view data the template needs.
	 *
	 * @param Product              $product The product to render.
	 * @param array<string, mixed> $attrs   Resolved attribute values (display toggles).
	 * @return array<string, mixed>
	 */
	abstract protected function viewData( Product $product, array $attrs ): array;

	/**
	 * Return non-empty rich text, or null.
	 *
	 * @param string|null $html The stored rich text, or null.
	 * @return string|null
	 */
	protected function richField( ?string $html ): ?string {
		if ( null === $html ) {
			return null;
		}

		$html = trim( $html );

		return '' === $html ? null : $html;
	}

	/**
	 * Render the editor placeholder when no product resolves.
	 *
	 * @return string Escaped HTML.
	 */
	protected function editorPlaceholder(): string {
		return '<div class="tiermint-component tiermint-product-preview--placeholder">'
			. '<p>' . esc_html__( 'This block shows product details on the product page.', 'tiermint' ) . '</p>'
			. '</div>';
	}
}
