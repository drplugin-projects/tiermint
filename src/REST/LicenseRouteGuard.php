<?php
/**
 * Rate-limit guard for the public licensing routes.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\REST;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Core\Hookable;
use DrPlugin\WP\TierMint\Util\RateLimiter;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Limits public licensing and signed-download routes before REST dispatch.
 *
 * License routes use key and IP windows. Download routes use the IP window.
 *
 * @since 1.0.0
 */
final class LicenseRouteGuard implements Hookable {

	/**
	 * The route pattern the guard applies to (relative to the REST prefix).
	 *
	 * @var string
	 */
	private const ROUTE_PATTERN = '#^/tiermint/v1/(license/(validate|activate|deactivate)|update-check|package/download|order/download)$#';

	/**
	 * The transient-backed fixed-window limiter.
	 *
	 * @var RateLimiter
	 */
	private RateLimiter $limiter;

	/**
	 * Wire the guard with the rate limiter.
	 *
	 * @since 1.0.0
	 *
	 * @param RateLimiter $limiter The transient-backed rate limiter.
	 */
	public function __construct( RateLimiter $limiter ) {
		$this->limiter = $limiter;
	}

	/**
	 * Attach the guard ahead of REST dispatch.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_filter( 'rest_pre_dispatch', array( $this, 'guard' ), 10, 3 );
	}

	/**
	 * Return 429 for an over-limit public licensing request.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed           $result  The short-circuit result another filter may have set.
	 * @param WP_REST_Server  $server  The REST server (unused; filter signature).
	 * @param WP_REST_Request $request The incoming request.
	 * @return mixed The 429 response, or the untouched $result.
	 */
	public function guard( $result, $server, $request ) {
		if ( null !== $result || ! $request instanceof WP_REST_Request ) {
			return $result;
		}

		if ( 1 !== preg_match( self::ROUTE_PATTERN, $request->get_route() ) ) {
			return $result;
		}

		$limits = $this->limits();

		// Check the IP before reading request parameters.
		$retryAfter = $this->limiter->check(
			'license_ip',
			$this->clientIp(),
			$limits['license_ip']['limit'],
			$limits['license_ip']['window']
		);

		if ( null === $retryAfter ) {
			$licenseKey = $request->get_param( 'license_key' );

			if ( is_string( $licenseKey ) && '' !== trim( $licenseKey ) ) {
				$retryAfter = $this->limiter->check(
					'license_key',
					trim( $licenseKey ),
					$limits['license_key']['limit'],
					$limits['license_key']['window']
				);
			}
		}

		if ( null === $retryAfter ) {
			return $result;
		}

		$response = new WP_REST_Response(
			array(
				'code'    => 'tiermint_rate_limited',
				'message' => __( 'Too many licensing requests. Please wait and try again.', 'tiermint' ),
				'data'    => array( 'status' => 429 ),
			),
			429
		);
		$response->header( 'Retry-After', (string) $retryAfter );

		return $response;
	}

	/**
	 * Get filtered licensing limits with safe minimums.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array{limit: int, window: int}> The two effective windows.
	 */
	private function limits(): array {
		$defaults = array(
			'license_key' => array(
				'limit'  => 60,
				'window' => 3600,
			),
			'license_ip'  => array(
				'limit'  => 300,
				'window' => 3600,
			),
		);

		/**
		 * Filters REST rate limits by scope.
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
	 * Get the client IP from the non-spoofable server address.
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
