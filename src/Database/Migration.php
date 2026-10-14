<?php
/**
 * Contract for a single, ordered schema migration.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Database;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Defines a versioned, idempotent schema migration.
 *
 * @since 1.0.0
 */
interface Migration {

	/**
	 * Gets this migration's schema version.
	 *
	 * @since 1.0.0
	 *
	 * @return int A positive integer, unique and increasing across migrations.
	 */
	public function version(): int;

	/**
	 * Applies an idempotent schema or data change.
	 *
	 * @since 1.0.0
	 *
	 * @param \wpdb $wpdb The WordPress database abstraction (prefix + charset live here).
	 * @return void
	 *
	 * @throws \Throwable When the change cannot be applied; the Migrator halts,
	 *                    logs the failure, and does not record this version.
	 */
	public function up( \wpdb $wpdb ): void;
}
