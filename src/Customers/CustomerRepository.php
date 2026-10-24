<?php
/**
 * Customers repository: the only Customers class that touches $wpdb.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Customers;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Database\Schema;

/**
 * Customer database access and object-cache management.
 *
 * Old and new cache keys are cleared after a write. Table names come from Schema.
 *
 * @since 1.0.0
 */
class CustomerRepository {

	/**
	 * The object-cache group customers are cached under.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'tiermint_customers';

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
	 * Insert a customer with UTC timestamps.
	 *
	 * Guest user IDs are SQL NULL, so unique keys do not collide.
	 *
	 * @since 1.0.0
	 *
	 * @param Customer $customer The customer to persist.
	 * @return int The new customer id.
	 *
	 * @throws CustomerPersistenceException When the insert fails (e.g. a unique email or
	 *                          user_id clash). The controller reports this as a generic
	 *                          500, never leaking the table name.
	 */
	public function create( Customer $customer ): int {
		$now = gmdate( 'Y-m-d H:i:s' );
		$row = $customer->toRow();

		$id = $this->insertInto(
			$this->customersTable(),
			array(
				'user_id'        => $row['user_id'],
				'email'          => $row['email'],
				'name'           => $row['name'],
				'first_name'     => $row['first_name'],
				'last_name'      => $row['last_name'],
				'purchase_count' => $row['purchase_count'],
				'purchase_total' => $row['purchase_total'],
				'created_at'     => $now,
				'updated_at'     => $now,
			)
		);

		$this->forget( $id, (string) $row['email'], $customer->userId() );

		return $id;
	}

