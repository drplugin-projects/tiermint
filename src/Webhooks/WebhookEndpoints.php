<?php
/**
 * Outbound webhook endpoint configuration store.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Webhooks;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Util\Crypto;
use DrPlugin\WP\TierMint\Util\CryptoException;

/**
 * Store outbound webhook endpoints and encrypted signing secrets.
 *
 * Settings never contain secrets. Failed endpoints stay disabled until re-enabled.
 */
class WebhookEndpoints {

	/**
	 * Option name holding the shared plugin settings array.
	 *
	 * @var string
	 */
	private const SETTINGS_OPTION = 'tiermint_settings';

	/**
	 * Option-name prefix for per-endpoint encrypted signing secrets.
	 *
	 * @var string
	 */
	private const SECRET_OPTION_PREFIX = 'tiermint_secret_webhook_';

	/**
	 * Core event catalog, extended by the webhook-events filter.
	 *
	 * @var array<int, string>
	 */
	private const DEFAULT_EVENTS = array(
		'order.completed',
		'order.refunded',
		'license.generated',
		'license.activated',
		'license.deactivated',
		'license.expired',
		'customer.created',
	);

	/**
	 * Encrypts and decrypts the per-endpoint signing secrets at rest.
	 *
	 * @var Crypto
	 */
	private Crypto $crypto;

	/**
	 * Wire the secret cipher.
	 *
	 * @param Crypto $crypto Encrypts signing secrets keyed to SECURE_AUTH_KEY.
	 */
	public function __construct( Crypto $crypto ) {
		$this->crypto = $crypto;
	}

	/**
	 * The event names an endpoint may subscribe to.
	 *
	 * @return array<int, string> The filtered catalog, list-shaped and unique.
	 */
	public function eventCatalog(): array {
		/**
 * Filter outbound webhook events.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, string> $events Allowed event names.
		 */
		$events = apply_filters( 'tiermint_webhook_events', self::DEFAULT_EVENTS );

		$clean = array();
		foreach ( (array) $events as $event ) {
			if ( is_string( $event ) && '' !== $event ) {
				$clean[ $event ] = true;
			}
		}

		return array_keys( $clean );
	}

	/**
	 * All registered endpoints, secrets never included.
	 *
	 * @return array<int, array<string, mixed>> Endpoint records.
	 */
	public function all(): array {
		return array_values( $this->readEndpoints() );
	}

	/**
	 * A single endpoint by id.
	 *
	 * @param string $id The endpoint id.
	 * @return array<string, mixed>|null The record, or null when unknown.
	 */
	public function get( string $id ): ?array {
		$endpoints = $this->readEndpoints();

		return $endpoints[ $id ] ?? null;
	}

	/**
	 * Return active endpoints subscribed to an event.
	 *
	 * @param string $event The event name (for example 'order.completed').
	 * @return array<int, array<string, mixed>> Matching endpoint records.
	 */
	public function activeFor( string $event ): array {
		$matches = array();

		foreach ( $this->readEndpoints() as $endpoint ) {
			if ( $endpoint['active'] && in_array( $event, $endpoint['events'], true ) ) {
				$matches[] = $endpoint;
			}
		}

		return $matches;
	}

	/**
	 * Register an endpoint and return its secret once.
	 *
	 * @param string             $url    The delivery URL (http or https).
	 * @param array<int, string> $events Event names to subscribe to.
	 * @return array{endpoint: array<string, mixed>, secret: string} The stored
	 *                          record and the plaintext secret.
	 * @throws WebhookValidationException When the URL or events are invalid.
	 */
	public function create( string $url, array $events ): array {
		$url    = $this->validateUrl( $url );
		$events = $this->validateEvents( $events );

		$id     = 'whe_' . bin2hex( random_bytes( 8 ) );
		$secret = 'tmwh_' . bin2hex( random_bytes( 24 ) );

		$endpoint = array(
			'id'         => $id,
			'url'        => $url,
			'events'     => $events,
			'active'     => true,
			'failing'    => false,
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
		);

		// Secret first: if this write failed by fataling, no endpoint record
		// would reference a missing secret. Autoload off is load-bearing: the
		// encrypted blob must never ride the autoloaded options payload.
		update_option( self::SECRET_OPTION_PREFIX . $id, $this->crypto->encrypt( $secret ), false );

		$endpoints        = $this->readEndpoints();
		$endpoints[ $id ] = $endpoint;
		$this->writeEndpoints( $endpoints );

		return array(
			'endpoint' => $endpoint,
			'secret'   => $secret,
		);
	}

