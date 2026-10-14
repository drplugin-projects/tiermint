<?php
/**
 * REST controller for the block/page-builder editor product, term, and page pickers.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\REST;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Core\Capabilities;
use DrPlugin\WP\TierMint\Products\Product;
use DrPlugin\WP\TierMint\Products\ProductService;
use DrPlugin\WP\TierMint\Products\ProductStatus;
use DrPlugin\WP\TierMint\Products\ProductTaxonomy;
use DrPlugin\WP\TierMint\Products\Term;
use DrPlugin\WP\TierMint\Products\TermService;
use WP_Post;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Read-only picker routes for editors and page builders.
 *
 * Editors and store managers can read published picker data. Terms stay unpaginated.
 *
 * @since 1.0.0
 */
final class EditorSearchController extends AbstractRestController {

	/**
	 * The default number of products a search answers with.
	 *
	 * @var int
	 */
	private const DEFAULT_PER_PAGE = 20;

	/**
	 * The search and include-list limit.
	 *
	 * @var int
	 */
	private const MAX_PER_PAGE = 50;

	/**
	 * The catalog service, for the product search and id resolution.
	 *
	 * @var ProductService
	 */
	private ProductService $products;

	/**
	 * The taxonomy service, for the term list.
	 *
	 * @var TermService
	 */
	private TermService $terms;

	/**
	 * Set the catalog and taxonomy services.
	 *
	 * @since 1.0.0
	 *
	 * @param ProductService $products The catalog service.
	 * @param TermService    $terms    The taxonomy service.
	 */
	public function __construct( ProductService $products, TermService $terms ) {
		$this->products = $products;
		$this->terms    = $terms;
	}

	/**
	 * Register the editor product-search, term-list, and page-search routes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			'/editor/products',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'searchProducts' ),
					'permission_callback' => array( $this, 'editorPermission' ),
					'args'                => $this->productArgs(),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/editor/terms',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'listTerms' ),
					'permission_callback' => array( $this, 'editorPermission' ),
					'args'                => $this->termArgs(),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/editor/pages',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'searchPages' ),
					'permission_callback' => array( $this, 'editorPermission' ),
					'args'                => $this->pageArgs(),
				),
			)
		);
	}

	/**
	 * Allow editors and store managers to read picker data.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when the current user may edit posts or manage the store.
	 */
	public function editorPermission(): bool {
		return current_user_can( 'edit_posts' ) || current_user_can( Capabilities::MANAGE );
	}

	/**
	 * List published picker products.
	 *
	 * Included IDs keep caller order and take priority over search.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response The matching products.
	 */
	public function searchProducts( WP_REST_Request $request ): WP_REST_Response {
		$include = $this->includeIds( $request->get_param( 'include' ) );

		if ( array() !== $include ) {
			return new WP_REST_Response( $this->resolveIncluded( $include ), 200 );
		}

		$products = $this->products->list(
			array(
				'status'   => ProductStatus::Published->value,
				'search'   => (string) $request->get_param( 'search' ),
				'orderby'  => 'name',
				'order'    => 'asc',
				'page'     => 1,
				'per_page' => (int) $request->get_param( 'per_page' ),
			)
		);

		return new WP_REST_Response( array_map( array( $this, 'presentProduct' ), $products ), 200 );
	}

	/**
	 * List picker terms for one taxonomy.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response The taxonomy listing.
	 */
	public function listTerms( WP_REST_Request $request ): WP_REST_Response {
		// The enum arg guarantees a valid taxonomy reached the handler.
		$taxonomy = ProductTaxonomy::from( (string) $request->get_param( 'taxonomy' ) );

		$data = array_map( array( $this, 'presentTerm' ), $this->terms->listByTaxonomy( $taxonomy ) );

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * List published picker pages.
	 *
	 * Included IDs keep caller order and take priority over search.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response The matching pages.
	 */
	public function searchPages( WP_REST_Request $request ): WP_REST_Response {
		$include = $this->includeIds( $request->get_param( 'include' ) );

		if ( array() !== $include ) {
			return new WP_REST_Response( $this->resolveIncludedPages( $include ), 200 );
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				's'              => (string) $request->get_param( 'search' ),
				'search_columns' => array( 'post_title' ),
				'orderby'        => 'title',
				'order'          => 'ASC',
				'posts_per_page' => (int) $request->get_param( 'per_page' ),
				'no_found_rows'  => true,
			)
		);

		return new WP_REST_Response( array_map( array( $this, 'presentPage' ), $query->posts ), 200 );
	}

