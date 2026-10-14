<?php
/**
 * The product meta component (categories, tags, version, updated date).
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend\Components;

use DrPlugin\WP\TierMint\Frontend\PageResolver;
use DrPlugin\WP\TierMint\Products\Product;
use DrPlugin\WP\TierMint\Products\ProductService;
use DrPlugin\WP\TierMint\Products\ProductTaxonomy;
use DrPlugin\WP\TierMint\Products\Term;
use DrPlugin\WP\TierMint\Products\TermService;
use DrPlugin\WP\TierMint\Updates\ReleaseRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Renders product terms, version and release date.
 *
 * Terms link to the store page when it is available.
 *
 * @since 1.0.0
 */
final class ProductMeta extends AbstractProductComponent {

	/**
	 * The term service supplying the product's categories and tags.
	 *
	 * @var TermService
	 */
	private TermService $terms;

	/**
	 * The resolver for the store page the term links point at.
	 *
	 * @var PageResolver
	 */
	private PageResolver $pages;

	/**
	 * The releases repository the version and date come from.
	 *
	 * @var ReleaseRepository
	 */
	private ReleaseRepository $releases;

	/**
	 * Wire the component's collaborators.
	 *
	 * @param ProductService    $products The catalog service (routed-product resolution).
	 * @param TermService       $terms    The taxonomy service for the product's terms.
	 * @param PageResolver      $pages    The store-page URL resolver.
	 * @param ReleaseRepository $releases The releases repository for version and date.
	 */
	public function __construct( ProductService $products, TermService $terms, PageResolver $pages, ReleaseRepository $releases ) {
		parent::__construct( $products );
		$this->terms    = $terms;
		$this->pages    = $pages;
		$this->releases = $releases;
	}

	/**
	 * The component slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'product-meta';
	}

	/**
	 * The routed product and display options.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function schema(): array {
		return parent::schema() + array(
			'show_categories' => array(
				'type'        => 'boolean',
				'default'     => true,
				'label'       => __( 'Show categories', 'tiermint' ),
				'description' => __( "Show the product's categories, linked into the store grid.", 'tiermint' ),
				'control'     => 'toggle',
			),
			'show_tags'       => array(
				'type'        => 'boolean',
				'default'     => true,
				'label'       => __( 'Show tags', 'tiermint' ),
				'description' => __( "Show the product's tags, linked into the store grid.", 'tiermint' ),
				'control'     => 'toggle',
			),
			'show_version'    => array(
				'type'        => 'boolean',
				'default'     => true,
				'label'       => __( 'Show version', 'tiermint' ),
				'description' => __( 'Show the latest published version number.', 'tiermint' ),
				'control'     => 'toggle',
			),
			'show_updated'    => array(
				'type'        => 'boolean',
				'default'     => true,
				'label'       => __( 'Show last updated', 'tiermint' ),
				'description' => __( 'Show the date of the latest published release.', 'tiermint' ),
				'control'     => 'toggle',
			),
		);
	}

	/**
	 * Build the product meta view data.
	 *
	 * @param Product              $product The product to render.
	 * @param array<string, mixed> $attrs   Resolved attribute values (display toggles).
	 * @return array{categories: array<int, array{name: string, url: string|null}>, tags: array<int, array{name: string, url: string|null}>, version: string|null, updated: string|null}
	 */
	protected function viewData( Product $product, array $attrs ): array {
		$productId = (int) $product->id();

		$showCategories = (bool) ( $attrs['show_categories'] ?? true );
		$showTags       = (bool) ( $attrs['show_tags'] ?? true );
		$showVersion    = (bool) ( $attrs['show_version'] ?? true );
		$showUpdated    = (bool) ( $attrs['show_updated'] ?? true );

		$categories = array();
		$tags       = array();

		if ( $showCategories || $showTags ) {
			// A missing store page leaves terms as plain text.
			$storeUrl = $this->pages->url( PageResolver::STORE );

			if ( $showCategories ) {
				$categories = $this->termLinks( $this->terms->termsForProduct( $productId, ProductTaxonomy::Category ), ProductGrid::GET_CATEGORY, $storeUrl );
			}

			if ( $showTags ) {
				$tags = $this->termLinks( $this->terms->termsForProduct( $productId, ProductTaxonomy::Tag ), ProductGrid::GET_TAG, $storeUrl );
			}
		}

		$version = null;
		$updated = null;

		if ( $showVersion || $showUpdated ) {
			// The default query excludes prerelease channels.
			$latest = $this->releases->latestPublished( $productId );

			if ( null !== $latest ) {
				if ( $showVersion ) {
					$version = $latest->version();
				}

				$releasedAt = $latest->releasedAt();
				if ( $showUpdated && null !== $releasedAt ) {
					$formatted = wp_date( (string) get_option( 'date_format' ), $releasedAt->getTimestamp() );
					$updated   = ( is_string( $formatted ) && '' !== $formatted ) ? $formatted : null;
				}
			}
		}

		return array(
			'categories' => $categories,
			'tags'       => $tags,
			'version'    => $version,
			'updated'    => $updated,
		);
	}

