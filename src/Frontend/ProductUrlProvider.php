<?php
/**
 * Builds the details-page URL for a product.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend;

use DrPlugin\WP\TierMint\Products\Product;

defined( 'ABSPATH' ) || exit;

/**
 * Builds details page URLs for products.
 *
 * Returns null when the details page is unavailable.
 *
 * @since 1.0.0
 */
class ProductUrlProvider {

	/**
	 * The query var carrying the product slug on both permalink modes.
	 *
	 * @var string
	 */
	public const QUERY_VAR = 'tiermint_product';

	/**
	 * The resolver for the configured details page.
	 *
	 * @var PageResolver
	 */
	private PageResolver $pages;

	/**
	 * Wire the page resolver.
	 *
	 * @param PageResolver $pages The storefront page resolver.
	 */
	public function __construct( PageResolver $pages ) {
		$this->pages = $pages;
	}

	/**
	 * Get a product details URL.
	 *
	 * @since 1.0.0
	 *
	 * @param Product $product The product to link to.
	 * @return string|null The details URL, or null.
	 */
	public function url( Product $product ): ?string {
		$base = $this->pages->url( PageResolver::PRODUCT );

		if ( null === $base ) {
			return null;
		}

		$slug = $product->slug();

		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			$url = add_query_arg( self::QUERY_VAR, $slug, $base );
		} else {
			// Add the slug with the site's slash convention.
			$url = user_trailingslashit( trailingslashit( $base ) . $slug );
		}

		/**
		 * Filters the details URL built for a product.
		 *
		 * @since 1.0.0
		 *
		 * @param string  $url     The resolved details URL.
		 * @param Product $product The product the URL points at.
		 */
		return (string) apply_filters( 'tiermint_product_url', $url, $product );
	}
}
