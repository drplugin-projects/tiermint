<?php
/**
 * Reconciles the theme page title with the block heading on the product page.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend;

use DrPlugin\WP\TierMint\Core\Hookable;
use DrPlugin\WP\TierMint\Products\Product;

defined( 'ABSPATH' ) || exit;

/**
 * Prevents duplicate product-name headings on routed product pages.
 *
 * The configured source chooses the theme title or the product block.
 *
 * @since 1.0.0
 */
class ProductPageTitle implements Hookable {

	/**
	 * The settings option holding the storefront subtree.
	 *
	 * @var string
	 */
	private const SETTINGS_OPTION = 'tiermint_settings';

	/**
	 * The shared request context supplying the routed product.
	 *
	 * @var ProductRequestContext
	 */
	private ProductRequestContext $request;

	/**
	 * The storefront page resolver.
	 *
	 * @var PageResolver
	 */
	private PageResolver $pages;

	/**
	 * Wire the request context and page resolver.
	 *
	 * @param ProductRequestContext $request The routed-product source.
	 * @param PageResolver          $pages   The storefront page resolver.
	 */
	public function __construct( ProductRequestContext $request, PageResolver $pages ) {
		$this->request = $request;
		$this->pages   = $pages;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_filter( 'the_title', array( $this, 'filterTitle' ), 10, 2 );
	}

	/**
	 * Return the configured product page heading.
	 *
	 * @param string $title  The title WordPress would otherwise render.
	 * @param int    $postId The post id the title belongs to, or 0 when a caller
	 *                       applies the filter without one.
	 * @return string
	 */
	public function filterTitle( $title, $postId = 0 ): string {
		$title  = (string) $title;
		$postId = (int) $postId;

		if ( ! $this->isFrontend() || $postId <= 0 ) {
			return $title;
		}

		// Only filter the routed product page title.
		if ( ! is_singular() || get_queried_object_id() !== $postId ) {
			return $title;
		}

		if ( ! $this->pages->isPage( PageResolver::PRODUCT, $postId ) ) {
			return $title;
		}

		$product = $this->request->product();

		// Keep the page title when no product is routed.
		if ( null === $product ) {
			return $title;
		}

		// SEO title overrides apply only to the document title.
		return $this->themeOwnsHeading() ? $product->name() : '';
	}

	/**
	 * Whether the theme title owns the product heading.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function themeOwnsHeading(): bool {
		return 'theme' === $this->source();
	}

	/**
	 * Whether this product block must hide a duplicate theme heading.
	 *
	 * @since 1.0.0
	 *
	 * @param Product $product The product the block resolved to render.
	 * @return bool
	 */
	public function blockHidesNameFor( Product $product ): bool {
		if ( ! $this->themeOwnsHeading() || ! $this->isFrontend() || ! is_singular() ) {
			return false;
		}

		if ( ! $this->pages->isPage( PageResolver::PRODUCT, (int) get_queried_object_id() ) ) {
			return false;
		}

		$routed = $this->request->product();

		return null !== $routed && (int) $routed->id() === (int) $product->id();
	}

	/**
	 * Get the product title source, defaulting to `block`.
	 *
	 * @return string Either `block` or `theme`.
	 */
	private function source(): string {
		$settings = get_option( self::SETTINGS_OPTION );

		if ( is_array( $settings ) && isset( $settings['storefront']['product_title_source'] ) ) {
			$value = (string) $settings['storefront']['product_title_source'];

			if ( 'theme' === $value || 'block' === $value ) {
				return $value;
			}
		}

		return 'block';
	}

	/**
	 * Whether this is a front-end page request.
	 *
	 * @return bool
	 */
	private function isFrontend(): bool {
		if ( is_admin() || wp_doing_ajax() ) {
			return false;
		}

		return ! ( defined( 'REST_REQUEST' ) && REST_REQUEST );
	}
}
