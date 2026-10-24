<?php
/**
 * REST controller for the Products management resource.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\REST;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Frontend\ProductUrlProvider;
use DrPlugin\WP\TierMint\Products\BundleItem;
use DrPlugin\WP\TierMint\Products\Fields\ProductFieldRegistry;
use DrPlugin\WP\TierMint\Products\Product;
use DrPlugin\WP\TierMint\Products\ProductException;
use DrPlugin\WP\TierMint\Products\ProductNotFoundException;
use DrPlugin\WP\TierMint\Products\ProductPersistenceException;
use DrPlugin\WP\TierMint\Products\ProductPrice;
use DrPlugin\WP\TierMint\Products\ProductService;
use DrPlugin\WP\TierMint\Products\ProductTaxonomy;
use DrPlugin\WP\TierMint\Products\ProductValidationException;
use DrPlugin\WP\TierMint\Products\Term;
use DrPlugin\WP\TierMint\Products\TermService;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST routes for product management.
 *
 * The service owns product rules. Route schemas validate request input.
 *
 * @since 1.0.0
 */
final class ProductsController extends AbstractRestController {

	/**
	 * Write fields sent to the service.
	 *
	 * @var array<int, string>
	 */
	private const WRITABLE_FIELDS = array(
		'name',
		'slug',
		'status',
		'type',
		'overview',
		'description',
		'is_licensed',
		'update_slug',
		'files',
		'settings',
		'prices',
		'bundle_items',
		'categories',
		'tags',
	);

	/**
	 * Bulk actions and their target statuses.
	 *
	 * @var array<string, string|null>
	 */
	private const BATCH_ACTIONS = array(
		'publish'   => 'published',
		'unpublish' => 'draft',
		'delete'    => null,
	);

	/**
	 * The products domain service this controller delegates to.
	 *
	 * @var ProductService
	 */
	private ProductService $products;

	/**
	 * The term service used to present a product's assigned categories and tags.
	 *
	 * @var TermService
	 */
	private TermService $terms;

	/**
	 * The registry of product fields, both settings-backed and column-backed.
	 *
	 * @var ProductFieldRegistry
	 */
	private ProductFieldRegistry $fields;

	/**
	 * The storefront URL builder, for the permalink on each product response.
	 *
	 * @var ProductUrlProvider
	 */
	private ProductUrlProvider $urls;

	/**
	 * Set product services and response helpers.
	 *
	 * @since 1.0.0
	 *
	 * @param ProductService       $products The products domain service.
	 * @param TermService          $terms    The term service, for presenting assigned terms.
	 * @param ProductFieldRegistry $fields   The registry of settings- and column-backed product fields.
	 * @param ProductUrlProvider   $urls     The storefront details-page URL builder.
	 */
	public function __construct( ProductService $products, TermService $terms, ProductFieldRegistry $fields, ProductUrlProvider $urls ) {
		$this->products = $products;
		$this->terms    = $terms;
		$this->fields   = $fields;
		$this->urls     = $urls;
	}

	/**
	 * Register the Products collection and single-item routes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			'/products',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'managePermission' ),
					'args'                => $this->collectionArgs(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'managePermission' ),
					'args'                => $this->writeArgs(),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/products/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'show' ),
					'permission_callback' => array( $this, 'managePermission' ),
					'args'                => $this->idArgs(),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'managePermission' ),
					'args'                => array_merge( $this->idArgs(), $this->writeArgs() ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => array( $this, 'managePermission' ),
					'args'                => $this->idArgs(),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/products/(?P<id>\d+)/terms',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'updateTerms' ),
					'permission_callback' => array( $this, 'managePermission' ),
					'args'                => array_merge( $this->idArgs(), $this->termArgs() ),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/products/batch',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'batch' ),
					'permission_callback' => array( $this, 'managePermission' ),
					'args'                => BatchRequest::args( array_keys( self::BATCH_ACTIONS ) ),
				),
			)
		);
	}

	/**
	 * List products with pagination headers.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response The collection response.
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		$perPage = (int) $request->get_param( 'per_page' );

		$args = array(
			'page'             => (int) $request->get_param( 'page' ),
			'per_page'         => $perPage,
			'search'           => $request->get_param( 'search' ),
			'status'           => $request->get_param( 'status' ),
			'orderby'          => $request->get_param( 'orderby' ),
			'order'            => $request->get_param( 'order' ),
			'category'         => $request->get_param( 'category' ),
			'tag'              => $request->get_param( 'tag' ),
			'include_children' => $request->get_param( 'include_children' ),
		);

		$products = $this->products->list( $args );
		$total    = $this->products->count( $args );

		$response = new WP_REST_Response( $this->presentCollection( $products ), 200 );

		return $this->withPagination( $response, $total, $perPage );
	}

	/**
	 * Create a product.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error The created product, or an error envelope.
	 */
	public function create( WP_REST_Request $request ) {
		try {
			$product = $this->products->create( $this->payload( $request ) );
		} catch ( ProductValidationException $e ) {
			return $this->invalidParams( $e->getErrors() );
		} catch ( ProductPersistenceException $e ) {
			// Do not expose database details.
			return $this->serverError( __( 'The product could not be saved. Please try again.', 'tiermint' ) );
		} catch ( ProductException $e ) {
			// Other domain errors are invalid input.
			return $this->invalidParams( array( $e->getMessage() ) );
		}

		return new WP_REST_Response( $this->presentProduct( $product, true ), 201 );
	}

