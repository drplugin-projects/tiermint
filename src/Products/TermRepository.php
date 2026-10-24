<?php
/**
 * Term repository: the only Products class that touches $wpdb for `_terms`.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Products;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Database\Schema;

/**
 * Stores product terms and their links.
 *
 * Cache entries use the term id. SQL identifiers come from {@see Schema}.
 *
 * @since 1.0.0
 */
class TermRepository {

	/**
	 * The object-cache group terms are cached under.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'tiermint_terms';

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
	 * Insert a term, stamping its timestamps in UTC, and return the new id.
	 *
	 * @since 1.0.0
	 *
	 * @param Term $term The term to persist.
	 * @return int The new term id.
	 *
	 * @throws TermPersistenceException When the insert fails.
	 */
	public function create( Term $term ): int {
		$now = gmdate( 'Y-m-d H:i:s' );
		$row = $term->toRow();

		$id = $this->insertInto(
			$this->termsTable(),
			array(
				'taxonomy'    => $row['taxonomy'],
				'name'        => $row['name'],
				'slug'        => $row['slug'],
				'parent'      => $row['parent'],
				'description' => $row['description'],
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);

		$this->forget( $id );

		return $id;
	}

	/**
	 * Load a term by id, reading through the object cache.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The term id.
	 * @return Term|null The term, or null when it does not exist.
	 */
	public function find( int $id ): ?Term {
		$cached = wp_cache_get( $this->idKey( $id ), self::CACHE_GROUP );

		if ( $cached instanceof Term ) {
			return $cached;
		}

		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table; this class decides what to cache.
		$row = $this->fetchRow( fn () => $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->termsTable(), $id ) ) );

		if ( null === $row ) {
			return null;
		}

		$term = Term::fromRow( $row );
		wp_cache_set( $this->idKey( $id ), $term, self::CACHE_GROUP );