	/**
	 * Update an endpoint URL, events or active state.
	 *
	 * Re-activating clears the failing flag. Changed values are revalidated.
	 *
	 * @param string               $id      The endpoint id.
	 * @param array<string, mixed> $changes Any of 'url', 'events', 'active'.
	 * @return array<string, mixed> The updated record.
	 * @throws WebhookNotFoundException When the id is not registered.
	 */
	public function update( string $id, array $changes ): array {
		$endpoints = $this->readEndpoints();

		if ( ! isset( $endpoints[ $id ] ) ) {
			throw new WebhookNotFoundException( esc_html( $this->unknownEndpointMessage() ) );
		}

		$endpoint = $endpoints[ $id ];

		if ( array_key_exists( 'url', $changes ) ) {
			$endpoint['url'] = $this->validateUrl( (string) $changes['url'] );
		}

		if ( array_key_exists( 'events', $changes ) ) {
			$endpoint['events'] = $this->validateEvents( (array) $changes['events'] );
		}

		if ( array_key_exists( 'active', $changes ) ) {
			$endpoint['active'] = (bool) $changes['active'];

			if ( $endpoint['active'] ) {
				$endpoint['failing'] = false;
			}
		}

		$endpoints[ $id ] = $endpoint;
		$this->writeEndpoints( $endpoints );

		return $endpoint;
	}

	/**
	 * Remove an endpoint and its stored secret.
	 *
	 * @param string $id The endpoint id.
	 * @return void
	 * @throws WebhookNotFoundException When the id is not registered.
	 */
	public function delete( string $id ): void {
		$endpoints = $this->readEndpoints();

		if ( ! isset( $endpoints[ $id ] ) ) {
			throw new WebhookNotFoundException( esc_html( $this->unknownEndpointMessage() ) );
		}

		unset( $endpoints[ $id ] );
		$this->writeEndpoints( $endpoints );
		delete_option( self::SECRET_OPTION_PREFIX . $id );
	}

	/**
	 * Return an endpoint signing secret, or null when unavailable.
	 *
	 * @param string $id The endpoint id.
	 * @return string|null The plaintext secret, or null when unavailable.
	 */
	public function secretFor( string $id ): ?string {
		$stored = get_option( self::SECRET_OPTION_PREFIX . $id );

		if ( ! is_string( $stored ) || '' === $stored ) {
			return null;
		}

		try {
			return $this->crypto->decrypt( $stored );
		} catch ( CryptoException $e ) {
			return null;
		}
	}

	/**
	 * Mark an endpoint as failing and stop deliveries.
	 *
	 * @param string $id The endpoint id.
	 * @return void
	 */
	public function markFailing( string $id ): void {
		$endpoints = $this->readEndpoints();

		if ( ! isset( $endpoints[ $id ] ) ) {
			return;
		}

		$endpoints[ $id ]['failing'] = true;
		$endpoints[ $id ]['active']  = false;
		$this->writeEndpoints( $endpoints );
	}

	/**
	 * Endpoints currently flagged failing (for the admin notice).
	 *
	 * @return array<int, array<string, mixed>> Failing endpoint records.
	 */
	public function failing(): array {
		$failing = array();

		foreach ( $this->readEndpoints() as $endpoint ) {
			if ( $endpoint['failing'] ) {
				$failing[] = $endpoint;
			}
		}

		return $failing;
	}