	/**
	 * Get one product.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error The product, or an error envelope.
	 */
	public function show( WP_REST_Request $request ) {
		$product = $this->products->get( (int) $request->get_param( 'id' ) );

		if ( null === $product ) {
			return $this->notFound();
		}

		return new WP_REST_Response( $this->presentProduct( $product, true ), 200 );
	}

	/**
	 * Update one product.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error The updated product, or an error envelope.
	 */
	public function update( WP_REST_Request $request ) {
		try {
			$product = $this->products->update( (int) $request->get_param( 'id' ), $this->payload( $request ) );
		} catch ( ProductNotFoundException $e ) {
			return $this->notFound();
		} catch ( ProductValidationException $e ) {
			return $this->invalidParams( $e->getErrors() );
		} catch ( ProductPersistenceException $e ) {
			// Do not expose database details.
			return $this->serverError( __( 'The product could not be saved. Please try again.', 'tiermint' ) );
		} catch ( ProductException $e ) {
			return $this->invalidParams( array( $e->getMessage() ) );
		}

		return new WP_REST_Response( $this->presentProduct( $product, true ), 200 );
	}

	/**
	 * Delete one product.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error The deletion result, or an error envelope.
	 */
	public function delete( WP_REST_Request $request ) {
		$id      = (int) $request->get_param( 'id' );
		$product = $this->products->get( $id );

		if ( null === $product ) {
			return $this->notFound();
		}

		$previous = $this->presentProduct( $product );

		try {
			$this->products->delete( $id );
		} catch ( ProductNotFoundException $e ) {
			// Lost a race with a concurrent delete; report it as already gone.
			return $this->notFound();
		}

		return new WP_REST_Response(
			array(
				'deleted'  => true,
				'previous' => $previous,
			),
			200
		);
	}

	/**
	 * Update supplied category and tag assignments.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error The updated product, or an error envelope.
	 */
	public function updateTerms( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		// Omitted taxonomies remain unchanged.
		$data = array();

		if ( $request->has_param( 'categories' ) ) {
			$data['categories'] = $request->get_param( 'categories' );
		}

		if ( $request->has_param( 'tags' ) ) {
			$data['tags'] = $request->get_param( 'tags' );
		}

		try {
			$product = $this->products->update( $id, $data );
		} catch ( ProductNotFoundException $e ) {
			return $this->notFound();
		} catch ( ProductValidationException $e ) {
			return $this->invalidParams( $e->getErrors() );
		} catch ( ProductException $e ) {
			return $this->invalidParams( array( $e->getMessage() ) );
		}

		return new WP_REST_Response( $this->presentProduct( $product, true ), 200 );
	}

	/**
	 * Apply one allowed action to a product batch.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response The per-id result.
	 */
	public function batch( WP_REST_Request $request ): WP_REST_Response {
		$action = (string) $request->get_param( 'action' );

		return BatchRequest::run(
			(array) $request->get_param( 'ids' ),
			fn ( int $id ): ?WP_Error => $this->applyBatchAction( $id, $action )
		);
	}

