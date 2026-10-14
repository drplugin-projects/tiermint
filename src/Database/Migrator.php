<?php
/**
 * Versioned schema migrations runner.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Database;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use Throwable;

/**
 * Runs pending migrations in version order.
 *
 * An option lock prevents concurrent runs. Failed work retries later.
 *
 * @since 1.0.0
 */
final class Migrator {

	/**
	 * Option name holding the integer schema version (autoload no).
	 *
	 * @var string
	 */
	private const VERSION_OPTION = 'tiermint_db_version';

	/**
	 * Option name used as the migration mutex (autoload no).
	 *
	 * @var string
	 */
	private const LOCK_OPTION = 'tiermint_migrating';

	/**
	 * Seconds after which a held lock is considered stale and may be reclaimed.
	 *
	 * @var int
	 */
	private const LOCK_TTL = 300;

	/**
	 * The WordPress database abstraction, handed to each migration's up().
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * The registered migrations, unsorted (sorted at run time by version).
	 *
	 * @var array<int, Migration>
	 */
	private array $migrations;

	/**
	 * Wire the runner with its database handle and migration set.
	 *
	 * @since 1.0.0
	 *
	 * @param \wpdb                 $wpdb       WordPress database abstraction.
	 * @param array<int, Migration> $migrations Migrations to consider (any order).
	 */
	public function __construct( \wpdb $wpdb, array $migrations ) {
		$this->wpdb       = $wpdb;
		$this->migrations = $migrations;
	}

	/**
	 * Runs migrations newer than the stored version.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function migrate(): void {
		$current = (int) get_option( self::VERSION_OPTION, 0 );

		// Fast path: nothing to do, so never take the lock or write any option.
		if ( $current >= $this->targetVersion() ) {
			return;
		}

		// Another request is already migrating; back off and let it finish.
		if ( ! $this->acquireLock() ) {
			return;
		}

		try {
			foreach ( $this->sortedMigrations() as $migration ) {
				if ( $migration->version() <= $current ) {
					continue;
				}

				try {
					$migration->up( $this->wpdb );
				} catch ( Throwable $e ) {
					// Keep the version unchanged so this migration retries later.
					$this->logFailure( $migration, $e );
					return;
				}

				update_option( self::VERSION_OPTION, $migration->version(), false );
				$current = $migration->version();
			}
		} finally {
			$this->releaseLock();
		}
	}

	/**
	 * The highest version across all registered migrations (0 when none).
	 *
	 * @since 1.0.0
	 *
	 * @return int The target schema version.
	 */
	public function targetVersion(): int {
		$max = 0;

		foreach ( $this->migrations as $migration ) {
			$max = max( $max, $migration->version() );
		}

		return $max;
	}

	/**
	 * Migrations sorted ascending by version, so they apply in a defined order.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, Migration> Sorted migrations.
	 */
	private function sortedMigrations(): array {
		$sorted = $this->migrations;

		usort(
			$sorted,
			static fn ( Migration $a, Migration $b ): int => $a->version() <=> $b->version()
		);

		return $sorted;
	}

	/**
	 * Take the migration lock, reclaiming it if the current lease is stale.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when the lock was acquired; false when a fresh lease is held.
	 */
	private function acquireLock(): bool {
		$held = get_option( self::LOCK_OPTION );

		if ( is_numeric( $held ) && ( time() - (int) $held ) < self::LOCK_TTL ) {
			return false;
		}

		update_option( self::LOCK_OPTION, time(), false );

		return true;
	}

	/**
	 * Release the migration lock.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function releaseLock(): void {
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * Logs a migration failure, using error_log before the logs table exists.
	 *
	 * @since 1.0.0
	 *
	 * @param Migration $migration The migration that threw.
	 * @param Throwable $error     The thrown error.
	 * @return void
	 */
	private function logFailure( Migration $migration, Throwable $error ): void {
		$message = sprintf(
			'TierMint migration to version %d failed: %s',
			$migration->version(),
			$error->getMessage()
		);

		// WPCS resolves database calls through this local alias.
		$wpdb  = $this->wpdb;
		$table = $wpdb->prefix . 'tiermint_logs';

		try {
			// Escape underscores so LIKE treats the table name literally.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off table-existence probe on the migration error path; there is nothing to cache.
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;

			if ( $exists ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Appending a single record to the append-only log table; caching does not apply.
				$wpdb->insert(
					$table,
					array(
						'type'       => 'migration',
						'message'    => $message,
						'context'    => wp_json_encode(
							array(
								'version'   => $migration->version(),
								'exception' => get_class( $error ),
							)
						),
						'created_at' => gmdate( 'Y-m-d H:i:s' ),
					)
				);
				return;
			}
			// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Intentional: recording a failure must never itself fail loudly and abort the request; execution falls through to the error_log below.
		} catch ( Throwable ) {
			// Intentionally empty. See the phpcs justification above.
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- The _logs table does not exist yet if migration 0001 failed (or the write above failed), so error_log is all that is left.
		error_log( $message );
	}
}
