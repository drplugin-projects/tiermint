<?php
/**
 * Resolves the product routed to a details-page request.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend;

use DrPlugin\WP\TierMint\Core\Hookable;
use DrPlugin\WP\TierMint\Products\ProductService;
use DrPlugin\WP\TierMint\Products\ProductStatus;
use DrPlugin\WP\TierMint\Products\Product;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the routed product before storefront components render.
 *
 * Missing product slugs return 404 only on the details page.
 *
 * @since 1.0.0
 */
class ProductRequestContext implements Hookable {

	/**
	 * The early template redirect priority.
	 *
	 * @var int
	 */
	public const PRIORITY = 5;

	/**
	 * The catalog service the slug is resolved through.
	 *
	 * @var ProductService
	 */
	private ProductService $products;

	/**
	 * The resolver used to tell whether this request is the details page.
	 *
	 * @var PageResolver
	 */
	private PageResolver $pages;

	/**
	 * The resolved routed product, or null when none applies.
	 *
	 * @var Product|null
	 */
	private ?Product $product = null;

	/**
	 * The memoized first published product for editor previews.
	 *
	 * @var Product|null
	 */
	private ?Product $sample = null;

	/**
	 * Whether the preview sample query has already run.
	 *
	 * @var bool
	 */
	private bool $sampleResolved = false;

	/**
	 * Wire the catalog service and page resolver.
	 *
	 * @param ProductService $products The catalog service.
	 * @param PageResolver   $pages    The storefront page resolver.
	 */
	public function __construct( ProductService $products, PageResolver $pages ) {
		$this->products = $products;
		$this->pages    = $pages;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'template_redirect', array( $this, 'resolve' ), self::PRIORITY );
	}

	/**
	 * Resolve the routed product or return a 404 on the details page.
	 *
	 * @return void
	 */
	public function resolve(): void {
		$slug = $this->requestedSlug();

		if ( '' === $slug ) {
			return;
		}

		$product = $this->products->getBySlug( $slug );

		if ( null !== $product && ProductStatus::Published === $product->status() ) {
			$this->product = $product;

			return;
		}

		// Ignore unused query arguments outside the details page.
		if ( $this->isDetailsPage() ) {
			$this->send404();
		}
	}

	/**
	 * The routed product, or null when this request routes to none.
	 *
	 * @since 1.0.0
	 *
	 * @return Product|null
	 */
	public function product(): ?Product {
		return $this->product;
	}

	/**
	 * Get the routed product or a memoized editor preview sample.
	 *
	 * @since 1.0.0
	 *
	 * @return Product|null
	 */
	public function previewProduct(): ?Product {
		if ( null !== $this->product ) {
			return $this->product;
		}

		if ( ! $this->sampleResolved ) {
			$this->sampleResolved = true;
			$this->sample         = $this->firstPublishedProduct();
		}

		return $this->sample;
	}

	/**
	 * Get the first published product for an editor preview.
	 *
	 * @return Product|null
	 */
	private function firstPublishedProduct(): ?Product {
		$products = $this->products->list(
			array(
				'status'   => ProductStatus::Published->value,
				'per_page' => 1,
				'page'     => 1,
				'orderby'  => 'id',
				'order'    => 'ASC',
			)
		);

		return $products[0] ?? null;
	}

	/**
	 * Get the sanitized routed product slug.
	 *
	 * @return string The slug, or '' when the request carries none.
	 */
	private function requestedSlug(): string {
		$slug = sanitize_title( (string) get_query_var( ProductUrlProvider::QUERY_VAR ) );

		if ( '' !== $slug ) {
			return $slug;
		}

		// Fall back to the raw query argument on plain permalink installs.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only public catalogue lookup, no state change; sanitized to a slug on this line.
		return isset( $_GET[ ProductUrlProvider::QUERY_VAR ] ) ? sanitize_title( (string) wp_unslash( $_GET[ ProductUrlProvider::QUERY_VAR ] ) ) : '';
	}

	/**
	 * Whether the current request resolves to the configured details page.
	 *
	 * @return bool
	 */
	private function isDetailsPage(): bool {
		return $this->pages->isPage( PageResolver::PRODUCT, (int) get_queried_object_id() );
	}

	/**
	 * Turn the current request into a genuine, uncached 404.
	 *
	 * @return void
	 */
	private function send404(): void {
		$query = $GLOBALS['wp_query'] ?? null;

		if ( is_object( $query ) && method_exists( $query, 'set_404' ) ) {
			$query->set_404();
		}

		status_header( 404 );
		nocache_headers();
	}
}
