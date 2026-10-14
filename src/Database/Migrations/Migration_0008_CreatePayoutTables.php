<?php
/**
 * Migration 0008: create the affiliate payout table and its claim columns.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Database\Migrations;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Database\Migration;
use DrPlugin\WP\TierMint\Database\Schema;

/**
 * Creates the payout records table and adds the payout claim columns used by
 * Pro affiliate payouts.
 *
 * The new table is created by dbDelta; the added columns are existence-probed,
 * then the commissions DDL is re-run so the claim index lands on existing
 * installs (a raw ADD COLUMN adds no index).
 *
 * @since 1.0.0
 */
final class Migration_0008_CreatePayoutTables implements Migration {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 *
	 * @return int Always 8: the eighth migration.
	 */
	public function version(): int {
		return 8;
	}

	/**
	 * Create the payout table and add the claim columns and index.
	 *
	 * @since 1.0.0
	 *
	 * @param \wpdb $wpdb The WordPress database abstraction.
	 * @return void
	 */
	public function up( \wpdb $wpdb ): void {
		// Load dbDelta() outside admin requests.
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$statements = Schema::statements( $wpdb );

		dbDelta( $statements[ Schema::tableName( $wpdb, 'affiliate_payouts' ) ] );

		// The commission claim link and the affiliate payout target fields.
		$this->addColumnIfMissing( $wpdb, Schema::tableName( $wpdb, 'commissions' ), 'payout_id', 'bigint(20) unsigned DEFAULT NULL' );
		$this->addColumnIfMissing( $wpdb, Schema::tableName( $wpdb, 'affiliates' ), 'payout_email', 'varchar(190) DEFAULT NULL' );
		$this->addColumnIfMissing( $wpdb, Schema::tableName( $wpdb, 'affiliates' ), 'payout_method', 'varchar(20) DEFAULT NULL' );

		// The column probe adds no index, so re-run the commissions DDL to land
		// KEY payout_id on an install that already carried the column.
		dbDelta( $statements[ Schema::tableName( $wpdb, 'commissions' ) ] );
	}

	/**
	 * Adds a missing column with fixed, schema-derived SQL.
	 *
	 * @since 1.0.0
	 *
	 * @param \wpdb  $wpdb       The WordPress database abstraction.
	 * @param string $table      The fully-qualified table name (Schema-derived).
	 * @param string $column     The column name to add.
	 * @param string $definition The column's SQL type definition.
	 * @return void
	 */
	private function addColumnIfMissing( \wpdb $wpdb, string $table, string $column, string $definition ): void {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema-derived table name interpolated; the column name is bound via prepare(); a one-off DDL existence probe with nothing to cache.
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );

		if ( null !== $exists ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- One-off migration DDL: the table name is Schema-derived and the column name/definition are fixed literals set by this migration, never caller input; ALTER TABLE cannot be a prepared statement, has nothing to cache, and a schema change is exactly this migration's job.
		$wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$column} {$definition}" );
	}
}
