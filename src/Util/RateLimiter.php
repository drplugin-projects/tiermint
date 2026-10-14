<?php
/**
 * Fixed-window transient rate limiter.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Util;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Fixed-window request limits stored in transients.
 *
 * This is not atomic. Use edge rate limiting for a hard limit.
 *
 * @since 1.0.0
 */
final class RateLimiter {

	/**
	 * Prefix for the transient names holding the window counters.
	 *
	 * @var string
	 */
	private const KEY_PREFIX = 'tiermint_rl_';

	/**
	 * Count a request and return its wait time when limited.
	 *
	 * Limited calls do not extend the current window.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $scope      Limit scope.
	 * @param string   $identifier Caller identifier.
	 * @param int      $limit      Requests per window.
	 * @param int      $window     Window length in seconds.
	 * @param int|null $now        Test time, or null for now.
	 * @return int|null Wait time in seconds, or null.
	 */
	public function check( string $scope, string $identifier, int $limit, int $window, ?int $now = null ): ?int {
		$now    = $now ?? time();
		$bucket = intdiv( $now, $window );

		// Hash long identifiers to fit the transient-name limit.
		$key = self::KEY_PREFIX . sha1( $scope . ':' . $identifier . ':' . $window . ':' . $bucket );

		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			// A Retry-After value cannot be zero.
			return max( 1, ( $bucket + 1 ) * $window - $now );
		}

		set_transient( $key, $count + 1, $window );

		return null;
	}

	/**
	 * Return the wait time without using a request.
	 *
	 * Authentication checks must not consume the failure limit.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $scope      Limit scope.
	 * @param string   $identifier Caller identifier.
	 * @param int      $limit      Requests per window.
	 * @param int      $window     Window length in seconds.
	 * @param int|null $now        Test time, or null for now.
	 * @return int|null Wait time in seconds, or null.
	 */
	public function peek( string $scope, string $identifier, int $limit, int $window, ?int $now = null ): ?int {
		$now    = $now ?? time();
		$bucket = intdiv( $now, $window );
		$key    = self::KEY_PREFIX . sha1( $scope . ':' . $identifier . ':' . $window . ':' . $bucket );

		if ( (int) get_transient( $key ) >= $limit ) {
			return max( 1, ( $bucket + 1 ) * $window - $now );
		}

		return null;
	}
}