		return $term;
	}

	/**
	 * Load a term by its slug and taxonomy.
	 *
	 * @since 1.0.0
	 *
	 * @param string          $slug     The term slug.
	 * @param ProductTaxonomy $taxonomy The taxonomy to look within.
	 * @return Term|null The term, or null when it does not exist.
	 */
	public function findBySlug( string $slug, ProductTaxonomy $taxonomy ): ?Term {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$row = $this->fetchRow( fn () => $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE slug = %s AND taxonomy = %s', $this->termsTable(), $slug, $taxonomy->value ) ) );

		return null === $row ? null : Term::fromRow( $row );
	}

	/**
	 * Update a term's mutable columns, stamping updated_at in UTC.
	 *
	 * The service keeps the taxonomy immutable.
	 *
	 * @since 1.0.0
	 *
	 * @param Term $term The term to persist (must carry an id).
	 * @return void
	 *
	 * @throws TermException When the term has no id to update.
	 */
	public function update( Term $term ): void {
		$id = $term->id();

		if ( null === $id ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new TermException( __( 'Cannot update a term that has no id.', 'tiermint' ) );
		}

		$row = $term->toRow();

		$this->updateWhere(
			$this->termsTable(),
			array(
				'taxonomy'    => $row['taxonomy'],
				'name'        => $row['name'],
				'slug'        => $row['slug'],
				'parent'      => $row['parent'],
				'description' => $row['description'],
				'updated_at'  => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $id )
		);

		$this->forget( $id );
	}

	/**
	 * Delete a term and its relationship rows.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The term id to delete.
	 * @return void
	 */
	public function delete( int $id ): void {
		$this->deleteWhere( $this->termsTable(), array( 'id' => $id ) );
		$this->deleteWhere( $this->relationshipsTable(), array( 'term_id' => $id ) );

		$this->forget( $id );
	}

	/**
	 * Whether a slug is already taken within a taxonomy, optionally excluding one id.
	 *
	 * @since 1.0.0
	 *
	 * @param string          $slug     The slug to check.
	 * @param ProductTaxonomy $taxonomy The taxonomy to check within.
	 * @param int|null        $exceptId A term id to exclude (for updates), or null.
	 * @return bool True when the slug is in use within the taxonomy.
	 */
	public function slugExists( string $slug, ProductTaxonomy $taxonomy, ?int $exceptId = null ): bool {
		$wpdb = $this->wpdb;

		if ( null !== $exceptId ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
			$found = $this->fetchVar( fn () => $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE slug = %s AND taxonomy = %s AND id != %d LIMIT 1', $this->termsTable(), $slug, $taxonomy->value, $exceptId ) ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
			$found = $this->fetchVar( fn () => $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE slug = %s AND taxonomy = %s LIMIT 1', $this->termsTable(), $slug, $taxonomy->value ) ) );
		}

		return null !== $found;
	}

	/**
	 * List every term of a taxonomy, ordered by parent then name so callers can build
	 * a hierarchy top-down.
	 *
	 * @since 1.0.0
	 *
	 * @param ProductTaxonomy $taxonomy The taxonomy to list.
	 * @return array<int, Term> The ordered terms.
	 */
	public function listByTaxonomy( ProductTaxonomy $taxonomy ): array {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$rows = $this->fetchRows( fn () => $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE taxonomy = %s ORDER BY parent ASC, name ASC', $this->termsTable(), $taxonomy->value ) ) );

		return array_map( static fn ( object $r ): Term => Term::fromRow( $r ), $rows );
	}

	/**
	 * Load several terms by id in one query.
	 *
	 * Missing terms are not included. This read bypasses the object cache.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, mixed> $ids The term ids to load.
	 * @return array<int, Term> Term id => term.
	 */
	public function findMany( array $ids ): array {
		$unique = array_values( array_unique( array_map( 'intval', $ids ) ) );

		if ( array() === $unique ) {
			return array();
		}

		$wpdb   = $this->wpdb;
		$values = array_merge( array( $this->termsTable() ), $unique );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$rows = $this->fetchRows( fn () => $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE id IN (' . implode( ',', array_fill( 0, count( $unique ), '%d' ) ) . ')', $values ) ) );

		$terms = array();

		foreach ( $rows as $row ) {
			$terms[ (int) $row->id ] = Term::fromRow( $row );
		}

		return $terms;
	}

	/**
	 * Load a term's direct children in name order.
	 *
	 * @since 1.0.0
	 *
	 * @param int $parentId The parent term id.
	 * @return array<int, Term> The direct child terms.
	 */
	public function childrenOf( int $parentId ): array {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$rows = $this->fetchRows( fn () => $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE parent = %d ORDER BY name ASC', $this->termsTable(), $parentId ) ) );

		return array_map( static fn ( object $r ): Term => Term::fromRow( $r ), $rows );
	}

	/**
	 * The cache key for a term id.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The term id.
	 * @return string The cache key.
	 */
	private function idKey( int $id ): string {
		return 'term_' . $id;
	}

	/**
	 * Invalidate the cached entry for a term.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The term id.
	 * @return void
	 */
	private function forget( int $id ): void {
		wp_cache_delete( $this->idKey( $id ), self::CACHE_GROUP );
	}

	/**
	 * The fully-qualified `tiermint_terms` table name.
	 *
	 * @since 1.0.0
	 *
	 * @return string The table name.
	 */
	private function termsTable(): string {
		return Schema::tableName( $this->wpdb, 'terms' );
	}

	/**
	 * The fully-qualified `tiermint_term_relationships` table name.
	 *
	 * @since 1.0.0
	 *
	 * @return string The table name.
	 */
	private function relationshipsTable(): string {
		return Schema::tableName( $this->wpdb, 'term_relationships' );
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
	 * @throws TermPersistenceException When the insert fails.
	 */
	private function insertInto( string $table, array $data ): int {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table; this class decides what to cache.
		$inserted = $wpdb->insert( $table, $data );

		if ( false === $inserted ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new TermPersistenceException( 'Failed to insert a row into ' . $table . '.' );
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
	 */
	private function updateWhere( string $table, array $data, array $where ): void {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$wpdb->update( $table, $data, $where );
	}

	/**
	 * Delete rows from a Schema-defined table.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $table The fully-qualified table name.
	 * @param array<string, mixed> $where Column => value equality conditions.
	 * @return void
	 */
	private function deleteWhere( string $table, array $where ): void {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$wpdb->delete( $table, $where );
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
