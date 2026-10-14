<?php
/**
 * Service provider for the Pro upgrade surfaces.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Upsell;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Core\Container;
use DrPlugin\WP\TierMint\Core\ServiceProvider;

/**
 * Binds the upsell seams and wires the admin menu, settings teaser, and dismiss
 * route. Each surface reads the Pro status when its hook fires, so all classes
 * boot unconditionally and simply do nothing while Pro is active.
 *
 * @since 1.0.0
 */
final class UpsellServiceProvider implements ServiceProvider {

	/**
	 * Bind the upsell services.
	 *
	 * @since 1.0.0
	 *
	 * @param Container $container The shared plugin container.
	 * @return void
	 */
	public function register( Container $container ): void {
		$container->singleton(
			ProStatus::class,
			static fn (): ProStatus => new ProStatus()
		);
		$container->singleton(
			ProFeatures::class,
			static fn (): ProFeatures => new ProFeatures()
		);
		$container->singleton(
			Dismissals::class,
			static fn (): Dismissals => new Dismissals()
		);
		$container->singleton(
			UpgradeAdminIntegration::class,
			static fn ( Container $c ): UpgradeAdminIntegration => new UpgradeAdminIntegration( $c->get( ProStatus::class ) )
		);
		$container->singleton(
			SettingsTeaser::class,
			static fn ( Container $c ): SettingsTeaser => new SettingsTeaser( $c->get( ProStatus::class ) )
		);
		$container->singleton(
			UpsellController::class,
			static fn ( Container $c ): UpsellController => new UpsellController( $c->get( Dismissals::class ) )
		);
	}

	/**
	 * Wire the upsell hooks.
	 *
	 * @since 1.0.0
	 *
	 * @param Container $container The shared plugin container.
	 * @return void
	 */
	public function boot( Container $container ): void {
		$container->get( UpgradeAdminIntegration::class )->hooks();
		$container->get( SettingsTeaser::class )->hooks();
		$container->get( UpsellController::class )->hooks();
	}
}