	/**
	 * Apply one bulk action through the normal service path.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $id     The product id.
	 * @param string $action The bulk action, a key of {@see BATCH_ACTIONS}.
	 * @return WP_Error|null The failure envelope, or null when the action landed.
	 */
	private function applyBatchAction( int $id, string $action ): ?WP_Error {
		if ( ! array_key_exists( $action, self::BATCH_ACTIONS ) ) {
			// Keep a stale route schema from reaching the delete branch.
			return $this->invalidParams( array( __( 'That bulk action is not available for products.', 'tiermint' ) ) );
		}

		$status = self::BATCH_ACTIONS[ $action ];

		try {
			if ( 'delete' === $action ) {
				$this->products->delete( $id );
			} else {
				$this->products->update( $id, array( 'status' => $status ) );
			}
		} catch ( ProductNotFoundException $e ) {
			return $this->notFound();
		} catch ( ProductValidationException $e ) {
			return $this->invalidParams( $e->getErrors() );
		} catch ( ProductPersistenceException $e ) {
			return $this->serverError(
				'delete' === $action
					? __( 'The product could not be deleted. Please try again.', 'tiermint' )
					: __( 'The product could not be updated. Please try again.', 'tiermint' )
			);
		} catch ( ProductException $e ) {
			return $this->invalidParams( array( $e->getMessage() ) );
		} catch ( Throwable $e ) {
			// Continue the remaining batch after an unexpected failure.
			return $this->serverError();
		}

		return null;
	}

	/**
	 * Sanitize strings in a nested request value.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value The raw parameter value.
	 * @return mixed The sanitized value, with the same structure.
	 */
	public function sanitizeDeep( $value ) {
		if ( is_array( $value ) ) {
			$sanitized = array();

			foreach ( $value as $key => $item ) {
				$cleanKey               = is_string( $key ) ? sanitize_text_field( $key ) : $key;
				$sanitized[ $cleanKey ] = $this->sanitizeDeep( $item );
			}

			return $sanitized;
		}

		if ( is_string( $value ) ) {
			return sanitize_text_field( $value );
		}

		return $value;
	}

	/**
	 * Sanitize registered settings and preserve unknown keys.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value The raw settings value.
	 * @return mixed The sanitized settings, with the same structure for unknown keys.
	 */
	public function sanitizeSettings( $value ) {
		if ( ! is_array( $value ) ) {
			return $this->sanitizeDeep( $value );
		}

		$clean = array();

		foreach ( $value as $key => $item ) {
			$field = is_string( $key ) ? $this->fields->field( $key ) : null;

			if ( null === $field ) {
				// Keep the existing deep sanitization for extension keys.
				$cleanKey           = is_string( $key ) ? sanitize_text_field( $key ) : $key;
				$clean[ $cleanKey ] = $this->sanitizeDeep( $item );
				continue;
			}

			// Column fields do not belong in the settings blob.
			if ( isset( $field['storage'] ) && 'column' === $field['storage'] ) {
				continue;
			}

			$sanitized = $this->fields->sanitize( $key, $item );

			// A cleared media selection or an empty gallery is stored as absence.
			if ( 'media' === $field['type'] && 0 === $sanitized ) {
				continue;
			}
			if ( 'media_gallery' === $field['type'] && array() === $sanitized ) {
				continue;
			}

			$clean[ $key ] = $sanitized;
		}

		return $clean;
	}

