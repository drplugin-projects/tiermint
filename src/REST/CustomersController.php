<?php
/**
 * REST controller for the Customers management resource.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\REST;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Customers\Customer;
use DrPlugin\WP\TierMint\Customers\CustomerDeleteBlockedException;
use DrPlugin\WP\TierMint\Customers\CustomerException;
use DrPlugin\WP\TierMint\Customers\CustomerNotFoundException;
use DrPlugin\WP\TierMint\Customers\CustomerPersistenceException;
use DrPlugin\WP\TierMint\Customers\CustomerService;
use DrPlugin\WP\TierMint\Customers\CustomerValidationException;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST routes for customer management.
 *
 * The service owns customer rules. License lists have no route yet.
 *
 * @since 1.0.0
 */
final class CustomersController extends AbstractRestController {

	/**
	 * Write fields sent to the service.
	 *
	 * @var array<int, string>
	 */
	private const WRITABLE_FIELDS = array(
		'email',
		'name',
		'user_id',
	);

	/**
	 * The bulk actions this resource accepts.
	 *
	 * @var array<int, string>
	 */
	private const BATCH_ACTIONS = array( 'delete' );

	/**
	 * The customers domain service this controller delegates to.
	 *
	 * @var CustomerService
	 */
	private CustomerService $customers;

	/**
	 * Set the customer service.
	 *
	 * @since 1.0.0
	 *
	 * @param CustomerService $customers The customers domain service.
	 */
	public function __construct( CustomerService $customers ) {
		$this->customers = $customers;
	}

	/**
	 * Register the Customers collection and single-item routes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			'/customers',
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
			'/customers/(?P<id>\d+)',
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
			'/customers/batch',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'batch' ),
					'permission_callback' => array( $this, 'managePermission' ),
					'args'                => BatchRequest::args( self::BATCH_ACTIONS ),
				),
			)
		);
	}

	/**
	 * List customers with pagination headers.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response The collection response.
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		$perPage = (int) $request->get_param( 'per_page' );

		$args = array(
			'page'     => (int) $request->get_param( 'page' ),
			'per_page' => $perPage,
			'search'   => $request->get_param( 'search' ),
			'orderby'  => $request->get_param( 'orderby' ),
			'order'    => $request->get_param( 'order' ),
		);

		$customers = $this->customers->list( $args );
		$total     = $this->customers->count( $args );

		$response = new WP_REST_Response( $this->presentCollection( $customers ), 200 );

		return $this->withPagination( $response, $total, $perPage );
	}

	/**
	 * Shape a customer page with linked account details.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, Customer> $customers The customers on this page.
	 * @return array<int, array<string, mixed>> The response representations.
	 */
	private function presentCollection( array $customers ): array {
		$accounts = $this->accountsFor(
			array_map( static fn ( Customer $customer ): int => (int) $customer->userId(), $customers )
		);

		return array_map(
			fn ( Customer $customer ): array => $this->presentCustomer( $customer, $accounts ),
			$customers
		);
	}

	/**
	 * Load account names and emails by user ID.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, int> $userIds The linked user ids.
	 * @return array<int, array{name: string, email: string}> User id => its name and email.
	 */
	private function accountsFor( array $userIds ): array {
		$unique = array_values( array_unique( array_filter( $userIds, static fn ( int $id ): bool => $id > 0 ) ) );

		if ( array() === $unique ) {
			return array();
		}

		$accounts = array();

		foreach ( get_users(
			array(
				'include' => $unique,
				'fields'  => array( 'ID', 'display_name', 'user_email' ),
				'number'  => count( $unique ),
			)
		) as $user ) {
			$accounts[ (int) $user->ID ] = array(
				'name'  => (string) $user->display_name,
				'email' => (string) $user->user_email,
			);
		}

		return $accounts;
	}

	/**
	 * Create a customer.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error The created customer, or an error envelope.
	 */
	public function create( WP_REST_Request $request ) {
		try {
			$customer = $this->customers->create( $this->payload( $request ) );
		} catch ( CustomerValidationException $e ) {
			return $this->invalidParams( $e->getErrors() );
		} catch ( CustomerPersistenceException $e ) {
			// Do not expose database details.
			return $this->serverError( __( 'The customer could not be saved. Please try again.', 'tiermint' ) );
		} catch ( CustomerException $e ) {
			// Other domain errors are invalid input.
			return $this->invalidParams( array( $e->getMessage() ) );
		}

		return new WP_REST_Response( $this->presentCustomer( $customer ), 201 );
	}

	/**
	 * Get one customer.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error The customer, or an error envelope.
	 */
	public function show( WP_REST_Request $request ) {
		$customer = $this->customers->get( (int) $request->get_param( 'id' ) );

		if ( null === $customer ) {
			return $this->notFound();
		}

		return new WP_REST_Response( $this->presentCustomer( $customer ), 200 );
	}

