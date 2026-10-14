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
		// Keep assignments in other taxonomies.
		$this->run(
			'DELETE tr FROM ' . $this->relationshipsTable() . ' tr'
			. ' INNER JOIN ' . $this->termsTable() . ' t ON t.id = tr.term_id'
			. ' WHERE tr.product_id = %d AND t.taxonomy = %s',
			array( $productId, $taxonomy->value )
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
		$sql = 'SELECT tr.term_id FROM ' . $this->relationshipsTable() . ' tr'
			. ' INNER JOIN ' . $this->termsTable() . ' t ON t.id = tr.term_id'
			. ' WHERE tr.product_id = %d AND t.taxonomy = %s'
			. ' ORDER BY tr.term_id ASC';

		return array_map( 'intval', $this->fetchCol( $sql, array( $productId, $taxonomy->value ) ) );
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

		$sql = 'SELECT tr.product_id, tr.term_id FROM ' . $this->relationshipsTable() . ' tr'
			. ' INNER JOIN ' . $this->termsTable() . ' t ON t.id = tr.term_id'
			. ' WHERE t.taxonomy = %s AND tr.product_id IN (' . implode( ',', array_fill( 0, count( $unique ), '%d' ) ) . ')'
			. ' ORDER BY tr.product_id ASC, tr.term_id ASC';

		$grouped = array();

		foreach ( $this->fetchRows( $sql, array_merge( array( $taxonomy->value ), $unique ) ) as $row ) {
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Insert into a Schema-defined table; $wpdb->insert escapes every value; there is nothing to cache on a write.
		$inserted = $wpdb->insert( $table, $data );

		if ( false === $inserted ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $table is a Schema-derived internal identifier, never user input or HTML output; this is a developer-facing failure kept out of the client response by the controller.
			throw new TermPersistenceException( 'Failed to insert a row into ' . $table . '.' );
		}
	}

	/**
	 * Fetch one column with bound values.
	 *
	 * @since 1.0.0
	 *
	 * @param string            $sql  SQL with Schema-derived identifiers and %d/%s placeholders.
	 * @param array<int, mixed> $args Values bound into the placeholders.
	 * @return array<int, mixed> The column values.
	 */
	private function fetchCol( string $sql, array $args = array() ): array {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SQL embeds only Schema-derived table names; runtime values are bound via prepare(); this is a bounded per-product read.
		$col = $wpdb->get_col( array() === $args ? $sql : $wpdb->prepare( $sql, $args ) );

		return is_array( $col ) ? $col : array();
	}

	/**
	 * Fetch result rows with bound values.
	 *
	 * @since 1.0.0
	 *
	 * @param string            $sql  SQL with Schema-derived identifiers and %d/%s placeholders.
	 * @param array<int, mixed> $args Values bound into the placeholders.
	 * @return array<int, object> The rows (empty on no match).
	 */
	private function fetchRows( string $sql, array $args = array() ): array {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SQL embeds only Schema-derived table names and generated placeholders; runtime values are bound via prepare(); this is a bounded per-page read.
		$rows = $wpdb->get_results( array() === $args ? $sql : $wpdb->prepare( $sql, $args ) );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Run a write query with bound values.
	 *
	 * @since 1.0.0
	 *
	 * @param string            $sql  SQL with Schema-derived identifiers and %d/%s placeholders.
	 * @param array<int, mixed> $args Values bound into the placeholders.
	 * @return void
	 */
	private function run( string $sql, array $args = array() ): void {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SQL embeds only Schema-derived table names; runtime values are bound via prepare(); this is a targeted taxonomy-scoped relationship delete.
		$wpdb->query( array() === $args ? $sql : $wpdb->prepare( $sql, $args ) );
	}
}
