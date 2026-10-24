<?php
/**
 * Webhook idempotency ledger repository over tiermint_gateway_events.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Payments;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Database\Schema;

/**
 * Stores webhook event claims and statuses.
 *
 * Claim reads are uncached so each worker sees the current status. Not final for processor tests.
 *
 * @since 1.0.0
 */
class GatewayEventRepository {

	/**
	 * Seconds before a processing claim can be reclaimed.
	 *
	 * @var int
	 */
	public const LEASE_SECONDS = 300;

	/**
	 * Lease stamps written by this request, keyed by row id.
	 *
	 * Status updates use the stamp so a stale worker cannot replace a new result.
	 *
	 * @var array<int, string>
	 */
	private array $leases = array();

	/**
	 * Wire the repository with its database handle.
	 *
	 * @since 1.0.0
	 *
	 * @param \wpdb $wpdb The WordPress database abstraction.
	 */
	public function __construct( private readonly \wpdb $wpdb ) {}

	/**
	 * Claim exclusive processing rights for a gateway event.
	 *
	 * Terminal events return null. Failed or stale claims are retried with a conditional update.
	 *
	 * @since 1.0.0
	 *
	 * @param string $gateway   The gateway id the event arrived on, e.g. 'stripe'.
	 * @param string $eventId   The gateway's globally unique event id.
	 * @param string $eventType The gateway's event type.
	 * @param string $payload   The raw webhook body, persisted for replay/debugging.
	 * @return int|null The claimed ledger row id, or null when the event is genuinely
	 *                  already handled (a terminal processed/ignored row) and the caller
	 *                  should acknowledge 200 without processing.
	 *
	 * @throws WebhookRetryException When the claim cannot be taken right now (a ledger
	 *                               fault, or another worker holds a fresh lease), so the
	 *                               caller answers 500 and the gateway retries.
	 */
	public function claim( string $gateway, string $eventId, string $eventType, string $payload ): ?int {
		$id = $this->insertClaim( $gateway, $eventId, $eventType, $payload );

		if ( null !== $id ) {
			return $id;
		}

		$row = $this->find( $gateway, $eventId );

		if ( null === $row ) {
			// The colliding row is not visible to this SELECT yet: a concurrent
			// worker inserted it and has not committed (nothing deletes from this
			// table outside uninstall). Retry the insert once.
			$retryId = $this->insertClaim( $gateway, $eventId, $eventType, $payload );

			if ( null !== $retryId ) {
				return $retryId;
			}

			// Still a duplicate: another worker inserted (and now holds) the row in
			// this window. Retry the delivery rather than dropping it as handled.
			throw $this->retry( 'Gateway event was claimed by a concurrent worker.' );
		}

		return $this->reclaim( $row );
	}

	/**
	 * Mark an event as processed while this request holds its lease.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The ledger row id returned by claim().
	 * @return void
	 */
	public function markProcessed( int $id ): void {
		$this->updateStatus( $id, array( 'status' => 'processed' ) );
	}

	/**
	 * Mark an unhandled event as ignored while this request holds its lease.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The ledger row id returned by claim().
	 * @return void
	 */
	public function markIgnored( int $id ): void {
		$this->updateStatus( $id, array( 'status' => 'ignored' ) );
	}

	/**
	 * Mark an event as failed while this request holds its lease.
	 *
	 * A gateway retry may reclaim the row.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $id    The ledger row id returned by claim().
	 * @param string $error What went wrong, typically the exception class and message.
	 * @return void
	 */
	public function markFailed( int $id, string $error ): void {
		// The error column is TEXT (64KB); truncate defensively so an oversized
		// message cannot make the status write itself fail. mb_strcut cuts on a
		// byte budget without splitting a multibyte character, so a truncated
		// UTF-8 message never lands as an invalid byte sequence in utf8mb4.
		// mbstring is optional on some hosts and WordPress does not polyfill
		// mb_strcut, so fall back to substr: worst case one clipped character,
		// never a fatal inside the failure-recording path.
		$truncated = function_exists( 'mb_strcut' ) ? mb_strcut( $error, 0, 60000 ) : substr( $error, 0, 60000 );

		$this->updateStatus(
			$id,
			array(
				'status' => 'failed',
				'error'  => $truncated,
			)
		);
	}

	/**
	 * Load a raw ledger row for tests and operational tools.
	 *
	 * @since 1.0.0
	 *
	 * @param string $gateway The gateway id.
	 * @param string $eventId The gateway event id.
	 * @return object|null The raw row object, or null when the event was never recorded.
	 */
	public function find( string $gateway, string $eventId ): ?object {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE gateway = %s AND event_id = %s', $this->table(), $gateway, $eventId ) );
	}