	/**
	 * Validate a delivery URL without resolving DNS.
	 *
	 * WordPress checks unsafe addresses at delivery time.
	 *
	 * @param string $url The candidate URL.
	 * @return string The validated, normalized URL.
	 * @throws WebhookValidationException When malformed or not http(s).
	 */
	private function validateUrl( string $url ): string {
		$url   = trim( $url );
		$parts = wp_parse_url( $url );

		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
		$host   = isset( $parts['host'] ) ? (string) $parts['host'] : '';

		$invalid = ! in_array( $scheme, array( 'http', 'https' ), true )
			|| '' === $host
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
			|| false !== strpbrk( $host, ':#?[]' );

		$clean = $invalid ? '' : (string) esc_url_raw( $url, array( 'http', 'https' ) );

		if ( '' === $clean ) {
			throw new WebhookValidationException(
				esc_html__( 'The webhook URL must be a valid http or https URL.', 'tiermint' )
			);
		}

		return $clean;
	}

	/**
	 * Validate a subscription list against the filtered catalog.
	 *
	 * @param array<int, mixed> $events The candidate event names.
	 * @return array<int, string> The normalized, de-duplicated list.
	 * @throws WebhookValidationException When empty or containing unknown events.
	 */
	private function validateEvents( array $events ): array {
		$catalog = $this->eventCatalog();
		$clean   = array();

		foreach ( $events as $event ) {
			if ( ! is_string( $event ) || ! in_array( $event, $catalog, true ) ) {
				throw new WebhookValidationException(
					esc_html__( 'One or more webhook events are not recognized.', 'tiermint' )
				);
			}

			$clean[ $event ] = true;
		}

		if ( array() === $clean ) {
			throw new WebhookValidationException(
				esc_html__( 'A webhook endpoint must subscribe to at least one event.', 'tiermint' )
			);
		}

		return array_keys( $clean );
	}

	/**
	 * The translated unknown-endpoint message shared by update and delete.
	 *
	 * @return string The message.
	 */
	private function unknownEndpointMessage(): string {
		return __( 'That webhook endpoint is not registered.', 'tiermint' );
	}

	/**
	 * Read the endpoint records keyed by id.
	 *
	 * @return array<string, array<string, mixed>> Endpoint records by id.
	 */
	private function readEndpoints(): array {
		$settings = get_option( self::SETTINGS_OPTION, array() );
		$stored   = array();

		if ( is_array( $settings ) && isset( $settings['webhooks']['endpoints'] ) && is_array( $settings['webhooks']['endpoints'] ) ) {
			$stored = $settings['webhooks']['endpoints'];
		}

		$endpoints = array();
		foreach ( $stored as $endpoint ) {
			if ( is_array( $endpoint ) && isset( $endpoint['id'] ) && is_string( $endpoint['id'] ) ) {
				$endpoints[ $endpoint['id'] ] = $this->normalize( $endpoint );
			}
		}

		return $endpoints;
	}

	/**
	 * Persist the endpoint records, preserving unrelated settings keys.
	 *
	 * @param array<string, array<string, mixed>> $endpoints Records keyed by id.
	 * @return void
	 */
	private function writeEndpoints( array $endpoints ): void {
		$settings = get_option( self::SETTINGS_OPTION, array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		if ( ! isset( $settings['webhooks'] ) || ! is_array( $settings['webhooks'] ) ) {
			$settings['webhooks'] = array();
		}

		$settings['webhooks']['endpoints'] = array_values( $endpoints );

		update_option( self::SETTINGS_OPTION, $settings );
	}

	/**
	 * Normalise a stored record with safe defaults.
	 *
	 * @param array<string, mixed> $endpoint The stored record.
	 * @return array<string, mixed> The normalized record.
	 */
	private function normalize( array $endpoint ): array {
		return array(
			'id'         => (string) $endpoint['id'],
			'url'        => isset( $endpoint['url'] ) ? (string) $endpoint['url'] : '',
			'events'     => isset( $endpoint['events'] ) && is_array( $endpoint['events'] ) ? array_values( array_filter( $endpoint['events'], 'is_string' ) ) : array(),
			'active'     => ! empty( $endpoint['active'] ),
			'failing'    => ! empty( $endpoint['failing'] ),
			'created_at' => isset( $endpoint['created_at'] ) ? (string) $endpoint['created_at'] : '',
		);
	}
}
