<?php
/**
 * The TierMint API key authentication layer for management REST routes.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\REST\Auth;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Core\Hookable;
use DrPlugin\WP\TierMint\Util\RateLimiter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Authenticate management API keys and apply key rate limits.
 *
 * Cookie and Application Password authentication remain with WordPress.
 *
 * @since 1.0.0
 */
final class ApiKeyAuthenticator implements Hookable {

	/**
	 * Bearer credential pattern.
	 *
	 * @var string
	 */
	private const BEARER_PATTERN = '/^(?i:Bearer)\s+(tmk_[0-9a-f]{32}):([^\s:]+)$/';

	/**
	 * The cheap engage probe: does the header claim our scheme at all?
	 *
	 * @var string
	 */
	private const SCHEME_PATTERN = '/^(?i:Bearer)\s+tmk_/';

	/**
	 * Public routes that do not use management-key limits.
	 *
	 * @var string
	 */
	private const PUBLIC_ROUTE_PATTERN = '#^/tiermint/v1/(license/|checkout/|webhooks/)#';

	/**
	 * The api-key domain service.
	 *
	 * @var ApiKeyService
	 */
	private ApiKeyService $apiKeys;

	/**
	 * The transient-backed fixed-window limiter.
	 *
	 * @var RateLimiter
	 */
	private RateLimiter $limiter;

	/**
	 * The error to report from rest_authentication_errors, when auth failed.
	 *
	 * @var WP_Error|null
	 */
	private ?WP_Error $error = null;

	/**
	 * Key waiting for WordPress to set its user.
	 *
	 * @var ApiKey|null
	 */
	private ?ApiKey $pendingKey = null;

	/**
	 * The key that authenticated the current request, when one did.
	 *
	 * @var ApiKey|null
	 */
	private ?ApiKey $authenticatedKey = null;

	/**
	 * Wire the authenticator.
	 *
	 * @since 1.0.0
	 *
	 * @param ApiKeyService $apiKeys The api-key domain service.
	 * @param RateLimiter   $limiter The transient-backed rate limiter.
	 */
	public function __construct( ApiKeyService $apiKeys, RateLimiter $limiter ) {
		$this->apiKeys = $apiKeys;
		$this->limiter = $limiter;
	}

	/**
	 * Attach authentication hooks after WordPress credentials.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_filter( 'determine_current_user', array( $this, 'authenticate' ), 30 );
		add_filter( 'rest_authentication_errors', array( $this, 'reportError' ), 20 );
		add_filter( 'rest_pre_dispatch', array( $this, 'restrictKeyRequest' ), 5, 3 );
		add_action( 'set_current_user', array( $this, 'confirmKey' ) );
	}

	/**
	 * Confirm the pending key when WordPress sets its user.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function confirmKey(): void {
		$this->authenticatedKey = $this->pendingKey;
		$this->pendingKey       = null;
	}

	/**
	 * Resolve a TierMint bearer credential to its owning user id.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $userId The user id another authenticator resolved, if any.
	 * @return mixed The owner's user id, or the incoming value when this layer
	 *               does not engage or refuses the credential.
	 */
	public function authenticate( $userId ) {
		// Clear a verdict from an earlier determination.
		$this->error      = null;
		$this->pendingKey = null;

		if ( ! empty( $userId ) ) {
			return $userId;
		}

		$header = $this->authorizationHeader();

		// Let other authenticators handle other schemes.
		if ( 1 !== preg_match( self::SCHEME_PATTERN, $header ) ) {
			return $userId;
		}

		$limits = $this->limits();

		// A full failure window blocks this IP, including valid keys.
		$blocked = $this->limiter->peek(
			'auth_fail',
			$this->clientIp(),
			$limits['auth_fail']['limit'],
			$limits['auth_fail']['window']
		);

		if ( null !== $blocked ) {
			$this->error = new WP_Error(
				'tiermint_rate_limited',
				__( 'Too many failed authentication attempts. Please wait and try again.', 'tiermint' ),
				array(
					'status'      => 429,
					'retry_after' => $blocked,
				)
			);

			return $userId;
		}

		if ( 1 !== preg_match( self::BEARER_PATTERN, $header, $matches ) ) {
			// Malformed headers do not spend the failure limit.
			$this->error = $this->unauthorized();

			return $userId;
		}

		try {
			$key = $this->apiKeys->verify( $matches[1], $matches[2] );
		} catch ( ApiKeyPersistenceException $e ) {
			// Do not report a storage fault as invalid credentials.
			$this->error = new WP_Error(
				'tiermint_server_error',
				__( 'Authentication is temporarily unavailable. Please try again.', 'tiermint' ),
				array( 'status' => 500 )
			);

			return $userId;
		}

		if ( null === $key ) {
			return $this->recordFailure( $userId, $limits );
		}

		// confirmKey() promotes this key after WordPress sets its user.
		$this->pendingKey = $key;

		return $key->userId();
	}

