<?php
/**
 * REST controller for the Discounts management resource.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\REST;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Discounts\Discount;
use DrPlugin\WP\TierMint\Discounts\DiscountNotFoundException;
use DrPlugin\WP\TierMint\Discounts\DiscountPersistenceException;
use DrPlugin\WP\TierMint\Discounts\DiscountService;
use DrPlugin\WP\TierMint\Discounts\DiscountValidationException;
use DrPlugin\WP\TierMint\Discounts\DuplicateDiscountCodeException;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST routes for discount management.
 *
 * The service owns discount rules. Deleted discounts do not change order history.
 *
 * @since 1.0.0
 */
final class DiscountsController extends AbstractRestController {

	/**
	 * Write fields sent to the service.
	 *
	 * @var array<int, string>
	 */
	private const WRITABLE_FIELDS = array(
		'code',
		'name',
		'type',
		'amount_percent',
		'amount_fixed',
		'auto',
		'scope_type',
		'product_ids',
		'category_ids',
		'min_total',
		'max_uses',
		'once_per_customer',
		'starts_at',
		'expires_at',
		'status',
	);

	/**
	 * Bulk actions and their target statuses.
	 *
	 * @var array<string, string|null>
	 */
	private const BATCH_ACTIONS = array(
		'activate'   => 'active',
		'deactivate' => 'inactive',
		'delete'     => null,
	);

	/**
	 * The discounts domain service this controller delegates to.
	 *
	 * @var DiscountService
	 */
	private DiscountService $discounts;

	/**
	 * Set the discount service.
	 *
	 * @since 1.0.0
	 *
	 * @param DiscountService $discounts The discounts domain service.
	 */
	public function __construct( DiscountService $discounts ) {
		$this->discounts = $discounts;
	}

	/**
	 * Register the Discounts collection and single-item routes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			'/discounts',
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
			'/discounts/(?P<id>\d+)',
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
			'/discounts/batch',
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
	 * List discounts with pagination headers.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error The collection response, or an error envelope.
	 */
	public function index( WP_REST_Request $request ) {
		$perPage = (int) $request->get_param( 'per_page' );

		$args = array(
			'page'     => (int) $request->get_param( 'page' ),
			'per_page' => $perPage,
			'status'   => $request->get_param( 'status' ),
			'search'   => $request->get_param( 'search' ),
			'orderby'  => $request->get_param( 'orderby' ),
			'order'    => $request->get_param( 'order' ),
		);

		try {
			$discounts = $this->discounts->list( $args );
			$total     = $this->discounts->count( $args );
		} catch ( DiscountPersistenceException $e ) {
			return $this->serverError();
		}

		$data     = array_map( array( $this, 'presentDiscount' ), $discounts );
		$response = new WP_REST_Response( $data, 200 );

		return $this->withPagination( $response, $total, $perPage );
	}

	/**
	 * Create a discount.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error The created discount, or an error envelope.
	 */
	public function create( WP_REST_Request $request ) {
		try {
			$discount = $this->discounts->create( $this->payload( $request ) );
		} catch ( Throwable $e ) {
			return $this->toErrorResponse( $e );
		}

		return new WP_REST_Response( $this->presentDiscount( $discount ), 201 );
	}

	/**
	 * Get one discount.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error The discount, or an error envelope.
	 */
	public function show( WP_REST_Request $request ) {
		try {
			$discount = $this->discounts->get( (int) $request->get_param( 'id' ) );
		} catch ( Throwable $e ) {
			return $this->toErrorResponse( $e );
		}

		return new WP_REST_Response( $this->presentDiscount( $discount ), 200 );
	}

	/**
	 * Update one discount.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error The updated discount, or an error envelope.
	 */
	public function update( WP_REST_Request $request ) {
		try {
			$discount = $this->discounts->update( (int) $request->get_param( 'id' ), $this->payload( $request ) );
		} catch ( Throwable $e ) {
			return $this->toErrorResponse( $e );
		}

		return new WP_REST_Response( $this->presentDiscount( $discount ), 200 );
	}