	/**
	 * Resolve published product IDs in caller order.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, int> $ids The (already normalized and capped) product ids.
	 * @return array<int, array<string, mixed>> The resolved rows.
	 */
	private function resolveIncluded( array $ids ): array {
		$rows = array();

		foreach ( $ids as $id ) {
			$product = $this->products->get( $id );

			if ( null !== $product && ProductStatus::Published === $product->status() ) {
				$rows[] = $this->presentProduct( $product );
			}
		}

		return $rows;
	}

	/**
	 * Resolve published page IDs in caller order.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, int> $ids The (already normalized and capped) page ids.
	 * @return array<int, array<string, mixed>> The resolved rows.
	 */
	private function resolveIncludedPages( array $ids ): array {
		$rows = array();

		foreach ( $ids as $id ) {
			$page = get_post( $id );

			if ( $page instanceof WP_Post && 'page' === $page->post_type && 'publish' === $page->post_status ) {
				$rows[] = $this->presentPage( $page );
			}
		}

		return $rows;
	}

	/**
	 * Normalize and limit included IDs.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value The raw include value (expected: a list of ids).
	 * @return array<int, int> The normalized, capped ids.
	 */
	private function includeIds( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $value ),
					static fn ( int $id ): bool => $id > 0
				)
			)
		);

		return array_slice( $ids, 0, self::MAX_PER_PAGE );
	}

	/**
	 * Shape a product row for the picker.
	 *
	 * @since 1.0.0
	 *
	 * @param Product $product The product to present.
	 * @return array<string, mixed> The response representation.
	 */
	private function presentProduct( Product $product ): array {
		return array(
			'id'   => (int) $product->id(),
			'name' => $product->name(),
		);
	}

	/**
	 * Shape a term row for the picker.
	 *
	 * @since 1.0.0
	 *
	 * @param Term $term The term to present.
	 * @return array<string, mixed> The response representation.
	 */
	private function presentTerm( Term $term ): array {
		return array(
			'id'   => (int) $term->id(),
			'slug' => $term->slug(),
			'name' => $term->name(),
		);
	}

	/**
	 * Shape a page row for the picker.
	 *
	 * Use the stored title, not filtered display text.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Post $page The page.
	 * @return array<string, mixed> The response representation.
	 */
	private function presentPage( WP_Post $page ): array {
		return array(
			'id'    => (int) $page->ID,
			'title' => $page->post_title,
		);
	}

	/**
	 * The `args` schema for the product search.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>> The parameter schema.
	 */
	private function productArgs(): array {
		return array(
			'search'   => array(
				'description'       => __( 'Match published products whose name, slug, overview, or description contains this text.', 'tiermint' ),
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'include'  => array(
				'description'       => __( 'Resolve these product ids to their names, ignoring the search term. Used to label saved selections.', 'tiermint' ),
				'type'              => 'array',
				'items'             => array( 'type' => 'integer' ),
				'sanitize_callback' => 'wp_parse_id_list',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'per_page' => array(
				'description'       => __( 'Maximum number of products to return.', 'tiermint' ),
				'type'              => 'integer',
				'default'           => self::DEFAULT_PER_PAGE,
				'minimum'           => 1,
				'maximum'           => self::MAX_PER_PAGE,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * Return the page-search schema.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>> The parameter schema.
	 */
	private function pageArgs(): array {
		return array(
			'search'   => array(
				'description'       => __( 'Match published pages whose title contains this text.', 'tiermint' ),
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'include'  => array(
				'description'       => __( 'Resolve these page ids to their titles, ignoring the search term. Used to label saved selections.', 'tiermint' ),
				'type'              => 'array',
				'items'             => array( 'type' => 'integer' ),
				'sanitize_callback' => 'wp_parse_id_list',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'per_page' => array(
				'description'       => __( 'Maximum number of pages to return.', 'tiermint' ),
				'type'              => 'integer',
				'default'           => self::DEFAULT_PER_PAGE,
				'minimum'           => 1,
				'maximum'           => self::MAX_PER_PAGE,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * Return the required taxonomy schema.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>> The parameter schema.
	 */
	private function termArgs(): array {
		return array(
			'taxonomy' => array(
				'description'       => __( 'Which taxonomy to list.', 'tiermint' ),
				'type'              => 'string',
				'required'          => true,
				'enum'              => array( ProductTaxonomy::Category->value, ProductTaxonomy::Tag->value ),
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}
}
