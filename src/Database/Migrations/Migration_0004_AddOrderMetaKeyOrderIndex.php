<?php
/**
 * Migration 0004: add the composite meta-key index to the order meta table.
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
 * Adds the `meta_key_order` composite index to the order meta table.
 *
 * The index supports meta-key scans ordered by order id. `Schema` remains its only DDL source.
 *
 * @since 1.0.0
 */
final class Migration_0004_AddOrderMetaKeyOrderIndex implements Migration {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 *
	 * @return int Always 4: the fourth migration.
	 */
	public function version(): int {
		return 4;
	}

	/**
	 * Add the order meta index from the schema DDL.
	 *
	 * @since 1.0.0
	 *
	 * @param \wpdb $wpdb The WordPress database abstraction.
	 * @return void
	 */
	public function up( \wpdb $wpdb ): void {
		// dbDelta() lives in an admin include that is not loaded on the front end
		// or during cron, so pull it in explicitly before use.
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$statements = Schema::statements( $wpdb );
		$table      = Schema::tableName( $wpdb, 'order_meta' );

		dbDelta( $statements[ $table ] );
	}
}
