<?php
/**
 * REST controller for the Licenses management resource.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\REST;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DateTimeImmutable;
use DateTimeZone;
use DrPlugin\WP\TierMint\Customers\Customer;
use DrPlugin\WP\TierMint\Customers\CustomerRepository;
use DrPlugin\WP\TierMint\Licensing\Activation;
use DrPlugin\WP\TierMint\Licensing\ActivationNotFoundException;
use DrPlugin\WP\TierMint\Licensing\ActivationRepository;
use DrPlugin\WP\TierMint\Licensing\ActivationService;
use DrPlugin\WP\TierMint\Licensing\License;
use DrPlugin\WP\TierMint\Licensing\LicenseNotFoundException;
use DrPlugin\WP\TierMint\Licensing\LicensePersistenceException;
use DrPlugin\WP\TierMint\Licensing\LicenseRepository;
use DrPlugin\WP\TierMint\Licensing\LicenseService;
use DrPlugin\WP\TierMint\Licensing\LicenseStatus;
use DrPlugin\WP\TierMint\Licensing\LicenseTransitionException;
use DrPlugin\WP\TierMint\Licensing\LicenseValidationException;
use DrPlugin\WP\TierMint\Products\ProductRepository;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Admin REST controller for license management.
 *
 * Lists mask keys. Detail responses show full keys for support work.
 *
 * @since 1.0.0
 */
final class LicensesController extends AbstractRestController {

	/**
	 * Lifecycle actions for single and batch routes.
	 *
	 * @var array<int, string>
	 */
	private const LIFECYCLE_ACTIONS = array( 'disable', 'enable' );

	/**
	 * The license repository (collection query, by-id detail read).
	 *
	 * @var LicenseRepository
	 */
	private LicenseRepository $licenses;

	/**
	 * The licensing service that owns the disable/enable transitions.
	 *
	 * @var LicenseService
	 */
	private LicenseService $licenseService;

	/**
	 * The activation repository (by-id lookup for the admin deactivation).
	 *
	 * @var ActivationRepository
	 */
	private ActivationRepository $activationRepo;

	/**
	 * The activation service that owns the slot-freeing deactivation (compare-and-swap semantics).
	 *
	 * @var ActivationService
	 */
	private ActivationService $activationService;

	/**
	 * The customer directory (search-by-email resolution, detail owner email).
	 *
	 * @var CustomerRepository
	 */
	private CustomerRepository $customers;

	/**
	 * The product catalog, for the names the collection shows in place of product ids.
	 *
	 * @var ProductRepository
	 */
	private ProductRepository $products;

	/**
	 * Wire the controller with the licensing reads and the activation service.
	 *
	 * @since 1.0.0
	 *
	 * @param LicenseRepository    $licenses          The license repository.
	 * @param ActivationRepository $activationRepo    The activation repository.
	 * @param ActivationService    $activationService The activation domain service.
	 * @param CustomerRepository   $customers         The customer repository.
	 * @param ProductRepository    $products          The product repository.
	 * @param LicenseService       $licenseService    The licensing domain service.
	 */
	public function __construct(
		LicenseRepository $licenses,
		ActivationRepository $activationRepo,
		ActivationService $activationService,
		CustomerRepository $customers,
		ProductRepository $products,
		LicenseService $licenseService
	) {
		$this->licenses          = $licenses;
		$this->activationRepo    = $activationRepo;
		$this->activationService = $activationService;
		$this->customers         = $customers;
		$this->products          = $products;
		$this->licenseService    = $licenseService;
	}

