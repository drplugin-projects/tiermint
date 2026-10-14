<?php
/**
 * Migration 0002: add the sales-report index to the orders table.
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
 * Adds the orders report index.
 *
 * Schema keeps fresh installs and upgrades aligned.
 *
 * @since 1.0.0
 */
final class Migration_0002_AddReportIndexes implements Migration {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 *
	 * @return int Always 2: the second migration.
	 */
	public function version(): int {
		return 2;
	}

	/**
	 * Adds the report index from the orders DDL.
	 *
	 * @since 1.0.0
	 *
	 * @param \wpdb $wpdb The WordPress database abstraction.
	 * @return void
	 */
	public function up( \wpdb $wpdb ): void {
		// Load dbDelta() outside admin requests.
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$statements  = Schema::statements( $wpdb );
		$ordersTable = Schema::tableName( $wpdb, 'orders' );

		dbDelta( $statements[ $ordersTable ] );
	}
}
