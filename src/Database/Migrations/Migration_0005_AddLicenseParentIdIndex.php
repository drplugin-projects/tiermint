<?php
/**
 * Migration 0005: add the parent_id index to the licenses table.
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
 * Adds the licenses parent_id index for team-seat lookups.
 *
 * Schema defines it for new installs, and dbDelta adds it on upgrades.
 *
 * @since 1.0.0
 */
final class Migration_0005_AddLicenseParentIdIndex implements Migration {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 *
	 * @return int Always 5: the fifth migration.
	 */
	public function version(): int {
		return 5;
	}

	/**
	 * Adds the parent_id index from the licenses DDL.
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
		$table      = Schema::tableName( $wpdb, 'licenses' );

		dbDelta( $statements[ $table ] );
	}
}
