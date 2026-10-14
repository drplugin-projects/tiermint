<?php
/**
 * Discounts repository: the only Discounts class that touches $wpdb.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Discounts;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Database\Schema;

/**
 * Store discounts and cache their IDs and codes.
 *
 * @since 1.0.0
 */
class DiscountRepository {

	/**
	 * The object-cache group discounts are cached under.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'tiermint_discounts';

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
	 * Insert a discount, stamping its timestamps in UTC, and return the new id.
	 *
	 * @since 1.0.0
	 *
	 * @param Discount $discount The discount to persist.
	 * @return int The new discount id.
	 *
	 * @throws DiscountPersistenceException When the insert fails (including a unique-code
	 *                                      clash, which the service re-reads and classifies).
	 */
	public function create( Discount $discount ): int {
		$now  = gmdate( 'Y-m-d H:i:s' );
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Insert into a Schema-defined table; $wpdb->insert escapes every value; there is nothing to cache on a write.
		$inserted = $wpdb->insert(
			$this->table(),
			array_merge(
				$discount->toRow(),
				array(
					'created_at' => $now,
					'updated_at' => $now,
				)
			)
		);

		if ( false === $inserted ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only literal; controllers map persistence failures to a generic message.
			throw new DiscountPersistenceException( 'Failed to insert a discount: ' . $wpdb->last_error );
		}

		$id = (int) $wpdb->insert_id;

		$this->forget( $id, $discount->code() );

		return $id;
	}

	/**
	 * Update editable discount fields and clear old cache keys.
	 *
	 * The atomic increment owns use_count, so a stale form cannot overwrite it.
	 *
	 * @since 1.0.0
	 *
	 * @param Discount $discount The discount to persist (must carry an id).
	 * @return void
	 *
	 * @throws DiscountException            When the discount has no id to update.
	 * @throws DiscountPersistenceException When the update fails at the database.
	 */
	public function update( Discount $discount ): void {
		$id = $discount->id();

		if ( null === $id ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer message; never echoed as HTML.
			throw new DiscountException( __( 'Cannot update a discount that has no id.', 'tiermint' ) );
		}

		$previous = $this->find( $id );

		$data = $discount->toRow();
		// The use count is owned by the atomic increment, never by a full-row write.
		unset( $data['use_count'] );
		$data['updated_at'] = gmdate( 'Y-m-d H:i:s' );

		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Update on a Schema-defined table; $wpdb->update escapes values and conditions; the write path invalidates the cache below.
		$updated = $wpdb->update( $this->table(), $data, array( 'id' => $id ) );

		if ( false === $updated ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only literal; controllers map persistence failures to a generic message.
			throw new DiscountPersistenceException( 'Failed to update a discount: ' . $wpdb->last_error );
		}

		// A code change must clear both code lookup keys.
		if ( null !== $previous ) {
			$this->forget( null, $previous->code() );
		}

		$this->forget( $id, $discount->code() );
	}

	/**
	 * Delete a discount and clear its caches.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The discount id to delete.
	 * @return void
	 *
	 * @throws DiscountPersistenceException When the delete faults at the database.
	 */
	public function delete( int $id ): void {
		$existing = $this->find( $id );
		$wpdb     = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Delete on a Schema-defined table; $wpdb->delete escapes the conditions; the cache is invalidated below.
		$deleted = $wpdb->delete( $this->table(), array( 'id' => $id ) );

		if ( false === $deleted ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only literal; controllers map persistence failures to a generic message.
			throw new DiscountPersistenceException( 'Failed to delete a discount: ' . $wpdb->last_error );
		}

		$this->forget( $id, null !== $existing ? $existing->code() : null );
	}

	/**
	 * Load a discount by id, reading through the object cache.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The discount id.
	 * @return Discount|null The discount, or null when it does not exist.
	 *
	 * @throws DiscountPersistenceException When the read faults at the database.
	 */
	public function find( int $id ): ?Discount {
		$cached = wp_cache_get( $this->idKey( $id ), self::CACHE_GROUP );

		if ( $cached instanceof Discount ) {
			return $cached;
		}

		$row = $this->getRowOrFail(
			'SELECT * FROM ' . $this->table() . ' WHERE id = %d',
			array( $id )
		);

		if ( null === $row ) {
			return null;
		}

		$discount = Discount::fromRow( (array) $row );
		$this->cache( $discount );

		return $discount;
	}

