<?php
/**
 * The product name and overview component.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend\Components;

use DrPlugin\WP\TierMint\Frontend\ProductPageTitle;
use DrPlugin\WP\TierMint\Products\Product;
use DrPlugin\WP\TierMint\Products\ProductService;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the product name and overview.
 *
 * The theme page title replaces the block heading when needed.
 *
 * @since 1.0.0
 */
final class ProductOverview extends AbstractProductComponent {

	/**
	 * The product-title source authority: tells whether the theme owns the heading.
	 *
	 * @var ProductPageTitle
	 */
	private ProductPageTitle $pageTitle;

	/**
	 * Wire the catalog service and the product-title source authority.
	 *
	 * @param ProductService   $products  The catalog service.
	 * @param ProductPageTitle $pageTitle The product-title source authority.
	 */
	public function __construct( ProductService $products, ProductPageTitle $pageTitle ) {
		parent::__construct( $products );
		$this->pageTitle = $pageTitle;
	}

	/**
	 * The component slug (template name, asset-handle suffix, shortcode base).
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'product-overview';
	}

	/**
	 * The product name and overview for the template.
	 *
	 * @param Product              $product The product to render.
	 * @param array<string, mixed> $attrs   Resolved attribute values (unused here).
	 * @return array{name: string, show_name: bool, overview: string|null}
	 */
	protected function viewData( Product $product, array $attrs ): array {
		unset( $attrs );

		return array(
			'name'      => $product->name(),
			'show_name' => ! $this->pageTitle->blockHidesNameFor( $product ),
			'overview'  => $this->richField( $product->overview() ),
		);
	}

	/**
	 * Empty when the product has no overview.
	 *
	 * @param array<string, mixed> $data The reduced view data.
	 * @return bool
	 */
	protected function viewDataIsEmpty( array $data ): bool {
		return null === ( $data['overview'] ?? null );
	}

	/**
	 * Sample overview for the editor.
	 *
	 * @param Product              $product The resolved product.
	 * @param array<string, mixed> $attrs   Resolved attribute values.
	 * @return array{name: string, show_name: bool, overview: string}
	 */
	protected function sampleViewData( Product $product, array $attrs ): array {
		$data             = $this->viewData( $product, $attrs );
		$data['overview'] = '<p>' . esc_html__( 'A short, punchy overview of your product goes here. It sits right under the product name to hook a visitor before they read the full description.', 'tiermint' ) . '</p>';

		return $data;
	}
}
