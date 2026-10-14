<?php
/**
 * Plugin activation handler.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Core;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Database\Migrator;
use DrPlugin\WP\TierMint\Frontend\ProductRewrites;

/**
 * Runs pending migrations and seeds missing defaults on activation.
 *
 * The migration check also handles upgrades that skip activation.
 *
 * @since 1.0.0
 */
final class Activator {

	/**
	 * The schema migrations runner.
	 *
	 * @var Migrator
	 */
	private Migrator $migrator;

	/**
	 * Wire the activator with its dependencies.
	 *
	 * @since 1.0.0
	 *
	 * @param Migrator $migrator The schema migrations runner.
	 */
	public function __construct( Migrator $migrator ) {
		$this->migrator = $migrator;
	}

	/**
	 * Perform activation side effects.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function activate(): void {
		// Migrate first so later steps can use the tables.
		$this->migrator->migrate();

		// Grant the custom management capabilities to administrators.
		Capabilities::ensure();

		// Never replace existing settings on reactivation.
		if ( get_option( 'tiermint_settings' ) === false ) {
			add_option( 'tiermint_settings', $this->defaultSettings() );
		}

		// Provision pages on init, after post types and blocks load.
		update_option( PageProvisioner::PROVISION_FLAG, true );

		// Flush rewrites after page provisioning.
		update_option( ProductRewrites::FLUSH_FLAG, true );
	}

	/**
	 * The default settings seeded on first activation.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed> Default TierMint settings.
	 */
	private function defaultSettings(): array {
		return array(
			// Keep purchased licences unless uninstall is explicitly enabled.
			'delete_data_on_uninstall' => false,
		);
	}
}
