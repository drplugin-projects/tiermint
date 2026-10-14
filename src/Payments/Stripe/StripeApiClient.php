<?php
/**
 * Thin HTTP client for the Stripe REST API over WordPress's HTTP layer.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Payments\Stripe;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Payments\GatewayRequestException;

/**
 * Sends Stripe REST requests through WordPress HTTP.
 *
 * This class has no order logic. It stays non-final for gateway tests.
 *
 * @since 1.0.0
 */
class StripeApiClient {

	/**
	 * Stripe API origin.
	 *
	 * @var string
	 */
	public const API_BASE = 'https://api.stripe.com';

	/**
	 * Send a Stripe request and decode its JSON response.
	 *
	 * Never include the secret key in an exception.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $secretKey      Stripe secret key.
	 * @param string      $method         HTTP method.
	 * @param string      $path           API path.
	 * @param array       $params         Query parameters or form body.
	 * @param string|null $idempotencyKey Retry key, if set.
	 * @return array Decoded response body.
	 * @throws GatewayRequestException When the request fails.
	 */
	public function request( string $secretKey, string $method, string $path, array $params = array(), ?string $idempotencyKey = null ): array {
		$method = strtoupper( $method );
		$url    = self::API_BASE . $path;

		$headers = array(
			'Authorization' => 'Bearer ' . $secretKey,
		);

		if ( null !== $idempotencyKey ) {
			$headers['Idempotency-Key'] = $idempotencyKey;
		}

		$args = array(
			'method'  => $method,
			'timeout' => 30,
		);

		if ( 'GET' === $method ) {
			if ( array() !== $params ) {
				$url = add_query_arg( $params, $url );
			}
		} else {
			$headers['Content-Type'] = 'application/x-www-form-urlencoded';
			$args['body']            = $params;
		}

		$args['headers'] = $headers;

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			// The controller never renders this log-only message.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only transport message.
			throw new GatewayRequestException( 'Stripe request failed: ' . $response->get_error_message() );
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $decoded ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only message.
			throw new GatewayRequestException( 'Stripe returned an unparseable response.', null, $status );
		}

		if ( $status >= 400 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only Stripe message.
			throw $this->errorFromEnvelope( $decoded, $status );
		}

		return $decoded;
	}

	/**
	 * Send a POST request.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $secretKey      Stripe secret key.
	 * @param string      $path           API path.
	 * @param array       $params         Form body.
	 * @param string|null $idempotencyKey Retry key, if set.
	 * @return array Decoded response body.
	 * @throws GatewayRequestException When the request fails.
	 */
	public function post( string $secretKey, string $path, array $params, ?string $idempotencyKey = null ): array {
		return $this->request( $secretKey, 'POST', $path, $params, $idempotencyKey );
	}

	/**
	 * Send a GET request.
	 *
	 * @since 1.0.0
	 *
	 * @param string $secretKey Stripe secret key.
	 * @param string $path      API path.
	 * @param array  $params    Query parameters.
	 * @return array Decoded response body.
	 * @throws GatewayRequestException When the request fails.
	 */
	public function get( string $secretKey, string $path, array $params = array() ): array {
		return $this->request( $secretKey, 'GET', $path, $params );
	}

	/**
	 * Build an exception from a Stripe error response.
	 *
	 * @since 1.0.0
	 *
	 * @param array $decoded Decoded response body.
	 * @param int   $status  HTTP status.
	 * @return GatewayRequestException Request exception.
	 */
	private function errorFromEnvelope( array $decoded, int $status ): GatewayRequestException {
		$envelope = ( isset( $decoded['error'] ) && is_array( $decoded['error'] ) ) ? $decoded['error'] : array();

		$message = ( isset( $envelope['message'] ) && is_string( $envelope['message'] ) )
			? 'Stripe error: ' . $envelope['message']
			: sprintf( 'Stripe returned HTTP %d with no error message.', $status );

		// Prefer Stripe's specific error code.
		$code = $envelope['code'] ?? $envelope['type'] ?? null;

		return new GatewayRequestException( $message, is_string( $code ) ? $code : null, $status );
	}
}