	/**
	 * Update one customer.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error The updated customer, or an error envelope.
	 */
	public function update( WP_REST_Request $request ) {
		try {
			$customer = $this->customers->update( (int) $request->get_param( 'id' ), $this->payload( $request ) );
		} catch ( CustomerNotFoundException $e ) {
			return $this->notFound();
		} catch ( CustomerValidationException $e ) {
			return $this->invalidParams( $e->getErrors() );
		} catch ( CustomerPersistenceException $e ) {
			// Do not expose database details.
			return $this->serverError( __( 'The customer could not be saved. Please try again.', 'tiermint' ) );
		} catch ( CustomerException $e ) {
			return $this->invalidParams( array( $e->getMessage() ) );
		}

		return new WP_REST_Response( $this->presentCustomer( $customer ), 200 );
	}

	/**
	 * Delete one customer profile.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error The deletion result, or an error envelope.
	 */
	public function delete( WP_REST_Request $request ) {
		$id       = (int) $request->get_param( 'id' );
		$customer = $this->customers->get( $id );

		if ( null === $customer ) {
			return $this->notFound();
		}

		$previous = $this->presentCustomer( $customer );
		$failure  = $this->deleteCustomer( $id );

		if ( $failure instanceof WP_Error ) {
			return $failure;
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
	 * Apply an allowed action to a customer batch.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response The per-id result.
	 */
	public function batch( WP_REST_Request $request ): WP_REST_Response {
		return BatchRequest::run(
			(array) $request->get_param( 'ids' ),
			fn ( int $id ): ?WP_Error => $this->deleteCustomer( $id )
		);
	}

	/**
	 * Delete one customer through the shared service path.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The customer id to delete.
	 * @return WP_Error|null The failure envelope, or null when the customer was deleted.
	 */
	private function deleteCustomer( int $id ): ?WP_Error {
		try {
			$this->customers->delete( $id );
		} catch ( CustomerNotFoundException $e ) {
			return $this->notFound();
		} catch ( CustomerDeleteBlockedException $e ) {
			return $this->error(
				'tiermint_customer_in_use',
				$e->getMessage(),
				409,
				array( 'reasons' => $e->getReasons() )
			);
		} catch ( Throwable $e ) {
			// Keep failure details in the log.
			return $this->serverError( __( 'The customer could not be deleted. Please try again.', 'tiermint' ) );
		}

		return null;
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
			'search'   => array(
				'description'       => __( 'Limit results to those matching an email or name.', 'tiermint' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'orderby'  => array(
				'description'       => __( 'Sort collection by customer attribute.', 'tiermint' ),
				'type'              => 'string',
				'enum'              => array( 'id', 'name', 'email', 'created_at' ),
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
	 * The service reports missing required fields. Orders maintain purchase totals.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>> The parameter schema.
	 */
	private function writeArgs(): array {
		return array(
			'email'   => array(
				'description'       => __( 'Customer email address (the primary identity).', 'tiermint' ),
				'type'              => 'string',
				'format'            => 'email',
				'sanitize_callback' => 'sanitize_email',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'name'    => array(
				'description'       => __( 'Customer display name.', 'tiermint' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'user_id' => array(
				'description'       => __( 'Linked WordPress user id (0 to unlink; a guest carries no link).', 'tiermint' ),
				'type'              => 'integer',
				'minimum'           => 0,
				'sanitize_callback' => 'absint',
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
				'description'       => __( 'Unique customer identifier.', 'tiermint' ),
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
	 * Shape a customer for JSON.
	 *
	 * Linked account fields are null for guests and deleted accounts.
	 *
	 * @since 1.0.0
	 *
	 * @param Customer                                            $customer The customer to present.
	 * @param array<int, array{name: string, email: string}>|null $accounts The page's linked
	 *                                                                      accounts, or null to
	 *                                                                      look this one up.
	 * @return array<string, mixed> The response representation.
	 */
	private function presentCustomer( Customer $customer, ?array $accounts = null ): array {
		$userId = (int) $customer->userId();
		$linked = ( $accounts ?? $this->accountsFor( array( $userId ) ) )[ $userId ] ?? null;

		return array(
			'id'             => (int) $customer->id(),
			'user_id'        => $customer->userId(),
			'user_name'      => null !== $linked ? $linked['name'] : null,
			'user_email'     => null !== $linked ? $linked['email'] : null,
			'email'          => $customer->email(),
			'name'           => $customer->name(),
			'is_guest'       => $customer->isGuest(),
			'purchase_count' => $customer->purchaseCount(),
			'purchase_total' => $customer->purchaseTotal(),
			'created_at'     => $customer->createdAt(),
			'updated_at'     => $customer->updatedAt(),
		);
	}
}
