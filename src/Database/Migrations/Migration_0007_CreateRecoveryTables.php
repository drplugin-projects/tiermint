<?php
/**
 * Migration 0007: create the cart-recovery tables.
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
 * Creates the core tables used by Pro cart recovery.
 *
 * This dbDelta call adds only missing tables.
 *
 * @since 1.0.0
 */
final class Migration_0007_CreateRecoveryTables implements Migration {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 *
	 * @return int Always 7: the seventh migration.
	 */
	public function version(): int {
		return 7;
	}

	/**
	 * Creates recovery tables from their Schema DDL.
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

		dbDelta( $statements[ Schema::tableName( $wpdb, 'recoveries' ) ] );
		dbDelta( $statements[ Schema::tableName( $wpdb, 'recovery_emails' ) ] );
	}
}
