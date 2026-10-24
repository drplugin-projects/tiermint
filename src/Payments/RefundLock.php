<?php
/**
 * Per-order advisory lock that serializes refund recording.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Payments;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Serializes refund writes for one order across requests.
 *
 * The lock and protected writes share the `$wpdb` connection. DB splitting must
 * keep them on the write connection.
 *
 * @since 1.0.0
 */
class RefundLock {

	/**
	 * Lock wait time in seconds.
	 *
	 * @var int
	 */
	private const TIMEOUT_SECONDS = 10;

	/**
	 * Wire the lock with its database handle.
	 *
	 * @since 1.0.0
	 *
	 * @param \wpdb $wpdb The WordPress database abstraction.
	 */
	public function __construct( private readonly \wpdb $wpdb ) {}

	/**
	 * Run a callback under the order lock.
	 *
	 * @since 1.0.0
	 *
	 * @template T
	 * @param int              $orderId  The order to serialize refund writes for.
	 * @param callable():mixed $callback The work to run under the lock.
	 * @return mixed The callback's return value.
	 *
	 * @throws RefundLockException When the lock cannot be acquired within the timeout.
	 */
	public function withOrderLock( int $orderId, callable $callback ) {
		$name = $this->lockName( $orderId );
		$wpdb = $this->wpdb;

		// Only a successful claim may write a refund.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Named lock, nothing to cache.
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, self::TIMEOUT_SECONDS ) );

		if ( '1' !== (string) $acquired ) {
			throw new RefundLockException( 'Could not acquire the per-order refund lock; another refund is in progress.' );
		}

		try {
			return $callback();
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Named lock, nothing to cache.
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
	}

	/**
	 * Build a server-wide MySQL lock name for this site and order.
	 *
	 * @since 1.0.0
	 *
	 * @param int $orderId The order id.
	 * @return string The lock name.
	 */
	private function lockName( int $orderId ): string {
		$scope = md5( (string) $this->wpdb->dbname . '|' . (string) $this->wpdb->prefix );

		return substr( 'tiermint_refund_' . $orderId . '_' . $scope, 0, 64 );
	}
}
