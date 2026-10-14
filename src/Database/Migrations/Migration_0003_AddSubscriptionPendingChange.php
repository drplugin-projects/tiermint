<?php
/**
 * Migration 0003: add the subscription pending-plan-change columns.
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
 * Adds scheduled plan-change columns to subscriptions.
 *
 * Raw ALTER statements avoid unreliable dbDelta column diffs.
 *
 * @since 1.0.0
 */
final class Migration_0003_AddSubscriptionPendingChange implements Migration {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 *
	 * @return int Always 3: the third migration.
	 */
	public function version(): int {
		return 3;
	}

	/**
	 * Add the two pending-change columns, each guarded by an existence probe.
	 *
	 * @since 1.0.0
	 *
	 * @param \wpdb $wpdb The WordPress database abstraction.
	 * @return void
	 */
	public function up( \wpdb $wpdb ): void {
		$table = Schema::tableName( $wpdb, 'subscriptions' );

		// The target tier of a scheduled downgrade; NULL means no pending change.
		$this->addColumnIfMissing( $wpdb, $table, 'pending_price_id', 'bigint(20) unsigned DEFAULT NULL' );

		// The instant the pending change applies (a snapshot of current_period_end).
		$this->addColumnIfMissing( $wpdb, $table, 'pending_change_at', 'datetime DEFAULT NULL' );
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
