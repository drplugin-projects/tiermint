<?php
/**
 * Service provider for the database layer (migrations runner).
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Database;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Core\Container;
use DrPlugin\WP\TierMint\Core\ServiceProvider;
use DrPlugin\WP\TierMint\Database\Migrations\Migration_0001_CreateCoreTables;
use DrPlugin\WP\TierMint\Database\Migrations\Migration_0002_AddReportIndexes;
use DrPlugin\WP\TierMint\Database\Migrations\Migration_0003_AddSubscriptionPendingChange;
use DrPlugin\WP\TierMint\Database\Migrations\Migration_0004_AddOrderMetaKeyOrderIndex;
use DrPlugin\WP\TierMint\Database\Migrations\Migration_0005_AddLicenseParentIdIndex;
use DrPlugin\WP\TierMint\Database\Migrations\Migration_0006_CreateAffiliateTables;
use DrPlugin\WP\TierMint\Database\Migrations\Migration_0007_CreateRecoveryTables;
use DrPlugin\WP\TierMint\Database\Migrations\Migration_0008_CreatePayoutTables;

/**
 * Registers database services and runs pending migrations.
 *
 * The boot-time check also covers upgrades that skip plugin activation.
 *
 * @since 1.0.0
 */
final class DatabaseServiceProvider implements ServiceProvider {

	/**
	 * Bind the migrations runner and the audit logger.
	 *
	 * Services resolve `$wpdb` only when they are needed.
	 *
	 * @since 1.0.0
	 *
	 * @param Container $container The shared plugin container.
	 * @return void
	 */
	public function register( Container $container ): void {
		$container->singleton(
			Migrator::class,
			static function (): Migrator {
				global $wpdb;

				return new Migrator( $wpdb, self::migrations() );
			}
		);

		$container->singleton(
			Logger::class,
			static function (): Logger {
				global $wpdb;

				return new Logger( $wpdb );
			}
		);

		$container->singleton(
			LogRepository::class,
			static function (): LogRepository {
				global $wpdb;

				return new LogRepository( $wpdb );
			}
		);
	}

	/**
	 * Apply pending migrations once the plugin has booted.
	 *
	 * Maps metadata tables and runs pending migrations.
	 *
	 * @since 1.0.0
	 *
	 * @param Container $container The shared plugin container.
	 * @return void
	 */
	public function boot( Container $container ): void {
		global $wpdb;

		// Map request-local metadata tables before metadata calls.
		MetaTables::register( $wpdb );

		$container->get( Migrator::class )->migrate();
	}

	/**
	 * The ordered set of migrations shipped by the core.
	 *
	 * The {@see Migrator} sorts this set by version.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, Migration> The registered migrations.
	 */
	private static function migrations(): array {
		return array(
			new Migration_0001_CreateCoreTables(),
			new Migration_0002_AddReportIndexes(),
			new Migration_0003_AddSubscriptionPendingChange(),
			new Migration_0004_AddOrderMetaKeyOrderIndex(),
			new Migration_0005_AddLicenseParentIdIndex(),
			new Migration_0006_CreateAffiliateTables(),
			new Migration_0007_CreateRecoveryTables(),
			new Migration_0008_CreatePayoutTables(),
		);
	}
}