	/**
	 * Delete one discount.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error The deletion result, or an error envelope.
	 */
	public function delete( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		try {
			$previous = $this->presentDiscount( $this->discounts->get( $id ) );

			$this->discounts->delete( $id );
		} catch ( Throwable $e ) {
			return $this->toErrorResponse( $e );
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
	 * Apply one allowed action to a discount batch.
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
	 * @param int    $id     The discount id.
	 * @param string $action The bulk action, a key of {@see BATCH_ACTIONS}.
	 * @return WP_Error|null The failure envelope, or null when the action landed.
	 */
	private function applyBatchAction( int $id, string $action ): ?WP_Error {
		if ( ! array_key_exists( $action, self::BATCH_ACTIONS ) ) {
			// Keep a stale route schema from reaching the delete branch.
			return $this->invalidParams( array( __( 'That bulk action is not available for discounts.', 'tiermint' ) ) );
		}

		$status = self::BATCH_ACTIONS[ $action ];

		try {
			if ( 'delete' === $action ) {
				$this->discounts->delete( $id );
			} else {
				$this->discounts->update( $id, array( 'status' => $status ) );
			}
		} catch ( Throwable $e ) {
			return $this->toErrorResponse( $e );
		}

		return null;
	}

	/**
	 * Map a domain exception to an error envelope.
	 *
	 * Check duplicate codes before their validation parent.
	 *
	 * @since 1.0.0
	 *
	 * @param Throwable $e The caught exception.
	 * @return WP_Error The matching error envelope.
	 */
	private function toErrorResponse( Throwable $e ): WP_Error {
		if ( $e instanceof DiscountNotFoundException ) {
			return $this->notFound();
		}

		if ( $e instanceof DuplicateDiscountCodeException ) {
			$errors = $e->getErrors();

			return $this->error(
				'tiermint_duplicate_code',
				array() === $errors ? $e->getMessage() : implode( ' ', $errors ),
				400,
				array( 'errors' => array_values( $errors ) )
			);
		}

		if ( $e instanceof DiscountValidationException ) {
			$errors = $e->getErrors();

			return $this->invalidParams( array() === $errors ? array( $e->getMessage() ) : $errors );
		}

		// Do not expose persistence details.
		return $this->serverError();
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
	 * Shape a discount for JSON.
	 *
	 * @since 1.0.0
	 *
	 * @param Discount $discount The discount to present.
	 * @return array<string, mixed> The response representation.
	 */
	private function presentDiscount( Discount $discount ): array {
		return array(
			'id'                => (int) $discount->id(),
			'code'              => $discount->code(),
			'name'              => $discount->name(),
			'type'              => $discount->type()->value,
			'amount_percent'    => $discount->amountPercent(),
			'amount_fixed'      => $discount->amountFixed(),
			'auto'              => $discount->isAuto(),
			'scope_type'        => $discount->scopeType()->value,
			'product_ids'       => $discount->productIds(),
			'category_ids'      => $discount->categoryIds(),
			'min_total'         => $discount->minTotal(),
			'max_uses'          => $discount->maxUses(),
			'use_count'         => $discount->useCount(),
			'once_per_customer' => $discount->isOncePerCustomer(),
			'starts_at'         => $discount->startsAt(),
			'expires_at'        => $discount->expiresAt(),
			'status'            => $discount->status()->value,
			'created_at'        => $discount->createdAt(),
			'updated_at'        => $discount->updatedAt(),
		);
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
			'page'     => array(
				'description'       => __( 'Current page of the collection.', 'tiermint' ),
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'per_page' => array(
				'description'       => __( 'Maximum number of items to return per page.', 'tiermint' ),
				'type'              => 'integer',
				'default'           => 10,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'status'   => array(
				'description'       => __( 'Limit results to a discount status.', 'tiermint' ),
				'type'              => 'string',
				'enum'              => array( 'active', 'inactive' ),
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'search'   => array(
				'description'       => __( 'Limit results to those matching a code or name.', 'tiermint' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'orderby'  => array(
				'description'       => __( 'Sort collection by discount attribute.', 'tiermint' ),
				'type'              => 'string',
				'enum'              => array( 'id', 'code', 'created_at' ),
				'default'           => 'created_at',
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'order'    => array(
				'description'       => __( 'Order sort attribute ascending or descending.', 'tiermint' ),
				'type'              => 'string',
				'enum'              => array( 'asc', 'desc' ),
				'default'           => 'desc',
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * Return the create and update schema.
	 *
	 * The service reports missing business fields. Orders maintain the use count.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>> The parameter schema.
	 */
	private function writeArgs(): array {
		return array(
			'code'              => array(
				'description'       => __( 'The discount code the buyer types.', 'tiermint' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'name'              => array(
				'description'       => __( 'An optional admin label for the discount.', 'tiermint' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'type'              => array(
				'description'       => __( 'How the reduction is computed.', 'tiermint' ),
				'type'              => 'string',
				'enum'              => array( 'percent', 'fixed' ),
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'amount_percent'    => array(
				'description'       => __( 'The percentage off in (0, 100] for a percent discount.', 'tiermint' ),
				'type'              => 'number',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'amount_fixed'      => array(
				'description'       => __( 'The flat reduction in minor units for a fixed discount.', 'tiermint' ),
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'auto'              => array(
				'description'       => __( 'Whether the discount applies automatically, with no code the shopper types.', 'tiermint' ),
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'scope_type'        => array(
				'description'       => __( 'What an automatic discount is scoped to: all products, chosen products, or chosen categories.', 'tiermint' ),
				'type'              => 'string',
				'enum'              => array( 'all', 'products', 'categories' ),
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'product_ids'       => array(
				'description'       => __( 'Product ids the discount is scoped to (omit or empty for all products).', 'tiermint' ),
				'type'              => 'array',
				'items'             => array( 'type' => 'integer' ),
				'validate_callback' => 'rest_validate_request_arg',
			),
			'category_ids'      => array(
				'description'       => __( 'Category term ids an automatic categories-scoped discount is scoped to (omit or empty for none).', 'tiermint' ),
				'type'              => 'array',
				'items'             => array( 'type' => 'integer' ),
				'validate_callback' => 'rest_validate_request_arg',
			),
			'min_total'         => array(
				'description'       => __( 'The minimum order subtotal in minor units the discount requires.', 'tiermint' ),
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'max_uses'          => array(
				'description'       => __( 'The total number of times the discount may be used.', 'tiermint' ),
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'once_per_customer' => array(
				'description'       => __( 'Whether a customer may redeem the discount only once.', 'tiermint' ),
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'starts_at'         => array(
				'description'       => __( 'The UTC date and time the discount becomes valid (Y-m-d H:i:s).', 'tiermint' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'expires_at'        => array(
				'description'       => __( 'The UTC date and time the discount stops being valid (Y-m-d H:i:s).', 'tiermint' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'status'            => array(
				'description'       => __( 'Whether the discount is switched on or off.', 'tiermint' ),
				'type'              => 'string',
				'enum'              => array( 'active', 'inactive' ),
				'sanitize_callback' => 'sanitize_key',
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
				'description'       => __( 'Unique discount identifier.', 'tiermint' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}
}
