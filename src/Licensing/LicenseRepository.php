<?php
/**
 * Licenses repository: the only Licensing class that touches the licenses table.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Licensing;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DateTimeImmutable;
use DateTimeZone;
use DrPlugin\WP\TierMint\Database\Schema;

/**
 * License storage with cache-backed key lookups.
 *
 * Guarded writes prevent competing status changes and slot claims.
 *
 * @since 1.0.0
 */
class LicenseRepository {

	/**
	 * The object-cache group licenses are cached under.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'tiermint_licenses';

	/**
	 * The WordPress database abstraction.
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * Wire the repository with its database handle.
	 *
	 * @since 1.0.0
	 *
	 * @param \wpdb $wpdb The WordPress database abstraction.
	 */
	public function __construct( \wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Insert a license with UTC timestamps.
	 *
	 * @since 1.0.0
	 *
	 * @param License $license The license to persist.
	 * @return int The new license id.
	 *
	 * @throws DuplicateLicenseKeyException When the key collides with an existing license
	 *                                      (the generation loop retries with a fresh key).
	 * @throws LicensePersistenceException  When the insert fails for any other reason.
	 */
	public function create( License $license ): int {
		$now = gmdate( 'Y-m-d H:i:s' );

		// A freshly minted license carries no timestamps and is stamped now. An
		// imported historical license carries its source created_at, which is kept;
		// updated_at follows the source's updated_at when present, else its
		// created_at, else now, so a migrated row never reads as touched today.
		$createdAt = $this->formatInstant( $license->createdAt() ) ?? $now;
		$updatedAt = $this->formatInstant( $license->updatedAt() )
			?? $this->formatInstant( $license->createdAt() )
			?? $now;

		$inserted = $this->wpdb->insert(
			$this->table(),
			array(
				'license_key'      => $license->licenseKey(),
				'status'           => $license->status()->value,
				'product_id'       => $license->productId(),
				'price_id'         => $license->priceId(),
				'order_id'         => $license->orderId(),
				'order_item_id'    => $license->orderItemId(),
				'customer_id'      => $license->customerId(),
				'activation_limit' => $license->activationLimit(),
				'activation_count' => $license->activationCount(),
				'expires_at'       => $this->formatInstant( $license->expiresAt() ),
				'created_at'       => $createdAt,
				'updated_at'       => $updatedAt,
			)
		);

		if ( false === $inserted ) {
			// Discriminate the unique-key clash from other faults: if a row now
			// carries this key, the collision is the cause and the caller may
			// retry with a fresh key; anything else is a real write failure.
			$wpdb = $this->wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
			$existing = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE license_key = %s', $this->table(), $license->licenseKey() ) );

			if ( null !== $existing ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
				throw DuplicateLicenseKeyException::forKey( $license->licenseKey() );
			}

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new LicensePersistenceException( 'Failed to insert license: ' . $this->wpdb->last_error );
		}

		$id = (int) $this->wpdb->insert_id;

		$this->forget( $id, $license->licenseKey() );

		return $id;
	}

	/**
	 * Load a license by id, reading through the object cache.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The license id.
	 * @return License|null The license, or null when it does not exist.
	 */
	public function find( int $id ): ?License {
		$cached = wp_cache_get( $this->idKey( $id ), self::CACHE_GROUP );

		if ( $cached instanceof License ) {
			return $cached;
		}

		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$row = $this->getRowOrFail( fn () => $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table(), $id ) ) );

		if ( null === $row ) {
			return null;
		}

		$license = $this->fromRow( $row );
		$this->cache( $license );

