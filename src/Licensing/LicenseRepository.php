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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Repository-owned custom table write; caching is handled here.
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
				'parent_id'        => $license->parentId(),
				'created_at'       => $createdAt,
				'updated_at'       => $updatedAt,
			)
		);

		if ( false === $inserted ) {
			// Discriminate the unique-key clash from other faults: if a row now
			// carries this key, the collision is the cause and the caller may
			// retry with a fresh key; anything else is a real write failure.
			$sql = 'SELECT id FROM ' . $this->table() . ' WHERE license_key = %s';
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Values bound via prepare(); a one-off existence probe on the write failure path.
			$existing = $this->wpdb->get_var( $this->wpdb->prepare( $sql, $license->licenseKey() ) );

			if ( null !== $existing ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only message; never echoed as HTML.
				throw DuplicateLicenseKeyException::forKey( $license->licenseKey() );
			}

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only literal; controllers map persistence failures to a generic message.
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

		$sql = 'SELECT * FROM ' . $this->table() . ' WHERE id = %d';
		$row = $this->getRowOrFail( $this->wpdb->prepare( $sql, $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Values bound via prepare(); the SQL embeds only the Schema-derived table name.

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

		$sql = 'SELECT * FROM ' . $this->table() . ' WHERE license_key = %s';
		$row = $this->getRowOrFail( $this->wpdb->prepare( $sql, $licenseKey ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Values bound via prepare(); the SQL embeds only the Schema-derived table name.

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
		$sql  = 'SELECT * FROM ' . $this->table() . ' WHERE order_id = %d ORDER BY id ASC';
		$rows = $this->getResultsOrFail( $this->wpdb->prepare( $sql, $orderId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Values bound via prepare(); the SQL embeds only the Schema-derived table name.

		return array_map( array( $this, 'fromRow' ), $rows );
	}

	/**
	 * Load a customer's top-level licenses, newest first.
	 *
	 * Child seats stay under their organization.
	 *
	 * @since 1.0.0
	 *
	 * @param int $customerId The customer id.
	 * @return array<int, License> The customer's own (non-seat) licenses, newest first.
	 */
	public function findByCustomerId( int $customerId ): array {
		$sql  = 'SELECT * FROM ' . $this->table() . ' WHERE customer_id = %d AND parent_id IS NULL ORDER BY id DESC';
		$rows = $this->getResultsOrFail( $this->wpdb->prepare( $sql, $customerId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Values bound via prepare(); the SQL embeds only the Schema-derived table name.

		return array_map( array( $this, 'fromRow' ), $rows );
	}

	/**
	 * Load an organization's child licenses, oldest first.
	 *
	 * @since 1.0.0
	 *
	 * @param int $orgLicenseId The organization (parent) license id.
	 * @return array<int, License> The child licenses, oldest first.
	 *
	 * @throws LicensePersistenceException When the read faults at the database.
	 */
	public function findByParentId( int $orgLicenseId ): array {
		$sql  = 'SELECT * FROM ' . $this->table() . ' WHERE parent_id = %d ORDER BY id ASC';
		$rows = $this->getResultsOrFail( $this->wpdb->prepare( $sql, $orgLicenseId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Values bound via prepare(); the SQL embeds only the Schema-derived table name.

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
		$args = $this->normalizeArgs( $args );

		list( $where, $params ) = $this->buildFilters( $args );

		$orderby = $this->whitelistOrderby( $args['orderby'] ?? 'created_at' );
		$order   = $this->whitelistOrder( $args['order'] ?? 'DESC' );
		$perPage = $this->perPage( $args );
		$page    = max( 1, (int) ( $args['page'] ?? 1 ) );

		$sql = 'SELECT * FROM ' . $this->table() . $where
			. ' ORDER BY ' . $orderby . ' ' . $order . ', id ' . $order
			. ' LIMIT %d OFFSET %d';

		$params[] = $perPage;
		$params[] = ( $page - 1 ) * $perPage;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Runtime values are bound via prepare(); the SQL interpolates only the Schema-derived table name and a whitelisted orderby/order.
		$rows = $this->getResultsOrFail( $this->wpdb->prepare( $sql, $params ) );

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
		$args = $this->normalizeArgs( $args );

		list( $where, $params ) = $this->buildFilters( $args );

		$sql = 'SELECT COUNT(*) FROM ' . $this->table() . $where;

		if ( array() === $params ) {
			return (int) $this->getVarOrFail( $sql );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Runtime values are bound via prepare(); the SQL interpolates only the Schema-derived table name.
		return (int) $this->getVarOrFail( $this->wpdb->prepare( $sql, $params ) );
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
		$sql    = 'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE customer_id = %d';
		$params = array( $customerId );

		if ( null !== $ignoring ) {
			$sql     .= ' AND status <> %s';
			$params[] = $ignoring->value;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Values bound via prepare(); the SQL embeds only the Schema-derived table name.
		return (int) $this->getVarOrFail( $this->wpdb->prepare( $sql, $params ) );
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

		$placeholders = implode( ',', array_fill( 0, count( $fromStatuses ), '%s' ) );
		$sql          = 'UPDATE ' . $this->table() .
			" SET status = %s, updated_at = %s WHERE id = %d AND status IN ({$placeholders})";

		$values = array_merge(
			array( $to->value, gmdate( 'Y-m-d H:i:s' ), $id ),
			array_map( static fn ( LicenseStatus $s ): string => $s->value, $fromStatuses )
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Values bound via prepare(); cache invalidated below.
		$affected = $this->wpdb->query( $this->wpdb->prepare( $sql, $values ) );

		if ( false === $affected ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only literal; controllers map persistence failures to a generic message.
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
		$id  = $this->requireId( $license );
		$now = gmdate( 'Y-m-d H:i:s' );

		if ( null === $newExpiresAt ) {
			$sql    = 'UPDATE ' . $this->table() .
				" SET status = 'active', expires_at = NULL, updated_at = %s" .
				" WHERE id = %d AND status IN ('active','expired')";
			$values = array( $now, $id );
		} else {
			$sql    = 'UPDATE ' . $this->table() .
				" SET status = 'active', expires_at = %s, updated_at = %s" .
				" WHERE id = %d AND status IN ('active','expired')";
			$values = array( $newExpiresAt, $now, $id );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Values bound via prepare(); cache invalidated below.
		$affected = $this->wpdb->query( $this->wpdb->prepare( $sql, $values ) );

		if ( false === $affected ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only literal; controllers map persistence failures to a generic message.
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
	 * Use literal SQL NULL for nullable unsigned fields. Existing activations remain.
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
		$id  = $this->requireId( $license );
		$now = gmdate( 'Y-m-d H:i:s' );

		$sets   = array();
		$values = array();

		if ( null === $priceId ) {
			$sets[] = 'price_id = NULL';
		} else {
			$sets[]   = 'price_id = %d';
			$values[] = $priceId;
		}

		if ( null === $activationLimit ) {
			$sets[] = 'activation_limit = NULL';
		} else {
			$sets[]   = 'activation_limit = %d';
			$values[] = $activationLimit;
		}

		$sets[]   = 'updated_at = %s';
		$values[] = $now;
		$values[] = $id;

		$sql = 'UPDATE ' . $this->table() . ' SET ' . implode( ', ', $sets ) . ' WHERE id = %d';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Values bound via prepare(); the SQL embeds only the Schema-derived table name and literal NULL assignments; cache invalidated below.
		$affected = $this->wpdb->query( $this->wpdb->prepare( $sql, $values ) );

		if ( false === $affected ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only literal; controllers map persistence failures to a generic message.
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
		$sql = 'UPDATE ' . $this->table() .
			' SET activation_count = activation_count + 1, updated_at = %s' .
			' WHERE id = %d AND (activation_limit IS NULL OR activation_count < activation_limit)';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Values bound via prepare(); cache invalidated below.
		$affected = $this->wpdb->query( $this->wpdb->prepare( $sql, gmdate( 'Y-m-d H:i:s' ), $licenseId ) );

		if ( false === $affected ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only literal; controllers map persistence failures to a generic message.
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
		$sql = 'UPDATE ' . $this->table() .
			' SET activation_count = activation_count - 1, updated_at = %s' .
			' WHERE id = %d AND activation_count > 0';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Values bound via prepare(); cache invalidated below.
		$affected = $this->wpdb->query( $this->wpdb->prepare( $sql, gmdate( 'Y-m-d H:i:s' ), $licenseId ) );

		if ( false === $affected ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only literal; controllers map persistence failures to a generic message.
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
		$sql = 'SELECT * FROM ' . $this->table() .
			" WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at < %s" .
			' ORDER BY expires_at ASC LIMIT %d';

		$rows = $this->getResultsOrFail( $this->wpdb->prepare( $sql, $now, $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Values bound via prepare(); the SQL embeds only the Schema-derived table name.

		return array_map( array( $this, 'fromRow' ), $rows );
	}

	/**
	 * Run a prepared single-row SELECT.
	 *
	 * Distinguish a database error from a missing row.
	 *
	 * @since 1.0.0
	 *
	 * @param string $preparedSql The already-prepared SQL.
	 * @return object|null The row, or null when it genuinely does not exist.
	 *
	 * @throws LicensePersistenceException When the read faulted at the database.
	 */
	private function getRowOrFail( string $preparedSql ): ?object {
		$this->wpdb->last_error = '';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Callers prepare the SQL; the repository fronts reads with wp_cache where the hot path needs it.
		$row = $this->wpdb->get_row( $preparedSql );

		if ( '' !== $this->wpdb->last_error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only literal; controllers map persistence failures to a generic message.
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
	 * @param string $preparedSql The already-prepared SQL.
	 * @return array<int, object> The rows (possibly empty).
	 *
	 * @throws LicensePersistenceException When the read faulted at the database.
	 */
	private function getResultsOrFail( string $preparedSql ): array {
		$this->wpdb->last_error = '';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Callers prepare the SQL; list reads are deliberately uncached.
		$rows = $this->wpdb->get_results( $preparedSql );

		if ( '' !== $this->wpdb->last_error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only literal; controllers map persistence failures to a generic message.
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
	 * @param string $preparedSql The already-prepared SQL.
	 * @return string|null The scalar value, or null when the query genuinely returned none.
	 *
	 * @throws LicensePersistenceException When the read faulted at the database.
	 */
	private function getVarOrFail( string $preparedSql ): ?string {
		$this->wpdb->last_error = '';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Callers prepare the SQL; a bounded admin count, deliberately uncached.
		$value = $this->wpdb->get_var( $preparedSql );

		if ( '' !== $this->wpdb->last_error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only literal; controllers map persistence failures to a generic message.
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
	 * Build indexed filters for the collection query.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $args The query args.
	 * @return array{0: string, 1: array<int, mixed>} The clause (with leading space) and params.
	 */
	private function buildFilters( array $args ): array {
		$clauses = array();
		$params  = array();

		if ( isset( $args['status'] ) && '' !== (string) $args['status'] ) {
			$status = (string) $args['status'];

			if ( null !== LicenseStatus::tryFrom( $status ) ) {
				$clauses[] = 'status = %s';
				$params[]  = $status;
			}
		}

		if ( isset( $args['product_id'] ) && (int) $args['product_id'] > 0 ) {
			$clauses[] = 'product_id = %d';
			$params[]  = (int) $args['product_id'];
		}

		if ( isset( $args['customer_id'] ) && (int) $args['customer_id'] > 0 ) {
			$clauses[] = 'customer_id = %d';
			$params[]  = (int) $args['customer_id'];
		}

		if ( isset( $args['search'] ) && '' !== trim( (string) $args['search'] ) ) {
			$search           = trim( (string) $args['search'] );
			$searchCustomerId = isset( $args['search_customer_id'] ) ? (int) $args['search_customer_id'] : 0;

			// The search term is matched as an exact license key, and, when the controller
			// resolved it to a customer email, that customer's id is OR-ed in. Both sides are
			// index-backed, so the search never degrades into a table scan.
			if ( $searchCustomerId > 0 ) {
				$clauses[] = '(license_key = %s OR customer_id = %d)';
				$params[]  = $search;
				$params[]  = $searchCustomerId;
			} else {
				$clauses[] = 'license_key = %s';
				$params[]  = $search;
			}
		}

		$where = array() === $clauses ? '' : ' WHERE ' . implode( ' AND ', $clauses );

		return array( $where, $params );
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
			null === $row->parent_id ? null : (int) $row->parent_id,
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
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer message; never echoed as HTML.
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