	/**
	 * Load a discount by code through the object cache.
	 *
	 * The database matches code case-insensitively.
	 *
	 * @since 1.0.0
	 *
	 * @param string $code The code to look up.
	 * @return Discount|null The discount, or null when no discount holds the code.
	 *
	 * @throws DiscountPersistenceException When the read faults at the database.
	 */
	public function findByCode( string $code ): ?Discount {
		$trimmed = trim( $code );

		if ( '' === $trimmed ) {
			return null;
		}

		$cached = wp_cache_get( $this->codeKey( $trimmed ), self::CACHE_GROUP );

		if ( $cached instanceof Discount ) {
			return $cached;
		}

		$row = $this->getRowOrFail(
			'SELECT * FROM ' . $this->table() . ' WHERE code = %s',
			array( $trimmed )
		);

		if ( null === $row ) {
			return null;
		}

		$discount = Discount::fromRow( (array) $row );
		$this->cache( $discount );

		return $discount;
	}

	/**
	 * Load active automatic discounts, oldest first.
	 *
	 * The resolver uses this stable order to break ties. Collection reads are uncached.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, Discount> The active automatic discounts, oldest first.
	 *
	 * @throws DiscountPersistenceException When the read faults at the database.
	 */
	public function activeAutomatic(): array {
		$rows = $this->getResultsOrFail(
			'SELECT * FROM ' . $this->table() . ' WHERE auto = 1 AND status = %s ORDER BY id ASC',
			array( DiscountStatus::Active->value )
		);

		return array_map( static fn ( object $r ): Discount => Discount::fromRow( (array) $r ), $rows );
	}

	/**
	 * Atomically add one use to a discount.
	 *
	 * Limits are checked before payment. A completed order must still record its use.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The discount id.
	 * @return void
	 *
	 * @throws DiscountPersistenceException When the increment faults at the database.
	 */
	public function incrementUseCount( int $id ): void {
		$existing = $this->find( $id );
		$wpdb     = $this->wpdb;

		$sql = 'UPDATE ' . $this->table() . ' SET use_count = use_count + 1, updated_at = %s WHERE id = %d';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Values bound via prepare(); the cache is invalidated below.
		$affected = $wpdb->query( $wpdb->prepare( $sql, gmdate( 'Y-m-d H:i:s' ), $id ) );

		$this->forget( $id, null !== $existing ? $existing->code() : null );

		if ( false === $affected ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only literal; controllers map persistence failures to a generic message.
			throw new DiscountPersistenceException( 'Failed to increment a discount use count: ' . $wpdb->last_error );
		}
	}

	/**
	 * Query discounts for a collection view.
	 *
	 * Supports status, search, pagination and whitelisted sorting.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $args The query args.
	 * @return array<int, Discount> The matching, hydrated discounts.
	 *
	 * @throws DiscountPersistenceException When the read faults at the database.
	 */
	public function query( array $args ): array {
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

		$rows = $this->getResultsOrFail( $sql, $params );

		return array_map( static fn ( object $r ): Discount => Discount::fromRow( (array) $r ), $rows );
	}

	/**
	 * Count discounts matching a collection query (for pagination).
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $args The query args (status, search).
	 * @return int The total matching discount count.
	 *
	 * @throws DiscountPersistenceException When the read faults at the database.
	 */
	public function countQuery( array $args ): int {
		list( $where, $params ) = $this->buildFilters( $args );

		$sql = 'SELECT COUNT(*) FROM ' . $this->table() . $where;

		return (int) $this->getVarOrFail( $sql, $params );
	}

