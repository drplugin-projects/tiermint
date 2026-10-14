<?php
/**
 * Migration 0006: create the affiliate program tables.
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
 * Creates the core tables used by Pro affiliates.
 *
 * This dbDelta call adds only missing tables.
 *
 * @since 1.0.0
 */
final class Migration_0006_CreateAffiliateTables implements Migration {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 *
	 * @return int Always 6: the sixth migration.
	 */
	public function version(): int {
		return 6;
	}

	/**
	 * Creates affiliate tables from their Schema DDL.
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

		dbDelta( $statements[ Schema::tableName( $wpdb, 'affiliates' ) ] );
		dbDelta( $statements[ Schema::tableName( $wpdb, 'commissions' ) ] );
	}
}
