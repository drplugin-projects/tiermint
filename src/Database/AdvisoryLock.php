<?php
/**
 * Generic MySQL advisory lock for serializing cross-request critical sections.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Database;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * MySQL advisory lock for cross-request critical sections.
 *
 * It assumes one write connection per request.
 *
 * @since 1.0.0
 */
class AdvisoryLock {

	/**
	 * Lock wait time in seconds.
	 *
	 * @var int
	 */
	private const TIMEOUT_SECONDS = 10;

	/**
	 * Wire the lock with its database handle and namespace.
	 *
	 * @since 1.0.0
	 *
	 * @param \wpdb  $wpdb      The WordPress database abstraction.
	 * @param string $lockSpace A short slug scoping this lock's keys (e.g. 'mint'),
	 *                          so different critical sections never contend.
	 */
	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly string $lockSpace
	) {
	}

	/**
	 * Run a callback while holding a keyed lock.
	 *
	 * @since 1.0.0
	 *
	 * @param string|int       $key      The entity key to serialize on (e.g. an order id).
	 * @param callable():mixed $callback The work to run under the lock.
	 * @return mixed The callback's return value.
	 *
	 * @throws AdvisoryLockException When the lock cannot be acquired within the timeout.
	 */
	public function withLock( $key, callable $callback ) {
		$name = $this->lockName( (string) $key );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory lock acquire; the only bound value is the lock name, and there is nothing to cache.
		$acquired = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, self::TIMEOUT_SECONDS ) );

		if ( '1' !== (string) $acquired ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only literal; callers map it to retries or a generic message.
			throw new AdvisoryLockException( sprintf( 'Could not acquire the %s advisory lock for key %s.', $this->lockSpace, (string) $key ) );
		}

		try {
			return $callback();
		} finally {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory lock release bound by name; nothing to cache.
			$this->wpdb->query( $this->wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
	}

	/**
	 * Build a tenant-specific MySQL lock name.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key The entity key.
	 * @return string The lock name.
	 */
	private function lockName( string $key ): string {
		$scope = md5( (string) $this->wpdb->dbname . '|' . (string) $this->wpdb->prefix );

		return substr( 'tiermint_' . $this->lockSpace . '_' . $key . '_' . $scope, 0, 64 );
	}
}