	/**
	 * The `args` schema for the collection query.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>> The parameter schema.
	 */
	private function collectionArgs(): array {
		return array(
			'page'             => array(
				'description'       => __( 'Current page of the collection.', 'tiermint' ),
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'per_page'         => array(
				'description'       => __( 'Maximum number of items to return per page.', 'tiermint' ),
				'type'              => 'integer',
				'default'           => 10,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'search'           => array(
				'description'       => __( 'Limit results to those matching a name, slug, overview, or description.', 'tiermint' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'status'           => array(
				'description'       => __( 'Limit results to a single lifecycle status.', 'tiermint' ),
				'type'              => 'string',
				'enum'              => array( 'draft', 'published', 'archived' ),
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'orderby'          => array(
				'description'       => __( 'Sort collection by product attribute.', 'tiermint' ),
				'type'              => 'string',
				'enum'              => array( 'id', 'name', 'created_at' ),
				'default'           => 'created_at',
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'order'            => array(
				'description'       => __( 'Order sort attribute ascending or descending.', 'tiermint' ),
				'type'              => 'string',
				'enum'              => array( 'asc', 'desc' ),
				'default'           => 'desc',
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'category'         => array(
				'description'       => __( 'Limit results to one or more category slugs (comma-separated).', 'tiermint' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'tag'              => array(
				'description'       => __( 'Limit results to one or more tag slugs (comma-separated).', 'tiermint' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'include_children' => array(
				'description'       => __( 'Whether a category filter also matches descendant categories.', 'tiermint' ),
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * Return the create and update schema.
	 *
	 * The service reports missing required business fields together.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>> The parameter schema.
	 */
	private function writeArgs(): array {
		return array(
			'name'         => array(
				'description'       => __( 'Product display name.', 'tiermint' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'slug'         => array(
				'description'       => __( 'Product URL slug.', 'tiermint' ),
				'type'              => 'string',
				// REST passes extra callback arguments, so wrap sanitize_title().
				'sanitize_callback' => static fn ( $value ): string => sanitize_title( (string) $value ),
				'validate_callback' => 'rest_validate_request_arg',
			),
			'status'       => array(
				'description'       => __( 'Product lifecycle status.', 'tiermint' ),
				'type'              => 'string',
				'enum'              => array( 'draft', 'published', 'archived' ),
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'type'         => array(
				'description'       => __( 'Product type.', 'tiermint' ),
				'type'              => 'string',
				'enum'              => array( 'digital', 'bundle' ),
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'overview'     => array(
				'description'       => __( 'Short rich-text overview.', 'tiermint' ),
				'type'              => 'string',
				// Keep rich-text sanitization when an extension removes the field.
				'sanitize_callback' => static fn ( $value ): string => wp_kses_post( is_string( $value ) ? $value : '' ),
				'validate_callback' => 'rest_validate_request_arg',
			),
			'description'  => array(
				'description'       => __( 'Long rich-text product description.', 'tiermint' ),
				'type'              => 'string',
				// Description uses the rich-text sanitizer.
				'sanitize_callback' => static fn ( $value ): string => wp_kses_post( is_string( $value ) ? $value : '' ),
				'validate_callback' => 'rest_validate_request_arg',
			),
			'is_licensed'  => array(
				'description'       => __( 'Whether a purchase mints a license.', 'tiermint' ),
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'update_slug'  => array(
				'description'       => __( 'Slug clients send to the update-check endpoint.', 'tiermint' ),
				'type'              => 'string',
				// REST passes extra callback arguments, so wrap sanitize_title().
				'sanitize_callback' => static fn ( $value ): string => sanitize_title( (string) $value ),
				'validate_callback' => 'rest_validate_request_arg',
			),
			'files'        => array(
				'description'       => __( 'Downloadable file references (storage pointers).', 'tiermint' ),
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitizeDeep' ),
				'validate_callback' => 'rest_validate_request_arg',
			),
			'settings'     => array(
				'description'       => __( 'Per-product settings blob.', 'tiermint' ),
				'type'              => 'object',
				'sanitize_callback' => array( $this, 'sanitizeSettings' ),
				'validate_callback' => 'rest_validate_request_arg',
			),
			'prices'       => array(
				'description'       => __( 'Product price tiers.', 'tiermint' ),
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitizeDeep' ),
				'validate_callback' => 'rest_validate_request_arg',
			),
			'bundle_items' => array(
				'description'       => __( 'Bundle composition (child products).', 'tiermint' ),
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitizeDeep' ),
				'validate_callback' => 'rest_validate_request_arg',
			),
		) + $this->termArgs();
	}

	/**
	 * Return category and tag identifiers for product writes.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>> The parameter schema.
	 */
	private function termArgs(): array {
		return array(
			'categories' => array(
				'description'       => __( 'Category term ids to assign to the product.', 'tiermint' ),
				'type'              => 'array',
				'items'             => array( 'type' => 'integer' ),
				'sanitize_callback' => array( $this, 'sanitizeDeep' ),
				'validate_callback' => 'rest_validate_request_arg',
			),
			'tags'       => array(
				'description'       => __( 'Tag term ids to assign to the product.', 'tiermint' ),
				'type'              => 'array',
				'items'             => array( 'type' => 'integer' ),
				'sanitize_callback' => array( $this, 'sanitizeDeep' ),
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * The `args` schema for the `{id}` path parameter.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>> The parameter schema.
	 */
	private function idArgs(): array {
		return array(
			'id' => array(
				'description'       => __( 'Unique product identifier.', 'tiermint' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * Collect fields sent by the client.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return array<string, mixed> The service input array.
	 */
	private function payload( WP_REST_Request $request ): array {
		$data = array();

		foreach ( self::WRITABLE_FIELDS as $field ) {
			if ( $request->has_param( $field ) ) {
				$data[ $field ] = $request->get_param( $field );
			}
		}

		return $data;
	}

	/**
	 * Shape a product page with category chips.
	 *
	 * Categories load in one batch. Tags are not needed in a collection.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, Product> $products The products on this page.
	 * @return array<int, array<string, mixed>> The response representations.
	 */
	private function presentCollection( array $products ): array {
		$assigned = $this->terms->termsForProducts(
			array_map( static fn ( Product $product ): int => (int) $product->id(), $products ),
			ProductTaxonomy::Category
		);

		return array_map(
			function ( Product $product ) use ( $assigned ): array {
				$data               = $this->presentProduct( $product );
				$data['categories'] = array_map(
					array( $this, 'presentTermChip' ),
					$assigned[ (int) $product->id() ] ?? array()
				);

				return $data;
			},
			$products
		);
	}

	/**
	 * Shape a product for JSON.
	 *
	 * Include terms only when requested. A missing slug has no permalink.
	 *
	 * @since 1.0.0
	 *
	 * @param Product $product   The product to present.
	 * @param bool    $withTerms Whether to embed the assigned categories and tags.
	 * @return array<string, mixed> The response representation.
	 */
	private function presentProduct( Product $product, bool $withTerms = false ): array {
		$data = array(
			'id'           => (int) $product->id(),
			'name'         => $product->name(),
			'slug'         => $product->slug(),
			'permalink'    => '' === $product->slug() ? null : $this->urls->url( $product ),
			'status'       => $product->status()->value,
			'type'         => $product->type()->value,
			'overview'     => $product->overview(),
			'description'  => $product->description(),
			'is_licensed'  => $product->isLicensed(),
			'update_slug'  => $product->updateSlug(),
			'files'        => $product->files(),
			'settings'     => (object) $product->settings(),
			'prices'       => array_map( fn ( ProductPrice $price ): array => $this->presentPrice( $price, $product ), $product->prices() ),
			'bundle_items' => array_map( array( $this, 'presentBundleItem' ), $product->bundleItems() ),
			'created_at'   => $product->createdAt(),
			'updated_at'   => $product->updatedAt(),
		);

		if ( $withTerms ) {
			$productId          = (int) $product->id();
			$data['categories'] = array_map(
				array( $this, 'presentProductTerm' ),
				$this->terms->termsForProduct( $productId, ProductTaxonomy::Category )
			);
			$data['tags']       = array_map(
				array( $this, 'presentProductTerm' ),
				$this->terms->termsForProduct( $productId, ProductTaxonomy::Tag )
			);
		}

		return $data;
	}

	/**
	 * Shape an assigned term with its parent.
	 *
	 * @since 1.0.0
	 *
	 * @param Term $term The assigned term to present.
	 * @return array<string, mixed> The response representation.
	 */
	private function presentProductTerm( Term $term ): array {
		$data = $this->presentTermChip( $term );

		$data['parent'] = $term->parent();

		return $data;
	}

	/**
	 * Shape a term chip.
	 *
	 * @since 1.0.0
	 *
	 * @param Term $term The assigned term to present.
	 * @return array<string, mixed> The response representation.
	 */
	private function presentTermChip( Term $term ): array {
		return array(
			'id'   => (int) $term->id(),
			'name' => $term->name(),
			'slug' => $term->slug(),
		);
	}

	/**
	 * Shape a price tier for the JSON response.
	 *
	 * @since 1.0.0
	 *
	 * @param ProductPrice $price   The price tier to present.
	 * @param Product      $product The product the tier belongs to.
	 * @return array<string, mixed> The response representation.
	 */
	private function presentPrice( ProductPrice $price, Product $product ): array {
		$data = array(
			'id'               => null === $price->id() ? null : (int) $price->id(),
			'product_id'       => null === $price->productId() ? null : (int) $price->productId(),
			'name'             => $price->name(),
			'amount'           => $price->amount(),
			'activation_limit' => $price->activationLimit(),
			'license_length'   => $price->licenseLength(),
			'sort_order'       => $price->sortOrder(),
			'min_quantity'     => $price->minQuantity(),
			'max_quantity'     => $price->maxQuantity(),
			'status'           => $price->status()->value,
		);

		/**
		 * Filters a price tier in a products REST response.
		 *
		 * An extension that stores its own data per tier adds it here. A return
		 * that is not an array keeps core's data.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $data    The tier data.
		 * @param ProductPrice         $price   The tier.
		 * @param Product              $product The product the tier belongs to.
		 */
		$filtered = apply_filters( 'tiermint_rest_product_price', $data, $price, $product );

		return is_array( $filtered ) ? $filtered : $data;
	}

	/**
	 * Shape a bundle item for the JSON response.
	 *
	 * @since 1.0.0
	 *
	 * @param BundleItem $item The bundle item to present.
	 * @return array<string, mixed> The response representation.
	 */
	private function presentBundleItem( BundleItem $item ): array {
		return array(
			'bundle_product_id' => null === $item->bundleProductId() ? null : (int) $item->bundleProductId(),
			'child_product_id'  => $item->childProductId(),
			'child_price_id'    => $item->childPriceId(),
			'sort_order'        => $item->sortOrder(),
		);
	}
}