		return $license;
	}

	/**
	 * Load a license by key through the object cache.
	 *
	 * @since 1.0.0
	 *
	 * @param string $licenseKey The license key as presented by the client.
	 * @return License|null The license, or null when no license carries the key.
	 */
	public function findByKey( string $licenseKey ): ?License {
		$id = wp_cache_get( $this->keyKey( $licenseKey ), self::CACHE_GROUP );

		if ( is_int( $id ) ) {
			$license = $this->find( $id );

			// The lookup entry can outlive the row (or point at a re-keyed
			// license); trust it only when the loaded entity still matches.
			if ( null !== $license && hash_equals( $license->licenseKey(), $licenseKey ) ) {
				return $license;
			}
		}

		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$row = $this->getRowOrFail( fn () => $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE license_key = %s', $this->table(), $licenseKey ) ) );

		if ( null === $row ) {
			return null;
		}

		$license = $this->fromRow( $row );
		$this->cache( $license );

		return $license;
	}

	/**
	 * Load an order's licenses, oldest first.
	 *
	 * @since 1.0.0
	 *
	 * @param int $orderId The order id.
	 * @return array<int, License> The order's licenses, oldest first.
	 */
	public function findByOrderId( int $orderId ): array {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$rows = $this->getResultsOrFail( fn () => $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d ORDER BY id ASC', $this->table(), $orderId ) ) );

		return array_map( array( $this, 'fromRow' ), $rows );
	}

	/**
	 * Load every license of a customer, newest first.
	 *
	 * @since 1.0.0
	 *
	 * @param int $customerId The customer id.
	 * @return array<int, License> The customer's licenses, newest first.
	 */
	public function findByCustomerId( int $customerId ): array {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$rows = $this->getResultsOrFail( fn () => $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE customer_id = %d ORDER BY id DESC', $this->table(), $customerId ) ) );

		return array_map( array( $this, 'fromRow' ), $rows );
	}

	/**
	 * Query licenses for the admin collection.
	 *
	 * Filters use indexed columns. Sort columns and directions are whitelisted.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $args The query args.
	 * @return array<int, License> The matching, hydrated licenses.
	 *
	 * @throws LicensePersistenceException When the read faults at the database.
	 */
	public function query( array $args ): array {
		$args    = $this->normalizeArgs( $args );
		$orderby = $this->whitelistOrderby( $args['orderby'] ?? 'created_at' );
		$perPage = $this->perPage( $args );
		$offset  = ( max( 1, (int) ( $args['page'] ?? 1 ) ) - 1 ) * $perPage;
		$wpdb    = $this->wpdb;

		list( $status, $productId, $customerId, $bySearch, $search, $searchCustomerId ) = $this->filters( $args );

		// The WHERE is the one in countQuery(); keep the copies in step.
		if ( 'ASC' === $this->whitelistOrder( $args['order'] ?? 'DESC' ) ) {
			$rows = $this->getResultsOrFail(
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
				fn () => $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM %i WHERE ( %s = '' OR status = %s ) AND ( %d = 0 OR product_id = %d )" .
						' AND ( %d = 0 OR customer_id = %d )' .
						' AND ( %d = 0 OR license_key = %s OR ( %d > 0 AND customer_id = %d ) )' .
						' ORDER BY %i ASC, id ASC LIMIT %d OFFSET %d',
						$this->table(),
						$status,
						$status,
						$productId,
						$productId,
						$customerId,
						$customerId,
						$bySearch,
						$search,
						$searchCustomerId,
						$searchCustomerId,
						$orderby,
						$perPage,
						$offset
					)
				)
			);
		} else {
			$rows = $this->getResultsOrFail(
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
				fn () => $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM %i WHERE ( %s = '' OR status = %s ) AND ( %d = 0 OR product_id = %d )" .
						' AND ( %d = 0 OR customer_id = %d )' .
						' AND ( %d = 0 OR license_key = %s OR ( %d > 0 AND customer_id = %d ) )' .
						' ORDER BY %i DESC, id DESC LIMIT %d OFFSET %d',
						$this->table(),
						$status,
						$status,
						$productId,
						$productId,
						$customerId,
						$customerId,
						$bySearch,
						$search,
						$searchCustomerId,
						$searchCustomerId,
						$orderby,
						$perPage,
						$offset
					)
				)
			);
		}

		return array_map( array( $this, 'fromRow' ), $rows );
	}

	/**
	 * Count licenses for admin pagination.
	 *
	 * A read error is not an empty result.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $args The query args (status, product_id, customer_id, search).
	 * @return int The total matching license count.
	 *
	 * @throws LicensePersistenceException When the read faults at the database.
	 */
	public function countQuery( array $args ): int {
		$wpdb = $this->wpdb;

		list( $status, $productId, $customerId, $bySearch, $search, $searchCustomerId ) = $this->filters( $this->normalizeArgs( $args ) );

		return (int) $this->getVarOrFail(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
			fn () => $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i WHERE ( %s = '' OR status = %s ) AND ( %d = 0 OR product_id = %d )" .
					' AND ( %d = 0 OR customer_id = %d )' .
					' AND ( %d = 0 OR license_key = %s OR ( %d > 0 AND customer_id = %d ) )',
					$this->table(),
					$status,
					$status,
					$productId,
					$productId,
					$customerId,
					$customerId,
					$bySearch,
					$search,
					$searchCustomerId,
					$searchCustomerId
				)
			)
		);
	}

	/**
	 * Count a customer's licenses, optionally ignoring one status.
	 *
	 * Do not apply collection filters to this delete guard.
	 *
	 * @since 1.0.0
	 *
	 * @param int                $customerId The customer to count licenses for.
	 * @param LicenseStatus|null $ignoring   A status to leave out of the count.
	 * @return int The number of matching licenses.
	 *
	 * @throws LicensePersistenceException When the read faults at the database.
	 */
	public function countForCustomer( int $customerId, ?LicenseStatus $ignoring = null ): int {
		$wpdb = $this->wpdb;

		if ( null === $ignoring ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
			return (int) $this->getVarOrFail( fn () => $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE customer_id = %d', $this->table(), $customerId ) ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		return (int) $this->getVarOrFail( fn () => $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE customer_id = %d AND status <> %s', $this->table(), $customerId, $ignoring->value ) ) );
	}

	/**
	 * Change status only from an allowed source status.
	 *
	 * The guarded update lets one competing writer own transition hooks.
	 *
	 * @since 1.0.0
	 *
	 * @param License                   $license      The license to transition (its stored status is not trusted).
	 * @param array<int, LicenseStatus> $fromStatuses The statuses the move is legal from
	 *                                                (typically {@see LicenseStateMachine::sourcesFor()}).
	 * @param LicenseStatus             $to           The status to move to.
	 * @return bool True when this call made the transition; false when a concurrent
	 *              writer got there first. An unsaved entity raises LicenseException
	 *              from the id guard.
	 *
	 * @throws LicensePersistenceException When the write faults at the database.
	 */
	public function transitionStatus( License $license, array $fromStatuses, LicenseStatus $to ): bool {
		$id = $this->requireId( $license );

		$wpdb   = $this->wpdb;
		$values = array_merge(
			array( $this->table(), $to->value, gmdate( 'Y-m-d H:i:s' ), $id ),
			array_map( static fn ( LicenseStatus $s ): string => $s->value, $fromStatuses )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$affected = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, updated_at = %s WHERE id = %d AND status IN (' .
				implode( ',', array_fill( 0, count( $fromStatuses ), '%s' ) ) . ')',
				$values
			)
		);

		if ( false === $affected ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new LicensePersistenceException( 'Failed to transition license status: ' . $this->wpdb->last_error );
		}

		$this->forget( $id, $license->licenseKey() );

		return 1 === $affected;
	}

	/**
	 * Renew an active or expired license.
	 *
	 * A renewal cannot revive a disabled license.
	 *
	 * @since 1.0.0
	 *
	 * @param License     $license      The license to renew.
	 * @param string|null $newExpiresAt The new expiry as a UTC MySQL DATETIME, or null for lifetime.
	 * @return bool True when the renewal landed; false when the license was disabled
	 *              (or vanished) and nothing changed. An unsaved entity raises
	 *              LicenseException from the id guard.
	 *
	 * @throws LicensePersistenceException When the write faults at the database.
	 */
	public function renew( License $license, ?string $newExpiresAt ): bool {
		$id   = $this->requireId( $license );
		$now  = gmdate( 'Y-m-d H:i:s' );
		$wpdb = $this->wpdb;

		if ( null === $newExpiresAt ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
			$affected = $wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET status = 'active', expires_at = NULL, updated_at = %s" .
					" WHERE id = %d AND status IN ('active','expired')",
					$this->table(),
					$now,
					$id
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
			$affected = $wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET status = 'active', expires_at = %s, updated_at = %s" .
					" WHERE id = %d AND status IN ('active','expired')",
					$this->table(),
					$newExpiresAt,
					$now,
					$id
				)
			);
		}

		if ( false === $affected ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new LicensePersistenceException( 'Failed to renew license: ' . $this->wpdb->last_error );
		}

		$this->forget( $id, $license->licenseKey() );

		if ( $affected > 0 ) {
			return true;
		}

		// Zero affected rows is ambiguous under mysqli changed-rows semantics:
		// either the guard refused (the license is disabled, the true failure)
		// or the write was identical to the stored row within the same second
		// (a double-submitted renewal of, say, a lifetime license changes no
		// column and reports 0). Re-read to tell them apart, so an idempotent
		// replay is a success and never misreported as "disabled".
		$fresh = $this->find( $id );

		if ( null === $fresh || LicenseStatus::Active !== $fresh->status() ) {
			return false;
		}

		$storedExpiry = null === $fresh->expiresAt()
			? null
			: $fresh->expiresAt()->format( 'Y-m-d H:i:s' );

		return $storedExpiry === $newExpiresAt;
	}

	/**
	 * Update a license's price tier and activation cap.
	 *
	 * A null tier or cap is stored as NULL, never as 0. Existing activations remain.
	 *
	 * @since 1.0.0
	 *
	 * @param License  $license         The license to re-point (its stored values are not trusted).
	 * @param int|null $priceId         The new price tier id, or null for no explicit tier.
	 * @param int|null $activationLimit The new activation cap, or null for unlimited.
	 * @return bool True when the tier was applied (or already matched); false when the
	 *              row vanished. An unsaved entity raises LicenseException from the id guard.
	 *
	 * @throws LicensePersistenceException When the write faults at the database.
	 */
	public function changeTier( License $license, ?int $priceId, ?int $activationLimit ): bool {
		$id = $this->requireId( $license );

		$affected = $this->wpdb->update(
			$this->table(),
			array(
				'price_id'         => $priceId,
				'activation_limit' => $activationLimit,
				'updated_at'       => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $id ),
			array( '%d', '%d', '%s' ),
			array( '%d' )
		);

		if ( false === $affected ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new LicensePersistenceException( 'Failed to change license tier: ' . $this->wpdb->last_error );
		}

		$this->forget( $id, $license->licenseKey() );

		if ( $affected > 0 ) {
			return true;
		}

		// Zero affected rows under changed-rows semantics is ambiguous: either the
		// row is gone, or the re-applied tier was identical within the same second.
		// Re-read to tell them apart so an idempotent replay reports success.
		$fresh = $this->find( $id );

		return null !== $fresh && $fresh->priceId() === $priceId && $fresh->activationLimit() === $activationLimit;
	}

	/**
	 * Claim one activation slot with a guarded counter update.
	 *
	 * @since 1.0.0
	 *
	 * @param int $licenseId The license id.
	 * @return bool True when a slot was claimed; false when the limit is reached.
	 *
	 * @throws LicensePersistenceException When the write faults at the database.
	 */
	public function claimActivationSlot( int $licenseId ): bool {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$affected = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET activation_count = activation_count + 1, updated_at = %s' .
				' WHERE id = %d AND (activation_limit IS NULL OR activation_count < activation_limit)',
				$this->table(),
				gmdate( 'Y-m-d H:i:s' ),
				$licenseId
			)
		);

		if ( false === $affected ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new LicensePersistenceException( 'Failed to claim an activation slot: ' . $this->wpdb->last_error );
		}

		$this->forgetById( $licenseId );

		return 1 === $affected;
	}

	/**
	 * Release one claimed activation slot.
	 *
	 * The guard prevents an unsigned counter underflow.
	 *
	 * @since 1.0.0
	 *
	 * @param int $licenseId The license id.
	 * @return void
	 *
	 * @throws LicensePersistenceException When the write faults at the database.
	 */
	public function releaseActivationSlot( int $licenseId ): void {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$affected = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET activation_count = activation_count - 1, updated_at = %s WHERE id = %d AND activation_count > 0',
				$this->table(),
				gmdate( 'Y-m-d H:i:s' ),
				$licenseId
			)
		);

		if ( false === $affected ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new LicensePersistenceException( 'Failed to release an activation slot: ' . $this->wpdb->last_error );
		}

		$this->forgetById( $licenseId );
	}

	/**
	 * Load active licenses due for expiry.
	 *
	 * @since 1.0.0
	 *
	 * @param string $now   The cutoff as a UTC MySQL DATETIME.
	 * @param int    $limit The maximum rows per batch.
	 * @return array<int, License> The due licenses, oldest expiry first.
	 */
	public function findDueForExpiry( string $now, int $limit ): array {
		$wpdb = $this->wpdb;
		$rows = $this->getResultsOrFail(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
			fn () => $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM %i WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at < %s" .
					' ORDER BY expires_at ASC LIMIT %d',
					$this->table(),
					$now,
					$limit
				)
			)
		);

		return array_map( array( $this, 'fromRow' ), $rows );
	}

	/**
	 * Run a prepared single-row SELECT.
	 *
	 * Distinguish a database error from a missing row.
	 *
	 * @since 1.0.0
	 *
	 * @param callable(): mixed $query Runs the prepared query.
	 * @return object|null The row, or null when it genuinely does not exist.
	 *
	 * @throws LicensePersistenceException When the read faulted at the database.
	 */
	private function getRowOrFail( callable $query ): ?object {
		$this->wpdb->last_error = '';

		$row = $query();

		if ( '' !== $this->wpdb->last_error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new LicensePersistenceException( 'License read failed: ' . $this->wpdb->last_error );
		}

		return $row;
	}

	/**
	 * Run a prepared multi-row SELECT with the same fault discrimination as
	 * {@see getRowOrFail()}.
	 *
	 * @since 1.0.0
	 *
	 * @param callable(): mixed $query Runs the prepared query.
	 * @return array<int, object> The rows (possibly empty).
	 *
	 * @throws LicensePersistenceException When the read faulted at the database.
	 */
	private function getResultsOrFail( callable $query ): array {
		$this->wpdb->last_error = '';

		$rows = $query();

		if ( '' !== $this->wpdb->last_error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new LicensePersistenceException( 'License read failed: ' . $this->wpdb->last_error );
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Run a prepared scalar SELECT.
	 *
	 * Distinguish a database error from an empty result.
	 *
	 * @since 1.0.0
	 *
	 * @param callable(): mixed $query Runs the prepared query.
	 * @return string|null The scalar value, or null when the query genuinely returned none.
	 *
	 * @throws LicensePersistenceException When the read faulted at the database.
	 */
	private function getVarOrFail( callable $query ): ?string {
		$this->wpdb->last_error = '';

		$value = $query();

		if ( '' !== $this->wpdb->last_error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new LicensePersistenceException( 'License count failed: ' . $this->wpdb->last_error );
		}

		return null === $value ? null : (string) $value;
	}

	/**
	 * Apply the shared collection-query filter.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $args The raw query args.
	 * @return array<string, mixed> The normalized query args.
	 */
	private function normalizeArgs( array $args ): array {
		/**
 * Filter normalized license-query arguments.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $args The license-query args about to build SQL.
		 */
		return (array) apply_filters( 'tiermint_license_query_args', $args );
	}

	/**
	 * Read the collection filters for the fixed WHERE in query() and countQuery().
	 *
	 * An empty status or a 0 id or flag turns a filter off. The search has its own
	 * flag because MySQL collations compare a zero-width space equal to ''. MySQL
	 * drops a clause that is off before it plans the query, so the indexes still apply.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $args The query args.
	 * @return array{0: string, 1: int, 2: int, 3: int, 4: string, 5: int} Status, product id,
	 *         customer id, search flag, search term and search customer id.
	 */
	private function filters( array $args ): array {
		$status = isset( $args['status'] ) ? (string) $args['status'] : '';
		$search = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';

		// The search term is matched as an exact license key, and, when the controller
		// resolved it to a customer email, that customer's id is OR-ed in. Both sides are
		// index-backed, so the search never degrades into a table scan.
		return array(
			null !== LicenseStatus::tryFrom( $status ) ? $status : '',
			isset( $args['product_id'] ) ? max( 0, (int) $args['product_id'] ) : 0,
			isset( $args['customer_id'] ) ? max( 0, (int) $args['customer_id'] ) : 0,
			'' === $search ? 0 : 1,
			$search,
			isset( $args['search_customer_id'] ) ? max( 0, (int) $args['search_customer_id'] ) : 0,
		);
	}

	/**
	 * Whitelist an admin collection sort column.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $orderby The requested orderby.
	 * @return string A safe column name.
	 */
	private function whitelistOrderby( $orderby ): string {
		$allowed = array( 'id', 'created_at', 'expires_at' );

		return in_array( (string) $orderby, $allowed, true ) ? (string) $orderby : 'created_at';
	}

	/**
	 * Whitelist the sort direction, defaulting to DESC.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $order The requested direction.
	 * @return string 'ASC' or 'DESC'.
	 */
	private function whitelistOrder( $order ): string {
		return 'ASC' === strtoupper( (string) $order ) ? 'ASC' : 'DESC';
	}

	/**
	 * Return a collection page size from 1 to 100.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $args The query args.
	 * @return int The clamped per-page size.
	 */
	private function perPage( array $args ): int {
		$perPage = (int) ( $args['per_page'] ?? 10 );

		if ( $perPage < 1 ) {
			$perPage = 10;
		}

		return min( $perPage, 100 );
	}

	/**
	 * Hydrate a license from UTC database values.
	 *
	 * @since 1.0.0
	 *
	 * @param object $row The raw database row.
	 * @return License The hydrated license.
	 */
	private function fromRow( object $row ): License {
		return new License(
			(int) $row->id,
			(string) $row->license_key,
			LicenseStatus::from( (string) $row->status ),
			(int) $row->product_id,
			null === $row->price_id ? null : (int) $row->price_id,
			(int) $row->order_id,
			null === $row->order_item_id ? null : (int) $row->order_item_id,
			(int) $row->customer_id,
			null === $row->activation_limit ? null : (int) $row->activation_limit,
			(int) $row->activation_count,
			$this->parseInstant( $row->expires_at ),
			$this->parseInstant( $row->created_at ),
			$this->parseInstant( $row->updated_at )
		);
	}

	/**
	 * Parse a stored UTC DATETIME string into an instant.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $value The stored value.
	 * @return DateTimeImmutable|null The instant, or null.
	 */
	private function parseInstant( ?string $value ): ?DateTimeImmutable {
		if ( null === $value || '' === $value ) {
			return null;
		}

		return new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Format an instant as a UTC MySQL DATETIME string for storage.
	 *
	 * @since 1.0.0
	 *
	 * @param DateTimeImmutable|null $instant The instant, or null.
	 * @return string|null The storable string, or null.
	 */
	private function formatInstant( ?DateTimeImmutable $instant ): ?string {
		if ( null === $instant ) {
			return null;
		}

		return $instant->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * The id a write requires, or a domain error when the entity is unsaved.
	 *
	 * @since 1.0.0
	 *
	 * @param License $license The license.
	 * @return int The license id.
	 *
	 * @throws LicenseException When the license was never persisted.
	 */
	private function requireId( License $license ): int {
		$id = $license->id();

		if ( null === $id ) {
			throw new LicenseException( 'Cannot write a license that has not been persisted.' );
		}

		return $id;
	}

	/**
	 * Cache a license under its id and register the key-to-id lookup entry.
	 *
	 * @since 1.0.0
	 *
	 * @param License $license The license to cache.
	 * @return void
	 */
	private function cache( License $license ): void {
		wp_cache_set( $this->idKey( (int) $license->id() ), $license, self::CACHE_GROUP );
		wp_cache_set( $this->keyKey( $license->licenseKey() ), (int) $license->id(), self::CACHE_GROUP );
	}

	/**
	 * Invalidate the cache entries for a license identity.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $id         The license id.
	 * @param string $licenseKey The license key.
	 * @return void
	 */
	private function forget( int $id, string $licenseKey ): void {
		wp_cache_delete( $this->idKey( $id ), self::CACHE_GROUP );
		wp_cache_delete( $this->keyKey( $licenseKey ), self::CACHE_GROUP );
	}

	/**
	 * Invalidate a license's id cache entry.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The license id.
	 * @return void
	 */
	private function forgetById( int $id ): void {
		wp_cache_delete( $this->idKey( $id ), self::CACHE_GROUP );
	}

	/**
	 * The cache key for a license id.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The license id.
	 * @return string The cache key.
	 */
	private function idKey( int $id ): string {
		return 'id_' . $id;
	}

	/**
	 * The cache key for a license key's id lookup entry.
	 *
	 * @since 1.0.0
	 *
	 * @param string $licenseKey The license key.
	 * @return string The cache key.
	 */
	private function keyKey( string $licenseKey ): string {
		return 'key_' . md5( $licenseKey );
	}

	/**
	 * The fully-qualified licenses table name.
	 *
	 * @since 1.0.0
	 *
	 * @return string The prefixed licenses table name.
	 */
	private function table(): string {
		return Schema::tableName( $this->wpdb, 'licenses' );
	}
}