	/**
	 * Load a customer by id, reading through the object cache.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The customer id.
	 * @return Customer|null The customer, or null when it does not exist.
	 */
	public function find( int $id ): ?Customer {
		$cached = wp_cache_get( $this->idKey( $id ), self::CACHE_GROUP );

		if ( $cached instanceof Customer ) {
			return $cached;
		}

		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$row = $this->fetchRow( fn () => $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->customersTable(), $id ) ) );

		if ( null === $row ) {
			return null;
		}

		$customer = Customer::fromRow( $row );
		$this->cache( $customer );

		return $customer;
	}

	/**
	 * Load several customers in one query, keyed by ID.
	 *
	 * Missing IDs are omitted. This path bypasses the object cache.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, mixed> $ids The customer ids to load.
	 * @return array<int, Customer> Customer id => customer.
	 */
	public function findMany( array $ids ): array {
		$unique = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn ( int $id ): bool => $id > 0 ) ) );

		if ( array() === $unique ) {
			return array();
		}

		$wpdb   = $this->wpdb;
		$values = array_merge( array( $this->customersTable() ), $unique );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$rows = $this->fetchRows( fn () => $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE id IN (' . implode( ',', array_fill( 0, count( $unique ), '%d' ) ) . ')', $values ) ) );

		$customers = array();

		foreach ( $rows as $row ) {
			$customers[ (int) $row->id ] = Customer::fromRow( $row );
		}

		return $customers;
	}

	/**
	 * Load a customer by normalised email through the object cache.
	 *
	 * @since 1.0.0
	 *
	 * @param string $email The email to look up.
	 * @return Customer|null The customer, or null when none matches.
	 */
	public function findByEmail( string $email ): ?Customer {
		$normalized = Customer::normalizeEmail( $email );

		if ( '' === $normalized ) {
			return null;
		}

		$cached = wp_cache_get( $this->emailKey( $normalized ), self::CACHE_GROUP );

		if ( $cached instanceof Customer ) {
			return $cached;
		}

		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$row = $this->fetchRow( fn () => $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE email = %s', $this->customersTable(), $normalized ) ) );

		if ( null === $row ) {
			return null;
		}

		$customer = Customer::fromRow( $row );
		$this->cache( $customer );

		return $customer;
	}

	/**
	 * Load a customer by WordPress user ID through the object cache.
	 *
	 * A non-positive ID cannot match a guest.
	 *
	 * @since 1.0.0
	 *
	 * @param int $userId The WordPress user id.
	 * @return Customer|null The customer, or null when none is linked.
	 */
	public function findByUserId( int $userId ): ?Customer {
		if ( $userId <= 0 ) {
			return null;
		}

		$cached = wp_cache_get( $this->userKey( $userId ), self::CACHE_GROUP );

		if ( $cached instanceof Customer ) {
			return $cached;
		}

		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$row = $this->fetchRow( fn () => $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d', $this->customersTable(), $userId ) ) );

		if ( null === $row ) {
			return null;
		}

		$customer = Customer::fromRow( $row );
		$this->cache( $customer );

		return $customer;
	}

	/**
	 * Update mutable customer columns with a UTC timestamp.
	 *
	 * Clear old and new cache keys if the email or user link changed.
	 *
	 * @since 1.0.0
	 *
	 * @param Customer $customer The customer to persist (must carry an id).
	 * @return void
	 *
	 * @throws CustomerException When the customer has no id to update. A failed write
	 *                          (e.g. a unique clash) surfaces as the
	 *                          {@see CustomerPersistenceException} raised by updateWhere().
	 */
	public function update( Customer $customer ): void {
		$id = $customer->id();

		if ( null === $id ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new CustomerException( __( 'Cannot update a customer that has no id.', 'tiermint' ) );
		}

		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$previous = $this->fetchRow( fn () => $wpdb->get_row( $wpdb->prepare( 'SELECT email, user_id FROM %i WHERE id = %d', $this->customersTable(), $id ) ) );

		$row = $customer->toRow();

		$this->updateWhere(
			$this->customersTable(),
			array(
				'user_id'        => $row['user_id'],
				'email'          => $row['email'],
				'name'           => $row['name'],
				'first_name'     => $row['first_name'],
				'last_name'      => $row['last_name'],
				'purchase_count' => $row['purchase_count'],
				'purchase_total' => $row['purchase_total'],
				'updated_at'     => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $id )
		);

		// Drop the previous email/user keys first (they may differ from the new values),
		// then the new keys, so no stale lookup survives the change.
		if ( null !== $previous ) {
			$this->forget(
				null,
				null !== $previous->email ? (string) $previous->email : null,
				null !== $previous->user_id ? (int) $previous->user_id : null
			);
		}

		$this->forget( $id, (string) $row['email'], $customer->userId() );
	}

	/**
	 * Delete a customer and clear its caches.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The customer id to delete.
	 * @return void
	 *
	 * @throws CustomerPersistenceException When the delete fails at the database.
	 */
	public function delete( int $id ): void {
		$existing = $this->find( $id );

		$this->deleteWhere( $this->customersTable(), array( 'id' => $id ) );

		$this->forget(
			$id,
			null !== $existing ? $existing->email() : null,
			null !== $existing ? $existing->userId() : null
		);
	}

	/**
	 * Anonymise a customer without removing the row that orders and licences use.
	 *
	 * Clear personal fields and old and new cache keys.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $id        The customer id to anonymize.
	 * @param string $anonEmail The anonymized replacement email (unique per customer).
	 * @return void
	 *
	 * @throws CustomerPersistenceException When the update fails.
	 */
	public function anonymize( int $id, string $anonEmail ): void {
		$existing = $this->find( $id );

		$this->updateWhere(
			$this->customersTable(),
			array(
				'email'      => $anonEmail,
				'name'       => null,
				'first_name' => null,
				'last_name'  => null,
				'user_id'    => null,
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $id )
		);

		if ( null !== $existing ) {
			$this->forget( $existing->id(), $existing->email(), $existing->userId() );
		}

		$this->forget( $id, $anonEmail, null );
	}

	/**
	 * Atomically increase purchase totals and clear all customer cache keys.
	 *
	 * A raw arithmetic UPDATE prevents concurrent orders from losing an increment.
	 *
	 * @since 1.0.0
	 *
	 * @param int $customerId The customer to credit.
	 * @param int $amount     The purchase total to add, in minor units.
	 * @return void
	 */
	public function incrementPurchaseStats( int $customerId, int $amount ): void {
		$existing = $this->find( $customerId );
		$wpdb     = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET purchase_count = purchase_count + 1, purchase_total = purchase_total + %d, updated_at = %s WHERE id = %d',
				$this->customersTable(),
				$amount,
				gmdate( 'Y-m-d H:i:s' ),
				$customerId
			)
		);

		$this->forget(
			$customerId,
			null !== $existing ? $existing->email() : null,
			null !== $existing ? $existing->userId() : null
		);
	}

	/**
	 * Check whether a normalised email is in use, with an optional exclusion.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $email    The email to check.
	 * @param int|null $exceptId A customer id to exclude, or null.
	 * @return bool True when the email is in use.
	 */
	public function emailExists( string $email, ?int $exceptId = null ): bool {
		$normalized = Customer::normalizeEmail( $email );

		if ( '' === $normalized ) {
			return false;
		}

		$wpdb = $this->wpdb;

		if ( null === $exceptId ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
			return null !== $this->fetchVar( fn () => $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE email = %s LIMIT 1', $this->customersTable(), $normalized ) ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		return null !== $this->fetchVar( fn () => $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE email = %s AND id != %d LIMIT 1', $this->customersTable(), $normalized, $exceptId ) ) );
	}

	/**
	 * Check whether a WordPress user ID is linked, with an optional exclusion.
	 *
	 * @since 1.0.0
	 *
	 * @param int      $userId   The WordPress user id to check.
	 * @param int|null $exceptId A customer id to exclude, or null.
	 * @return bool True when the user id is already linked.
	 */
	public function userIdExists( int $userId, ?int $exceptId = null ): bool {
		if ( $userId <= 0 ) {
			return false;
		}

		$wpdb = $this->wpdb;

		if ( null === $exceptId ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
			return null !== $this->fetchVar( fn () => $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE user_id = %d LIMIT 1', $this->customersTable(), $userId ) ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		return null !== $this->fetchVar( fn () => $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE user_id = %d AND id != %d LIMIT 1', $this->customersTable(), $userId, $exceptId ) ) );
	}

	/**
	 * Query customers for a collection view.
	 *
	 * Search matches email or name. Page size is capped at 100. Sort fields are whitelisted.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $args The query args.
	 * @return array<int, Customer> The matching, hydrated customers.
	 */
	public function query( array $args ): array {
		$orderby = $this->whitelistOrderby( $args['orderby'] ?? 'created_at' );
		$perPage = $this->perPage( $args );
		$offset  = ( max( 1, (int) ( $args['page'] ?? 1 ) ) - 1 ) * $perPage;
		$wpdb    = $this->wpdb;

		list( $bySearch, $like ) = $this->filters( $args );

		// The WHERE is the one in countQuery(); keep the copies in step.
		if ( 'ASC' === $this->whitelistOrder( $args['order'] ?? 'DESC' ) ) {
			$rows = $this->fetchRows(
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
				fn () => $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE ( %d = 0 OR email LIKE %s OR name LIKE %s ) ORDER BY %i ASC, id ASC LIMIT %d OFFSET %d',
						$this->customersTable(),
						$bySearch,
						$like,
						$like,
						$orderby,
						$perPage,
						$offset
					)
				)
			);
		} else {
			$rows = $this->fetchRows(
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
				fn () => $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE ( %d = 0 OR email LIKE %s OR name LIKE %s ) ORDER BY %i DESC, id DESC LIMIT %d OFFSET %d',
						$this->customersTable(),
						$bySearch,
						$like,
						$like,
						$orderby,
						$perPage,
						$offset
					)
				)
			);
		}

		return array_map( static fn ( object $r ): Customer => Customer::fromRow( $r ), $rows );
	}

	/**
	 * Count customers matching a collection query (for pagination).
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $args The query args (search).
	 * @return int The total matching customer count.
	 */
	public function countQuery( array $args ): int {
		$wpdb = $this->wpdb;

		list( $bySearch, $like ) = $this->filters( $args );

		return (int) $this->fetchVar(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
			fn () => $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ( %d = 0 OR email LIKE %s OR name LIKE %s )', $this->customersTable(), $bySearch, $like, $like ) )
		);
	}

	/**
	 * Read the search filter for the fixed WHERE in query() and countQuery().
	 *
	 * An empty search turns the filter off, through a flag, because MySQL collations
	 * compare a zero-width space equal to ''. MySQL drops the clause before it plans the query.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $args The query args.
	 * @return array{0: int, 1: string} The search flag and the LIKE pattern.
	 */
	private function filters( array $args ): array {
		$search = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';

		return array( '' === $search ? 0 : 1, '%' . $this->wpdb->esc_like( $search ) . '%' );
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
		$allowed = array( 'id', 'name', 'email', 'created_at' );

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
	 * Return the page size, with a default of 10 and a cap of 100.
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
	 * Cache a customer under each of its lookup keys (id, email, user link).
	 *
	 * @since 1.0.0
	 *
	 * @param Customer $customer The customer to cache.
	 * @return void
	 */
	private function cache( Customer $customer ): void {
		$id = $customer->id();

		if ( null !== $id ) {
			wp_cache_set( $this->idKey( $id ), $customer, self::CACHE_GROUP );
		}

		wp_cache_set( $this->emailKey( $customer->email() ), $customer, self::CACHE_GROUP );

		$userId = $customer->userId();
		if ( null !== $userId ) {
			wp_cache_set( $this->userKey( $userId ), $customer, self::CACHE_GROUP );
		}
	}

	/**
	 * Clear the supplied customer cache keys.
	 *
	 * An update clears both old and new email or user keys.
	 *
	 * @since 1.0.0
	 *
	 * @param int|null    $id     The customer id, or null to skip the id key.
	 * @param string|null $email  The email, or null to skip the email key.
	 * @param int|null    $userId The linked user id, or null to skip the user key.
	 * @return void
	 */
	private function forget( ?int $id, ?string $email, ?int $userId ): void {
		if ( null !== $id ) {
			wp_cache_delete( $this->idKey( $id ), self::CACHE_GROUP );
		}

		if ( null !== $email && '' !== $email ) {
			wp_cache_delete( $this->emailKey( $email ), self::CACHE_GROUP );
		}

		if ( null !== $userId ) {
			wp_cache_delete( $this->userKey( $userId ), self::CACHE_GROUP );
		}
	}

	/**
	 * The cache key for a customer id.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The customer id.
	 * @return string The cache key.
	 */
	private function idKey( int $id ): string {
		return 'customer_' . $id;
	}

	/**
	 * Return a fixed-length cache key for a normalised email.
	 *
	 * @since 1.0.0
	 *
	 * @param string $email The normalized email.
	 * @return string The cache key.
	 */
	private function emailKey( string $email ): string {
		return 'email_' . md5( Customer::normalizeEmail( $email ) );
	}

	/**
	 * The cache key for a linked WordPress user id.
	 *
	 * @since 1.0.0
	 *
	 * @param int $userId The WordPress user id.
	 * @return string The cache key.
	 */
	private function userKey( int $userId ): string {
		return 'user_' . $userId;
	}

	/**
	 * The fully-qualified `tiermint_customers` table name.
	 *
	 * @since 1.0.0
	 *
	 * @return string The table name.
	 */
	private function customersTable(): string {
		return Schema::tableName( $this->wpdb, 'customers' );
	}

	/**
	 * Insert a row into a Schema-defined table and return the new auto-increment id.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $table The fully-qualified table name.
	 * @param array<string, mixed> $data  Column => value pairs.
	 * @return int The inserted row id.
	 *
	 * @throws CustomerPersistenceException When the insert fails (e.g. a unique-key
	 *                          clash). The controller reports this as a generic 500,
	 *                          never leaking the table name.
	 */
	private function insertInto( string $table, array $data ): int {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table; this class decides what to cache.
		$inserted = $wpdb->insert( $table, $data );

		if ( false === $inserted ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new CustomerPersistenceException( 'Failed to insert a row into ' . $table . '.' );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update rows in a Schema-defined table.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $table The fully-qualified table name.
	 * @param array<string, mixed> $data  Column => value pairs to set.
	 * @param array<string, mixed> $where Column => value equality conditions.
	 * @return void
	 *
	 * @throws CustomerPersistenceException When the update fails (e.g. a unique-key
	 *                          clash on the new email or user link).
	 */
	private function updateWhere( string $table, array $data, array $where ): void {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$updated = $wpdb->update( $table, $data, $where );

		if ( false === $updated ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new CustomerPersistenceException( 'Failed to update a row in ' . $table . '.' );
		}
	}

	/**
	 * Delete rows from a Schema-defined table.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $table The fully-qualified table name.
	 * @param array<string, mixed> $where Column => value equality conditions.
	 * @return void
	 *
	 * @throws CustomerPersistenceException When the delete fails at the database.
	 */
	private function deleteWhere( string $table, array $where ): void {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$deleted = $wpdb->delete( $table, $where );

		if ( false === $deleted ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new CustomerPersistenceException( 'Failed to delete a row from ' . $table . '.' );
		}
	}

	/**
	 * Fetch a single row.
	 *
	 * @since 1.0.0
	 *
	 * @param callable(): mixed $query Runs the prepared query.
	 * @return object|null The row object, or null.
	 */
	private function fetchRow( callable $query ): ?object {
		return $query();
	}

	/**
	 * Fetch a result set.
	 *
	 * @since 1.0.0
	 *
	 * @param callable(): mixed $query Runs the prepared query.
	 * @return array<int, object> The rows (empty on no match).
	 */
	private function fetchRows( callable $query ): array {
		$rows = $query();

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Fetch a single scalar.
	 *
	 * @since 1.0.0
	 *
	 * @param callable(): mixed $query Runs the prepared query.
	 * @return string|null The scalar value, or null.
	 */
	private function fetchVar( callable $query ): ?string {
		$value = $query();

		return null === $value ? null : (string) $value;
	}
}