	/**
	 * Register the collection, single-item, and activation-deactivation routes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			'/licenses',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'managePermission' ),
					'args'                => $this->collectionArgs(),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/licenses/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'show' ),
					'permission_callback' => array( $this, 'managePermission' ),
					'args'                => $this->idArgs(),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/licenses/(?P<id>\d+)/activations/(?P<activation_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'deleteActivation' ),
					'permission_callback' => array( $this, 'managePermission' ),
					'args'                => $this->activationIdArgs(),
				),
			)
		);

		foreach ( self::LIFECYCLE_ACTIONS as $action ) {
			register_rest_route(
				self::API_NAMESPACE,
				'/licenses/(?P<id>\d+)/' . $action,
				array(
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => fn ( WP_REST_Request $request ) => $this->lifecycle( $request, $action ),
						'permission_callback' => array( $this, 'managePermission' ),
						'args'                => $this->idArgs(),
					),
				)
			);
		}

		register_rest_route(
			self::API_NAMESPACE,
			'/licenses/batch',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'batch' ),
					'permission_callback' => array( $this, 'managePermission' ),
					'args'                => BatchRequest::args( self::LIFECYCLE_ACTIONS ),
				),
			)
		);
	}

	/**
	 * GET /licenses: a paginated, filterable collection with masked keys and the
	 * `X-WP-Total` / `X-WP-TotalPages` headers.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error The collection response, or an error envelope.
	 */
	public function index( WP_REST_Request $request ) {
		$perPage = (int) $request->get_param( 'per_page' );

		$args = array(
			'page'        => (int) $request->get_param( 'page' ),
			'per_page'    => $perPage,
			'status'      => $request->get_param( 'status' ),
			'product_id'  => (int) $request->get_param( 'product_id' ),
			'customer_id' => (int) $request->get_param( 'customer_id' ),
			'orderby'     => $request->get_param( 'orderby' ),
			'order'       => $request->get_param( 'order' ),
		);

		$args = $this->applySearch( $args, (string) $request->get_param( 'search' ) );

		try {
			$licenses = $this->licenses->query( $args );
			$total    = $this->licenses->countQuery( $args );
		} catch ( Throwable $e ) {
			return $this->toErrorResponse( $e );
		}

		try {
			$data = $this->presentCollection( $licenses );
		} catch ( Throwable $e ) {
			return $this->toErrorResponse( $e );
		}

		$response = new WP_REST_Response( $data, 200 );

		return $this->withPagination( $response, $total, $perPage );
	}

	/**
	 * Build collection data with batched product and customer names.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, License> $licenses The licenses on this page.
	 * @return array<int, array<string, mixed>> The masked summary representations.
	 */
	private function presentCollection( array $licenses ): array {
		$names  = $this->products->namesFor(
			array_map( static fn ( License $license ): int => (int) $license->productId(), $licenses )
		);
		$owners = $this->customers->findMany(
			array_map( static fn ( License $license ): int => (int) $license->customerId(), $licenses )
		);

		return array_map(
			function ( License $license ) use ( $names, $owners ): array {
				$owner = $owners[ (int) $license->customerId() ] ?? null;
				$data  = $this->presentSummary( $license );

				$data['product_name']   = $names[ (int) $license->productId() ] ?? null;
				$data['customer_email'] = $owner instanceof Customer ? $owner->email() : null;

				return $data;
			},
			$licenses
		);
	}

	/**
	 * GET /licenses/{id}: one license with its full key and activations, or the 404 envelope.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error The license detail, or an error envelope.
	 */
	public function show( WP_REST_Request $request ) {
		try {
			$license = $this->licenses->find( (int) $request->get_param( 'id' ) );
		} catch ( Throwable $e ) {
			return $this->toErrorResponse( $e );
		}

		if ( null === $license ) {
			return $this->notFound();
		}

		return $this->detailResponse( $license );
	}

	/**
	 * Deactivate one license activation and return refreshed detail.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error The refreshed license detail, or an error envelope.
	 */
	public function deleteActivation( WP_REST_Request $request ) {
		$licenseId    = (int) $request->get_param( 'id' );
		$activationId = (int) $request->get_param( 'activation_id' );

		try {
			$license = $this->licenses->find( $licenseId );

			if ( null === $license ) {
				return $this->notFound();
			}

			$activation = $this->activationRepo->find( $activationId );
		} catch ( Throwable $e ) {
			return $this->toErrorResponse( $e );
		}

		// Not found, or found under a different license: one indistinguishable 404 so an admin
		// cannot probe which activation ids exist on licenses they are not looking at.
		if ( null === $activation || $activation->licenseId() !== $licenseId ) {
			return $this->notFound( __( 'The requested activation was not found for this license.', 'tiermint' ) );
		}

		// Already deactivated: idempotent success with the current state, mirroring the public
		// deactivate route's repeat semantics. No second slot release, no hook.
		if ( ! $activation->isActive() ) {
			return $this->detailResponse( $license );
		}

		try {
			// The stored site URL is the already-normalized form the activation was created
			// with, so re-normalizing it reproduces the same site hash: the service resolves
			// the very row we just verified and runs its compare-and-swap slot release once.
			$this->activationService->deactivate( $license, $activation->siteUrl(), $activation->fingerprint() );
		} catch ( Throwable $e ) {
			return $this->toErrorResponse( $e );
		}

		return $this->detailResponse( $license );
	}

	/**
	 * Enable or disable a license and return refreshed detail.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @param string          $action  The lifecycle action, one of {@see LIFECYCLE_ACTIONS}.
	 * @return WP_REST_Response|WP_Error The refreshed license detail, or an error envelope.
	 */
	public function lifecycle( WP_REST_Request $request, string $action ) {
		$result = $this->applyLifecycle( (int) $request->get_param( 'id' ), $action );

		return $result instanceof WP_Error ? $result : $this->detailResponse( $result );
	}