	/**
	 * The block is degenerate when it has no terms and no release to show.
	 *
	 * @param array<string, mixed> $data The reduced view data.
	 * @return bool
	 */
	protected function viewDataIsEmpty( array $data ): bool {
		return array() === ( $data['categories'] ?? array() )
			&& array() === ( $data['tags'] ?? array() )
			&& null === ( $data['version'] ?? null )
			&& null === ( $data['updated'] ?? null );
	}

	/**
	 * Sample meta for an editor preview.
	 *
	 * @param Product              $product The resolved product (unused; the sample is fixed).
	 * @param array<string, mixed> $attrs   Resolved attribute values (display toggles).
	 * @return array{categories: array<int, array{name: string, url: string|null}>, tags: array<int, array{name: string, url: string|null}>, version: string|null, updated: string|null}
	 */
	protected function sampleViewData( Product $product, array $attrs ): array {
		unset( $product );

		$showCategories = (bool) ( $attrs['show_categories'] ?? true );
		$showTags       = (bool) ( $attrs['show_tags'] ?? true );
		$showVersion    = (bool) ( $attrs['show_version'] ?? true );
		$showUpdated    = (bool) ( $attrs['show_updated'] ?? true );

		$updated = null;
		if ( $showUpdated ) {
			$formatted = wp_date( (string) get_option( 'date_format' ), 1_722_506_400 );
			$updated   = ( is_string( $formatted ) && '' !== $formatted ) ? $formatted : null;
		}

		return array(
			'categories' => $showCategories ? array(
				array(
					'name' => __( 'Plugins', 'tiermint' ),
					'url'  => null,
				),
				array(
					'name' => __( 'Productivity', 'tiermint' ),
					'url'  => null,
				),
			) : array(),
			'tags'       => $showTags ? array(
				array(
					'name' => __( 'New', 'tiermint' ),
					'url'  => null,
				),
				array(
					'name' => __( 'Popular', 'tiermint' ),
					'url'  => null,
				),
			) : array(),
			'version'    => $showVersion ? '2.1.0' : null,
			'updated'    => $updated,
		);
	}

	/**
	 * Build name and URL rows for product terms.
	 *
	 * @param array<int, Term> $terms    The product's terms in one taxonomy.
	 * @param string           $getKey   The grid GET key to filter on.
	 * @param string|null      $storeUrl The store page URL, or null.
	 * @return array<int, array{name: string, url: string|null}>
	 */
	private function termLinks( array $terms, string $getKey, ?string $storeUrl ): array {
		$links = array();

		foreach ( $terms as $term ) {
			$url = null;

			if ( null !== $storeUrl ) {
				$url = ( ProductGrid::GET_TAG === $getKey )
					? add_query_arg( array( $getKey => array( $term->slug() ) ), $storeUrl )
					: add_query_arg( $getKey, $term->slug(), $storeUrl );
			}

			$links[] = array(
				'name' => $term->name(),
				'url'  => is_string( $url ) ? $url : null,
			);
		}

		return $links;
	}
}