	/**
	 * Build the WHERE clause and bound params for the collection query.
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

			if ( null !== DiscountStatus::tryFrom( $status ) ) {
				$clauses[] = 'status = %s';
				$params[]  = $status;
			}
		}

		if ( isset( $args['search'] ) && '' !== trim( (string) $args['search'] ) ) {
			$like      = '%' . $this->wpdb->esc_like( trim( (string) $args['search'] ) ) . '%';
			$clauses[] = '(code LIKE %s OR name LIKE %s)';
			$params[]  = $like;
			$params[]  = $like;
		}

		$where = array() === $clauses ? '' : ' WHERE ' . implode( ' AND ', $clauses );

		return array( $where, $params );
	}

	/**
	 * Whitelist the orderby column, defaulting to created_at.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $orderby The requested orderby.
	 * @return string A safe column name.
	 */
	private function whitelistOrderby( $orderby ): string {
		$allowed = array( 'id', 'code', 'created_at' );

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
	 * Get a page size from 1 to 100.
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
	 * Cache a discount under its id and lowercased-code lookup keys.
	 *
	 * @since 1.0.0
	 *
	 * @param Discount $discount The discount to cache.
	 * @return void
	 */
	private function cache( Discount $discount ): void {
		$id = $discount->id();

		if ( null !== $id ) {
			wp_cache_set( $this->idKey( $id ), $discount, self::CACHE_GROUP );
		}

		wp_cache_set( $this->codeKey( $discount->code() ), $discount, self::CACHE_GROUP );
	}

	/**
	 * Invalidate supplied discount cache keys.
	 *
	 * @since 1.0.0
	 *
	 * @param int|null    $id   The discount id, or null to skip the id key.
	 * @param string|null $code The code, or null to skip the code key.
	 * @return void
	 */
	private function forget( ?int $id, ?string $code ): void {
		if ( null !== $id ) {
			wp_cache_delete( $this->idKey( $id ), self::CACHE_GROUP );
		}

		if ( null !== $code && '' !== trim( $code ) ) {
			wp_cache_delete( $this->codeKey( $code ), self::CACHE_GROUP );
		}
	}

	/**
	 * The cache key for a discount id.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The discount id.
	 * @return string The cache key.
	 */
	private function idKey( int $id ): string {
		return 'id_' . $id;
	}

	/**
	 * Get a fixed-length, case-insensitive code cache key.
	 *
	 * @since 1.0.0
	 *
	 * @param string $code The discount code.
	 * @return string The cache key.
	 */
	private function codeKey( string $code ): string {
		return 'code_' . md5( strtolower( trim( $code ) ) );
	}

	/**
	 * The fully-qualified `tiermint_discounts` table name.
	 *
	 * @since 1.0.0
	 *
	 * @return string The table name.
	 */
	private function table(): string {
		return Schema::tableName( $this->wpdb, 'discounts' );
	}

	/**
	 * Run a prepared single-row SELECT.
	 *
	 * Check last_error because wpdb uses null for both errors and misses.
	 *
	 * @since 1.0.0
	 *
	 * @param string            $sql  SQL with a Schema-derived table name and placeholders.
	 * @param array<int, mixed> $args Values bound into the placeholders.
	 * @return object|null The row, or null when it genuinely does not exist.
	 *
	 * @throws DiscountPersistenceException When the read faulted at the database.
	 */
	private function getRowOrFail( string $sql, array $args ): ?object {
		$wpdb             = $this->wpdb;
		$wpdb->last_error = '';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Values bound via prepare(); find()/findByCode() front reads with wp_cache.
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $args ) );

		if ( '' !== $wpdb->last_error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only literal; controllers map persistence failures to a generic message.
			throw new DiscountPersistenceException( 'Discount read failed: ' . $wpdb->last_error );
		}

		return $row;
	}

	/**
	 * Run a prepared multi-row SELECT with the same fault discrimination as
	 * {@see getRowOrFail()}.
	 *
	 * @since 1.0.0
	 *
	 * @param string            $sql  SQL with a Schema-derived table name and placeholders.
	 * @param array<int, mixed> $args Values bound into the placeholders.
	 * @return array<int, object> The rows (possibly empty).
	 *
	 * @throws DiscountPersistenceException When the read faulted at the database.
	 */
	private function getResultsOrFail( string $sql, array $args ): array {
		$wpdb             = $this->wpdb;
		$wpdb->last_error = '';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Values bound via prepare(); collection reads are deliberately uncached.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );

		if ( '' !== $wpdb->last_error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only literal; controllers map persistence failures to a generic message.
			throw new DiscountPersistenceException( 'Discount read failed: ' . $wpdb->last_error );
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Run a prepared scalar SELECT with the same fault discrimination as
	 * {@see getRowOrFail()}.
	 *
	 * @since 1.0.0
	 *
	 * @param string            $sql  SQL with a Schema-derived table name and placeholders.
	 * @param array<int, mixed> $args Values bound into the placeholders.
	 * @return string|null The scalar value, or null.
	 *
	 * @throws DiscountPersistenceException When the read faulted at the database.
	 */
	private function getVarOrFail( string $sql, array $args ): ?string {
		$wpdb             = $this->wpdb;
		$wpdb->last_error = '';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Values bound via prepare(); a count probe has nothing to cache.
		$value = $wpdb->get_var( array() === $args ? $sql : $wpdb->prepare( $sql, $args ) );

		if ( '' !== $wpdb->last_error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only literal; controllers map persistence failures to a generic message.
			throw new DiscountPersistenceException( 'Discount read failed: ' . $wpdb->last_error );
		}

		return null === $value ? null : (string) $value;
	}
}