	/**
	 * Report this layer's verdict to the REST authentication check.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $errors Another layer's verdict, if any.
	 * @return mixed This layer's error when it has one, else the incoming value.
	 */
	public function reportError( $errors ) {
		if ( ! empty( $errors ) ) {
			return $errors;
		}

		// WordPress determines the REST user lazily (usually not before the
		// cookie check at priority 100, after this filter). Force it now so
		// this layer's verdict exists before it is reported, but only when
		// the request actually presents our scheme: the licensing hot path must
		// never pay for a user determination it does not need.
		if ( 1 === preg_match( self::SCHEME_PATTERN, $this->authorizationHeader() ) ) {
			get_current_user_id();
		}

		if ( null !== $this->error ) {
			return $this->error;
		}

		return $errors;
	}

	/**
	 * Restrict key-authenticated management requests.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed           $result  The short-circuit result another filter may have set.
	 * @param \WP_REST_Server $server  The REST server (unused; filter signature).
	 * @param WP_REST_Request $request The incoming request.
	 * @return mixed The refusal, or the untouched $result.
	 */
	public function restrictKeyRequest( $result, $server, $request ) {
		$key = $this->activeKey();

		if ( null !== $result || null === $key || ! $request instanceof WP_REST_Request ) {
			return $result;
		}

		if ( 1 === preg_match( self::PUBLIC_ROUTE_PATTERN, $request->get_route() ) ) {
			return $result;
		}

		$method = strtoupper( $request->get_method() );

		if ( ! $key->canWrite() && ! in_array( $method, array( 'GET', 'HEAD', 'OPTIONS' ), true ) ) {
			return new WP_Error(
				'tiermint_forbidden',
				__( 'This API key is read-only.', 'tiermint' ),
				array( 'status' => 403 )
			);
		}

		$limits = $this->limits();

		$retryAfter = $this->limiter->check(
			'management_api',
			$key->publicKey(),
			$limits['management_api']['limit'],
			$limits['management_api']['window']
		);

		if ( null !== $retryAfter ) {
			$response = new WP_REST_Response(
				array(
					'code'    => 'tiermint_rate_limited',
					'message' => __( 'Too many management API requests. Please wait and try again.', 'tiermint' ),
					'data'    => array( 'status' => 429 ),
				),
				429
			);
			$response->header( 'Retry-After', (string) $retryAfter );

			return $response;
		}

		return $result;
	}

	/**
	 * Get the key for the current request.
	 *
	 * @since 1.0.0
	 *
	 * @return ApiKey|null The authenticating key, if any.
	 */
	public function authenticatedKey(): ?ApiKey {
		return $this->activeKey();
	}

	/**
	 * Get the key while it still matches the current user.
	 *
	 * @since 1.0.0
	 *
	 * @return ApiKey|null The key, or null when it no longer applies.
	 */
	private function activeKey(): ?ApiKey {
		if ( null === $this->authenticatedKey ) {
			return null;
		}

		if ( get_current_user_id() !== $this->authenticatedKey->userId() ) {
			return null;
		}

		return $this->authenticatedKey;
	}

	/**
	 * Record a failed attempt and return a generic 401.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed                                         $userId The incoming user id to pass through.
	 * @param array<string, array{limit: int, window: int}> $limits The effective windows.
	 * @return mixed The untouched incoming user id.
	 */
	private function recordFailure( $userId, array $limits ) {
		$this->limiter->check(
			'auth_fail',
			$this->clientIp(),
			$limits['auth_fail']['limit'],
			$limits['auth_fail']['window']
		);

		$this->error = $this->unauthorized();

		return $userId;
	}

	/**
	 * Build a generic credential error.
	 *
	 * @since 1.0.0
	 *
	 * @return WP_Error The tiermint_unauthorized envelope.
	 */
	private function unauthorized(): WP_Error {
		return new WP_Error(
			'tiermint_unauthorized',
			__( 'The API credentials are not valid.', 'tiermint' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Get the filtered and clamped auth limits.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array{limit: int, window: int}> The effective windows.
	 */
	private function limits(): array {
		$defaults = array(
			'auth_fail'      => array(
				'limit'  => 20,
				'window' => 3600,
			),
			'management_api' => array(
				'limit'  => 600,
				'window' => 3600,
			),
		);

		/**
		 * Filters REST rate limits.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, array{limit: int, window: int}> $limits The limit map.
		 */
		$limits = apply_filters( 'tiermint_rest_rate_limits', $defaults );

		$effective = array();

		foreach ( $defaults as $scope => $default ) {
			$entry               = is_array( $limits[ $scope ] ?? null ) ? $limits[ $scope ] : $default;
			$effective[ $scope ] = array(
				'limit'  => max( 1, (int) ( $entry['limit'] ?? $default['limit'] ) ),
				'window' => max( 1, (int) ( $entry['window'] ?? $default['window'] ) ),
			);
		}

		return $effective;
	}

	/**
	 * Get the Authorization header, including Apache's fallback name.
	 *
	 * @since 1.0.0
	 *
	 * @return string The header value, or an empty string.
	 */
	private function authorizationHeader(): string {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The credential is parsed against a strict pattern and compared, never stored or echoed; sanitizing could corrupt it.
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			return trim( wp_unslash( (string) $_SERVER['HTTP_AUTHORIZATION'] ) );
		}

		if ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			return trim( wp_unslash( (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
		}
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		return '';
	}

	/**
	 * Get the client IP for failure limits.
	 *
	 * @since 1.0.0
	 *
	 * @return string The client IP, or 'unknown' when unavailable.
	 */
	private function clientIp(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';

		return '' !== $ip ? $ip : 'unknown';
	}
}
