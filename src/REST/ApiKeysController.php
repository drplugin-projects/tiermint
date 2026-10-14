<?php
/**
 * Management REST controller for TierMint API keys.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\REST;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\REST\Auth\ApiKey;
use DrPlugin\WP\TierMint\REST\Auth\ApiKeyAuthenticator;
use DrPlugin\WP\TierMint\REST\Auth\ApiKeyNotFoundException;
use DrPlugin\WP\TierMint\REST\Auth\ApiKeyPermission;
use DrPlugin\WP\TierMint\REST\Auth\ApiKeyPersistenceException;
use DrPlugin\WP\TierMint\REST\Auth\ApiKeyService;
use DrPlugin\WP\TierMint\REST\Auth\ApiKeyValidationException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Management API key routes.
 *
 * The bearer is returned only when a key is created.
 *
 * @since 1.0.0
 */
final class ApiKeysController extends AbstractRestController {

	/**
	 * The api-key domain service.
	 *
	 * @var ApiKeyService
	 */
	private ApiKeyService $apiKeys;

	/**
	 * Authenticates the current request.
	 *
	 * @var ApiKeyAuthenticator
	 */
	private ApiKeyAuthenticator $authenticator;

	/**
	 * Wire the controller.
	 *
	 * @since 1.0.0
	 *
	 * @param ApiKeyService       $apiKeys       The api-key domain service.
	 * @param ApiKeyAuthenticator $authenticator The management authenticator.
	 */
	public function __construct( ApiKeyService $apiKeys, ApiKeyAuthenticator $authenticator ) {
		$this->apiKeys       = $apiKeys;
		$this->authenticator = $authenticator;
	}

	/**
	 * Register the /api-keys management routes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			'/api-keys',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'settingsPermission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'settingsPermission' ),
					'args'                => $this->createArgs(),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/api-keys/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'revoke' ),
					'permission_callback' => array( $this, 'settingsPermission' ),
					'args'                => array(
						'id' => array(
							'description'       => __( 'The API key id.', 'tiermint' ),
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
			)
		);
	}

	/**
	 * List public API key fields.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request (unused).
	 * @return WP_REST_Response|WP_Error The listing, or an error envelope.
	 */
	public function index( WP_REST_Request $request ) {
		try {
			$keys = $this->apiKeys->all();
		} catch ( ApiKeyPersistenceException $e ) {
			return $this->serverError();
		}

		return new WP_REST_Response( array_map( array( $this, 'presentKey' ), $keys ), 200 );
	}

	/**
	 * Issue a key for the current user.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error The created key with its one-time bearer, or an error envelope.
	 */
	public function create( WP_REST_Request $request ) {
		// A key cannot mint replacement credentials.
		if ( null !== $this->authenticator->authenticatedKey() ) {
			return $this->error(
				'tiermint_forbidden',
				__( 'API keys cannot be created with an API key. Sign in to create keys.', 'tiermint' ),
				403
			);
		}

		$permission = 'write' === $request->get_param( 'permissions' )
			? ApiKeyPermission::Write
			: ApiKeyPermission::Read;

		try {
			$issued = $this->apiKeys->issue(
				get_current_user_id(),
				(string) $request->get_param( 'label' ),
				$permission
			);
		} catch ( ApiKeyValidationException $e ) {
			return $this->invalidParams( $e->getErrors() );
		} catch ( ApiKeyPersistenceException $e ) {
			return $this->serverError();
		}

		$payload           = $this->presentKey( $issued['key'] );
		$payload['bearer'] = $issued['bearer'];

		return new WP_REST_Response( $payload, 201 );
	}

	/**
	 * Revoke a key and retain its audit row.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error The revoked key, or an error envelope.
	 */
	public function revoke( WP_REST_Request $request ) {
		try {
			$key = $this->apiKeys->revoke( (int) $request->get_param( 'id' ) );
		} catch ( ApiKeyNotFoundException $e ) {
			return $this->notFound();
		} catch ( ApiKeyPersistenceException $e ) {
			return $this->serverError();
		}

		return new WP_REST_Response( $this->presentKey( $key ), 200 );
	}

	/**
	 * Build the public key response.
	 *
	 * @since 1.0.0
	 *
	 * @param ApiKey $key The key.
	 * @return array<string, mixed> The response row.
	 */
	private function presentKey( ApiKey $key ): array {
		return array(
			'id'           => (int) $key->id(),
			'user_id'      => $key->userId(),
			'label'        => $key->label(),
			'public_key'   => $key->publicKey(),
			'permissions'  => $key->permission()->value,
			'status'       => $key->status()->value,
			'last_used_at' => null !== $key->lastUsedAt() ? $key->lastUsedAt()->format( 'Y-m-d\TH:i:s\Z' ) : null,
			'created_at'   => null !== $key->createdAt() ? $key->createdAt()->format( 'Y-m-d\TH:i:s\Z' ) : null,
		);
	}

	/**
	 * Build key-creation arguments.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>> The parameter schema.
	 */
	private function createArgs(): array {
		return array(
			'label'       => array(
				'description'       => __( 'A human label describing what the key is for.', 'tiermint' ),
				'type'              => 'string',
				'required'          => true,
				'maxLength'         => 200,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'permissions' => array(
				'description'       => __( 'The permission tier: read (safe methods only) or write.', 'tiermint' ),
				'type'              => 'string',
				'default'           => 'read',
				'enum'              => array( 'read', 'write' ),
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}
}
