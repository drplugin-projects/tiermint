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
	 * Default option name holding the integer schema version (autoload no).
	 *
	 * @var string
	 */
	private const VERSION_OPTION = 'tiermint_db_version';

	/**
	 * Default option name used as the migration mutex (autoload no).
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
	 * Option name holding this runner's schema version.
	 *
	 * @var string
	 */
	private string $versionOption;

	/**
	 * Option name used as this runner's mutex.
	 *
	 * @var string
	 */
	private string $lockOption;

	/**
	 * Wire the runner with its database handle and migration set.
	 *
	 * A schema with its own version needs its own options, or it reads the core version.
	 *
	 * @since 1.0.0
	 *
	 * @param \wpdb                 $wpdb          WordPress database abstraction.
	 * @param array<int, Migration> $migrations    Migrations to consider (any order).
	 * @param string                $versionOption Option that stores the schema version.
	 * @param string                $lockOption    Option used as the migration mutex.
	 */
	public function __construct( \wpdb $wpdb, array $migrations, string $versionOption = self::VERSION_OPTION, string $lockOption = self::LOCK_OPTION ) {
		$this->wpdb          = $wpdb;
		$this->migrations    = $migrations;
		$this->versionOption = $versionOption;
		$this->lockOption    = $lockOption;
	}

	/**
	 * Runs migrations newer than the stored version.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function migrate(): void {
		$current = (int) get_option( $this->versionOption, 0 );

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

				update_option( $this->versionOption, $migration->version(), false );
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
		$held = get_option( $this->lockOption );

		if ( is_numeric( $held ) && ( time() - (int) $held ) < self::LOCK_TTL ) {
			return false;
		}

		update_option( $this->lockOption, time(), false );

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
		delete_option( $this->lockOption );
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
			'TierMint migration to version %d (%s) failed: %s',
			$migration->version(),
			$this->versionOption,
			$error->getMessage()
		);

		// WPCS resolves database calls through this local alias.
		$wpdb  = $this->wpdb;
		$table = $wpdb->prefix . 'tiermint_logs';

		try {
			// Escape underscores so LIKE treats the table name literally.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration error path, nothing to cache.
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;

			if ( $exists ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Migration error path, nothing to cache.
				$wpdb->insert(
					$table,
					array(
						'type'       => 'migration',
						'message'    => $message,
						'context'    => wp_json_encode(
							array(
								'version'   => $migration->version(),
								'option'    => $this->versionOption,
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

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- The logs table may not exist yet, so the PHP error log is the only place left.
		error_log( $message );
	}
}
