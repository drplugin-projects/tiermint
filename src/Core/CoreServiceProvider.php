<?php
/**
 * Service provider for core, cross-cutting services.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Core;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Database\Migrator;

/**
 * Registers core lifecycle services.
 *
 * @since 1.0.0
 */
final class CoreServiceProvider implements ServiceProvider {

	/**
	 * Bind the activation lifecycle handlers.
	 *
	 * @since 1.0.0
	 *
	 * @param Container $container The shared plugin container.
	 * @return void
	 */
	public function register( Container $container ): void {
		$container->singleton(
			Activator::class,
			static fn ( Container $c ): Activator => new Activator( $c->get( Migrator::class ) )
		);
		$container->singleton(
			Deactivator::class,
			static fn (): Deactivator => new Deactivator()
		);
		$container->singleton(
			PageProvisioner::class,
			static fn (): PageProvisioner => new PageProvisioner()
		);
	}

	/**
	 * Wire the core cross-cutting hooks.
	 *
	 * @since 1.0.0
	 *
	 * @param Container $container The shared plugin container.
	 * @return void
	 */
	public function boot( Container $container ): void {
		// Restore capabilities removed by a role editor or migration.
		add_action( 'init', array( Capabilities::class, 'ensure' ), 5 );

		// Provision pages on init, after post types and blocks load.
		$container->get( PageProvisioner::class )->hooks();
	}
}
