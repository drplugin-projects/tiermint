<?php
/**
 * Unified append-only audit logger over the `tiermint_logs` table.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Database;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use Throwable;

/**
 * Appends audit entries without interrupting the requested operation.
 *
 * Uses error_log when the logs table is unavailable.
 *
 * @since 1.0.0
 */
class Logger {

	/**
	 * The WordPress database abstraction.
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * Wire the logger with its database handle.
	 *
	 * @since 1.0.0
	 *
	 * @param \wpdb $wpdb The WordPress database abstraction.
	 */
	public function __construct( \wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Appends one audit entry and logs failures without throwing.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $type       Log stream, e.g. 'license', 'payment', 'download', 'error'.
	 * @param string      $message    Human-readable event description (log-only, deliberately untranslated).
	 * @param array       $context    Structured extra data, JSON-encoded; an empty array is stored as SQL NULL.
	 * @param string|null $objectType The related object's type ('license', 'order', ...), when the entry concerns one.
	 * @param int|null    $objectId   The related object's id, when the entry concerns one.
	 * @param string|null $ipAddress  Originating IP for request-driven events, when known.
	 * @return void
	 */
	public function log(
		string $type,
		string $message,
		array $context = array(),
		?string $objectType = null,
		?int $objectId = null,
		?string $ipAddress = null
	): void {
		try {
			$inserted = $this->wpdb->insert(
				Schema::tableName( $this->wpdb, 'logs' ),
				array(
					'type'        => $type,
					'object_type' => $objectType,
					'object_id'   => $objectId,
					'message'     => $message,
					'context'     => array() === $context ? null : wp_json_encode( $context ),
					'ip_address'  => $ipAddress,
					'created_at'  => gmdate( 'Y-m-d H:i:s' ),
				)
			);

			if ( false === $inserted ) {
				// Keep audit failures out of the customer response.
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- The logs table write failed, so the PHP error log is the only place left.
				error_log( sprintf( 'TierMint: failed to write %s log entry: %s', $type, $message ) );
			}
		} catch ( Throwable $t ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- The logs table write threw, so the PHP error log is the only place left.
			error_log( sprintf( 'TierMint: logger failure (%s) while writing %s entry: %s', $t->getMessage(), $type, $message ) );
		}
	}
}