	/**
	 * POST /licenses/batch: disable or enable up to {@see BatchRequest::MAX_IDS} licenses,
	 * reporting each id's outcome on its own.
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
			function ( int $id ) use ( $action ): ?WP_Error {
				$result = $this->applyLifecycle( $id, $action );

				return $result instanceof WP_Error ? $result : null;
			}
		);
	}

	/**
	 * Run one license lifecycle action.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $id     The license id.
	 * @param string $action The lifecycle action, one of {@see LIFECYCLE_ACTIONS}.
	 * @return License|WP_Error The license as it now stands, or the failure envelope.
	 */
	private function applyLifecycle( int $id, string $action ) {
		try {
			$license = $this->licenses->find( $id );

			if ( null === $license ) {
				return $this->notFound();
			}

			return 'disable' === $action
				? $this->licenseService->disable( $license )
				: $this->licenseService->enable( $license );
		} catch ( Throwable $e ) {
			return $this->toErrorResponse( $e );
		}
	}

	/**
	 * Add key or customer-email search criteria to query arguments.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $args   The query args built so far.
	 * @param string               $search The raw search term.
	 * @return array<string, mixed> The query args, with search resolution folded in.
	 */
	private function applySearch( array $args, string $search ): array {
		$search = trim( $search );

		if ( '' === $search ) {
			return $args;
		}

		$args['search'] = $search;

		$customer = $this->customers->findByEmail( $search );

		if ( null !== $customer && null !== $customer->id() ) {
			$args['search_customer_id'] = (int) $customer->id();
		}

		return $args;
	}

	/**
	 * Build a full license detail response.
	 *
	 * @since 1.0.0
	 *
	 * @param License $license The license to present.
	 * @return WP_REST_Response|WP_Error The detail response, or an error envelope.
	 */
	private function detailResponse( License $license ) {
		try {
			// Reload so the detail reflects any slot change this request just made; fall back
			// to the entity in hand if the row vanished under us.
			$fresh  = $this->licenses->find( (int) $license->id() ) ?? $license;
			$detail = $this->presentDetail( $fresh );
		} catch ( Throwable $e ) {
			return $this->toErrorResponse( $e );
		}

		return new WP_REST_Response( $detail, 200 );
	}

	/**
	 * Map a caught exception to the correct error envelope.
	 *
	 * @since 1.0.0
	 *
	 * @param Throwable $e The caught exception.
	 * @return WP_Error The matching error envelope.
	 */
	private function toErrorResponse( Throwable $e ): WP_Error {
		if ( $e instanceof LicenseNotFoundException ) {
			return $this->notFound();
		}

		if ( $e instanceof ActivationNotFoundException ) {
			return $this->notFound( __( 'The requested activation was not found for this license.', 'tiermint' ) );
		}

		if ( $e instanceof LicenseValidationException ) {
			return $this->invalidParams( $e->getErrors() );
		}

		if ( $e instanceof LicenseTransitionException ) {
			// Another writer moved the license while this request was deciding. The state
			// the caller acted on is stale, not wrong, so it reads as a conflict.
			return $this->error(
				'tiermint_license_conflict',
				__( 'The license changed state while this request was running. Reload and try again.', 'tiermint' ),
				409
			);
		}

		// LicensePersistenceException and anything else are server faults: a generic 500 that
		// never leaks internal detail (table names, SQL).
		return $this->serverError();
	}

	/**
	 * Build a collection row with a masked key.
	 *
	 * @since 1.0.0
	 *
	 * @param License $license The license to present.
	 * @return array<string, mixed> The masked summary representation.
	 */
	private function presentSummary( License $license ): array {
		$summary                = $this->baseFields( $license );
		$summary['license_key'] = $this->maskKey( $license->licenseKey() );

		return $summary;
	}

	/**
	 * Build an admin license detail response.
	 *
	 * @since 1.0.0
	 *
	 * @param License $license The license to present.
	 * @return array<string, mixed> The detail representation.
	 *
	 * @throws LicensePersistenceException When the activation read faults at the database.
	 */
	private function presentDetail( License $license ): array {
		$detail                   = $this->baseFields( $license );
		$detail['license_key']    = $license->licenseKey();
		$detail['customer_email'] = $this->ownerEmail( $license );
		$detail['activations']    = array_map(
			array( $this, 'presentActivation' ),
			$this->activationService->listForLicense( $license )
		);

		return $detail;
	}

