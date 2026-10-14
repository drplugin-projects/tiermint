<?php
/**
 * Migration 0001: create every core table.
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
 * Creates the core tables with dbDelta.
 *
 * @since 1.0.0
 */
final class Migration_0001_CreateCoreTables implements Migration {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 *
	 * @return int Always 1: this is the first migration.
	 */
	public function version(): int {
		return 1;
	}

	/**
	 * Create every core table via dbDelta.
	 *
	 * @since 1.0.0
	 *
	 * @param \wpdb $wpdb The WordPress database abstraction.
	 * @return void
	 */
	public function up( \wpdb $wpdb ): void {
		// Load dbDelta() outside admin requests.
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( Schema::statements( $wpdb ) as $sql ) {
			dbDelta( $sql );
		}
	}
}
