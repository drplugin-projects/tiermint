<?php
/**
 * Term relationship repository: writes the product-assignment join rows.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Products;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Database\Schema;

/**
 * Stores product term assignments one taxonomy at a time.
 * Identifiers come from {@see Schema::tableName()} and values use prepared queries.
 *
 * @since 1.0.0
 */
class TermRelationshipRepository {

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
	 * Replace a product's assignments within one taxonomy.
	 *
	 * @since 1.0.0
	 *
	 * @param int               $productId The product to assign terms to.
	 * @param ProductTaxonomy   $taxonomy  The taxonomy the assignment covers.
	 * @param array<int, mixed> $termIds  The term ids to assign (de-duplicated here).
	 * @return void
	 */
	public function replaceForProductTaxonomy( int $productId, ProductTaxonomy $taxonomy, array $termIds ): void {
		$wpdb = $this->wpdb;

		// Keep assignments in other taxonomies.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE tr FROM %i tr INNER JOIN %i t ON t.id = tr.term_id WHERE tr.product_id = %d AND t.taxonomy = %s',
				$this->relationshipsTable(),
				$this->termsTable(),
				$productId,
				$taxonomy->value
			)
		);

		$unique = array_values( array_unique( array_map( 'intval', $termIds ) ) );

		foreach ( $unique as $termId ) {
			$this->insertRow(
				$this->relationshipsTable(),
				array(
					'term_id'    => $termId,
					'product_id' => $productId,
				)
			);
		}
	}

	/**
	 * Get a product's term IDs in one taxonomy.
	 *
	 * @since 1.0.0
	 *
	 * @param int             $productId The product id.
	 * @param ProductTaxonomy $taxonomy  The taxonomy to read.
	 * @return array<int, int> The assigned term ids.
	 */
	public function termIdsForProduct( int $productId, ProductTaxonomy $taxonomy ): array {
		$wpdb = $this->wpdb;
		$ids  = $this->fetchCol(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
			fn () => $wpdb->get_col(
				$wpdb->prepare(
					'SELECT tr.term_id FROM %i tr INNER JOIN %i t ON t.id = tr.term_id' .
					' WHERE tr.product_id = %d AND t.taxonomy = %s ORDER BY tr.term_id ASC',
					$this->relationshipsTable(),
					$this->termsTable(),
					$productId,
					$taxonomy->value
				)
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Get term IDs for product rows in one query.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, mixed> $productIds The product ids to read.
	 * @param ProductTaxonomy   $taxonomy   The taxonomy to read.
	 * @return array<int, array<int, int>> Product id => assigned term ids.
	 */
	public function termIdsForProducts( array $productIds, ProductTaxonomy $taxonomy ): array {
		$unique = array_values( array_unique( array_map( 'intval', $productIds ) ) );

		if ( array() === $unique ) {
			return array();
		}

		$wpdb   = $this->wpdb;
		$values = array_merge( array( $this->relationshipsTable(), $this->termsTable(), $taxonomy->value ), $unique );
		$rows   = $this->fetchRows(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
			fn () => $wpdb->get_results(
				$wpdb->prepare(
					'SELECT tr.product_id, tr.term_id FROM %i tr INNER JOIN %i t ON t.id = tr.term_id' .
					' WHERE t.taxonomy = %s AND tr.product_id IN (' . implode( ',', array_fill( 0, count( $unique ), '%d' ) ) . ')' .
					' ORDER BY tr.product_id ASC, tr.term_id ASC',
					$values
				)
			)
		);

		$grouped = array();

		foreach ( $rows as $row ) {
			$grouped[ (int) $row->product_id ][] = (int) $row->term_id;
		}

		return $grouped;
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
	 * Insert a row into a schema-defined table.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $table The fully-qualified table name.
	 * @param array<string, mixed> $data  Column => value pairs.
	 * @return void
	 *
	 * @throws TermPersistenceException When the insert fails. The controller reports
	 *                          this as a generic 500, never leaking the table name.
	 */
	private function insertRow( string $table, array $data ): void {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table; this class decides what to cache.
		$inserted = $wpdb->insert( $table, $data );

		if ( false === $inserted ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new TermPersistenceException( 'Failed to insert a row into ' . $table . '.' );
		}
	}

	/**
	 * Fetch one column.
	 *
	 * @since 1.0.0
	 *
	 * @param callable(): mixed $query Runs the prepared query.
	 * @return array<int, mixed> The column values.
	 */
	private function fetchCol( callable $query ): array {
		$col = $query();

		return is_array( $col ) ? $col : array();
	}

	/**
	 * Fetch result rows.
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
}