	/**
	 * Insert the first claim. A duplicate insert returns zero rows.
	 *
	 * @since 1.0.0
	 *
	 * @param string $gateway   The gateway id.
	 * @param string $eventId   The gateway event id.
	 * @param string $eventType The gateway event type.
	 * @param string $payload   The raw webhook body.
	 * @return int|null The new row id, or null when the row already existed.
	 *
	 * @throws WebhookRetryException When the insert itself faults at the database (as
	 *                               opposed to the ordinary INSERT IGNORE duplicate).
	 */
	private function insertClaim( string $gateway, string $eventId, string $eventType, string $payload ): ?int {
		$wpdb = $this->wpdb;
		$now  = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (gateway, event_id, event_type, payload, status, claimed_at, created_at)' .
				" VALUES (%s, %s, %s, %s, 'processing', %s, %s)",
				$this->table(),
				$gateway,
				$eventId,
				$eventType,
				$payload,
				$now,
				$now
			)
		);

		if ( false === $inserted ) {
			// A real database fault. INSERT IGNORE turns a (gateway, event_id) clash
			// into "0 rows", never false, so false is never the duplicate signal; it
			// must not be mistaken for "already claimed" and answered 200. Surface a
			// retry so the gateway redelivers instead of losing the event.
			throw $this->retry( 'Gateway event claim insert failed at the database.' );
		}

		if ( 1 !== $inserted ) {
			return null;
		}

		$id = (int) $wpdb->insert_id;

		// Remember the lease stamp this claim wrote: the mark* writes are
		// conditional on it, so only the current lease holder can conclude the row.
		$this->leases[ $id ] = $now;

		return $id;
	}

	/**
	 * Reclaim a failed or stale event with a conditional update.
	 *
	 * @since 1.0.0
	 *
	 * @param object $row The existing ledger row for the event.
	 * @return int|null The row id when this caller reclaimed it, or null when the event is
	 *                  genuinely done (a terminal processed/ignored row) and should be
	 *                  acknowledged 200 without processing.
	 *
	 * @throws WebhookRetryException When the reclaim lost a race, the row is held under a
	 *                               fresh lease, the row carries an unknown status, or the
	 *                               update faulted, so the caller answers 500 and the
	 *                               gateway retries.
	 */
	private function reclaim( object $row ): ?int {
		$wpdb = $this->wpdb;
		$id   = (int) $row->id;
		$now  = gmdate( 'Y-m-d H:i:s' );

		switch ( (string) $row->status ) {
			case 'processed':
			case 'ignored':
				// Genuinely done; a re-delivery is answered 200 with no work.
				return null;

			case 'failed':
			case 'received':
				// A prior attempt failed (or a legacy row never got claimed):
				// this retry gets its second chance.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
				$affected = $wpdb->query(
					$wpdb->prepare(
						"UPDATE %i SET status = 'processing', claimed_at = %s, error = NULL" .
						" WHERE id = %d AND status IN ('failed','received')",
						$this->table(),
						$now,
						$id
					)
				);

				if ( 1 === $affected ) {
					$this->leases[ $id ] = $now;

					return $id;
				}

				// 0 rows (another worker reclaimed the row first and now holds it) or
				// false (a database fault): this delivery did not take the claim, so
				// retry rather than 200-ing and risking a dropped event.
				throw $this->retry( 'Gateway event reclaim lost a race or faulted.' );

			case 'processing':
				// Reclaimable only when the lease went stale (or was never
				// stamped): a fresh lease means another worker is mid-handling.
				$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::LEASE_SECONDS );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
				$affected = $wpdb->query(
					$wpdb->prepare(
						"UPDATE %i SET status = 'processing', claimed_at = %s, error = NULL" .
						" WHERE id = %d AND status = 'processing' AND (claimed_at IS NULL OR claimed_at < %s)",
						$this->table(),
						$now,
						$id,
						$cutoff
					)
				);

				if ( 1 === $affected ) {
					$this->leases[ $id ] = $now;

					return $id;
				}

				// A fresh lease (another worker is mid-handling), a lost stale-lease
				// reclaim race, or a database fault: retry so the gateway redelivers
				// until the lease resolves (row becomes processed, or goes stale and
				// is reclaimable), instead of 200-ing and risking a lost event.
				throw $this->retry( 'Gateway event is held under a fresh processing lease.' );

			default:
				// An unknown status can only come from a future schema or a
				// downgrade. Guessing "done" (a 200) could silently drop a live
				// money event; a retry is always safe, and once the site is back
				// on a schema that knows the status the delivery resolves.
				throw $this->retry( 'Gateway event carries an unknown ledger status.' );
		}
	}

	/**
	 * Update a claim status only while this request owns the lease.
	 *
	 * @since 1.0.0
	 *
	 * @param int                  $id   The ledger row id.
	 * @param array<string, mixed> $data Column => value pairs to write.
	 * @return void
	 */
	private function updateStatus( int $id, array $data ): void {
		$where = array(
			'id'     => $id,
			'status' => 'processing',
		);

		if ( isset( $this->leases[ $id ] ) ) {
			$where['claimed_at'] = $this->leases[ $id ];
		}

		$this->wpdb->update( $this->table(), $data, $where );
	}

	/**
	 * Build the retry signal for an unavailable claim.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message A log-only reason, English by design.
	 * @return WebhookRetryException The signal to throw.
	 */
	private function retry( string $message ): WebhookRetryException {
		return new WebhookRetryException( $message );
	}

	/**
	 * Redact raw webhook payloads that contain personal data.
	 *
	 * Keep event identity and status for idempotency and audit.
	 *
	 * @since 1.0.0
	 *
	 * @param string $needle The personal-data fragment to search for (e.g. the buyer email).
	 * @return void
	 */
	public function redactPayloadsContaining( string $needle ): void {
		if ( '' === trim( $needle ) ) {
			return;
		}

		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET payload = \'{"redacted":"personal-data-erasure"}\' WHERE payload LIKE %s',
				$this->table(),
				'%' . $wpdb->esc_like( $needle ) . '%'
			)
		);
	}

	/**
	 * The fully-qualified ledger table name.
	 *
	 * @since 1.0.0
	 *
	 * @return string The prefixed gateway_events table name.
	 */
	private function table(): string {
		return Schema::tableName( $this->wpdb, 'gateway_events' );
	}
}