	/**
	 * Build license fields shared by summary and detail.
	 *
	 * @since 1.0.0
	 *
	 * @param License $license The license to present.
	 * @return array<string, mixed> The shared fields.
	 */
	private function baseFields( License $license ): array {
		return array(
			'id'               => (int) $license->id(),
			'status'           => $license->status()->value,
			'product_id'       => $license->productId(),
			'price_id'         => $license->priceId(),
			'order_id'         => $license->orderId(),
			'order_item_id'    => $license->orderItemId(),
			'customer_id'      => $license->customerId(),
			'activation_limit' => $license->activationLimit(),
			'activation_count' => $license->activationCount(),
			'expires_at'       => $this->formatUtc( $license->expiresAt() ),
			'parent_id'        => $license->parentId(),
			'created_at'       => $this->formatUtc( $license->createdAt() ),
			'updated_at'       => $this->formatUtc( $license->updatedAt() ),
		);
	}

	/**
	 * Shape a single activation for the detail response.
	 *
	 * @since 1.0.0
	 *
	 * @param Activation $activation The activation to present.
	 * @return array<string, mixed> The activation representation.
	 */
	private function presentActivation( Activation $activation ): array {
		return array(
			'id'              => (int) $activation->id(),
			'site_url'        => $activation->siteUrl(),
			'site_hash'       => $activation->siteHash(),
			'fingerprint'     => $activation->fingerprint(),
			'status'          => $activation->status()->value,
			'activated_at'    => $this->formatUtc( $activation->activatedAt() ),
			'deactivated_at'  => $this->formatUtc( $activation->deactivatedAt() ),
			'last_checked_at' => $this->formatUtc( $activation->lastCheckedAt() ),
		);
	}

	/**
	 * Get the owner email for license detail.
	 *
	 * @since 1.0.0
	 *
	 * @param License $license The license.
	 * @return string|null The owner email, or null.
	 */
	private function ownerEmail( License $license ): ?string {
		$customer = $this->customers->find( $license->customerId() );

		return null !== $customer ? $customer->email() : null;
	}

	/**
	 * Mask a license key for a collection response.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key The full license key.
	 * @return string The masked key, e.g. `7F3A-****-****-7A60`.
	 */
	private function maskKey( string $key ): string {
		$length = strlen( $key );

		if ( $length <= 8 ) {
			return str_repeat( '*', $length );
		}

		return substr( $key, 0, 4 ) . '-****-****-' . substr( $key, -4 );
	}

	/**
	 * Format a datetime as the API's ISO-8601 UTC shape, matching the public licensing surface.
	 *
	 * @since 1.0.0
	 *
	 * @param DateTimeImmutable|null $date The datetime, or null.
	 * @return string|null The `Y-m-d\TH:i:s\Z` string, or null.
	 */
	private function formatUtc( ?DateTimeImmutable $date ): ?string {
		if ( null === $date ) {
			return null;
		}

		return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
	}

	/**
	 * Get the collection query parameter schema.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>> The parameter schema.
	 */
	private function collectionArgs(): array {
		return array(
			'page'        => array(
				'description'       => __( 'Current page of the collection.', 'tiermint' ),
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'per_page'    => array(
				'description'       => __( 'Maximum number of items to return per page.', 'tiermint' ),
				'type'              => 'integer',
				'default'           => 10,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'status'      => array(
				'description'       => __( 'Limit results to a license status.', 'tiermint' ),
				'type'              => 'string',
				'enum'              => $this->statusValues(),
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'product_id'  => array(
				'description'       => __( 'Limit results to licenses for a product.', 'tiermint' ),
				'type'              => 'integer',
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'customer_id' => array(
				'description'       => __( 'Limit results to licenses owned by a customer.', 'tiermint' ),
				'type'              => 'integer',
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'search'      => array(
				'description'       => __( 'Match an exact license key or a customer email.', 'tiermint' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'orderby'     => array(
				'description'       => __( 'Sort collection by license attribute.', 'tiermint' ),
				'type'              => 'string',
				'enum'              => array( 'id', 'created_at', 'expires_at' ),
				'default'           => 'created_at',
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'order'       => array(
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
	 * The `args` schema for the `{id}` path parameter.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>> The parameter schema.
	 */
	private function idArgs(): array {
		return array(
			'id' => array(
				'description'       => __( 'Unique license identifier.', 'tiermint' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * The `args` schema for the activation-deactivation path parameters.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>> The parameter schema.
	 */
	private function activationIdArgs(): array {
		return array_merge(
			$this->idArgs(),
			array(
				'activation_id' => array(
					'description'       => __( 'Unique activation identifier.', 'tiermint' ),
					'type'              => 'integer',
					'required'          => true,
					'sanitize_callback' => 'absint',
					'validate_callback' => 'rest_validate_request_arg',
				),
			)
		);
	}

	/**
	 * Get valid license status values.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, string> The status string values.
	 */
	private function statusValues(): array {
		return array_map(
			static fn ( LicenseStatus $status ): string => $status->value,
			LicenseStatus::cases()
		);
	}
}
