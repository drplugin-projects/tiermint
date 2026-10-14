<?php
/**
 * Service provider for the Updates domain (releases and update delivery).
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Updates;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Core\Container;
use DrPlugin\WP\TierMint\Core\ServiceProvider;
use DrPlugin\WP\TierMint\Licensing\LicenseRepository;
use DrPlugin\WP\TierMint\Licensing\LicenseService;
use DrPlugin\WP\TierMint\Licensing\LicenseValidationService;
use DrPlugin\WP\TierMint\Licensing\SiteUrlNormalizer;
use DrPlugin\WP\TierMint\Orders\OrderRepository;
use DrPlugin\WP\TierMint\Products\ProductRepository;
use DrPlugin\WP\TierMint\Products\ProductService;
use DrPlugin\WP\TierMint\Storage\StorageManager;
use DrPlugin\WP\TierMint\Util\Crypto;

/**
 * Registers update and release services.
 *
 * @since 1.0.0
 */
final class UpdatesServiceProvider implements ServiceProvider {

	/**
	 * Bind the Updates domain services as container singletons.
	 *
	 * @since 1.0.0
	 *
	 * @param Container $container The shared plugin container.
	 * @return void
	 */
	public function register( Container $container ): void {
		$container->singleton(
			ReleaseRepository::class,
			static function (): ReleaseRepository {
				global $wpdb;

				return new ReleaseRepository( $wpdb );
			}
		);

		$container->singleton(
			ReleaseStateMachine::class,
			static fn (): ReleaseStateMachine => new ReleaseStateMachine()
		);

		$container->singleton(
			ReleaseService::class,
			static fn ( Container $c ): ReleaseService => new ReleaseService(
				$c->get( ReleaseRepository::class ),
				$c->get( ReleaseStateMachine::class ),
				$c->get( ProductService::class ),
				$c->get( StorageManager::class )
			)
		);

		$container->singleton(
			ChangelogRenderer::class,
			static fn (): ChangelogRenderer => new ChangelogRenderer()
		);

		$container->singleton(
			PackageSigner::class,
			static fn ( Container $c ): PackageSigner => new PackageSigner( $c->get( Crypto::class ) )
		);

		$container->singleton(
			UpdateService::class,
			static fn ( Container $c ): UpdateService => new UpdateService(
				$c->get( ProductRepository::class ),
				$c->get( ReleaseRepository::class ),
				$c->get( LicenseValidationService::class ),
				$c->get( LicenseRepository::class ),
				$c->get( SiteUrlNormalizer::class ),
				$c->get( ChangelogRenderer::class ),
				$c->get( PackageSigner::class )
			)
		);

		$container->singleton(
			CustomerDownloads::class,
			static fn ( Container $c ): CustomerDownloads => new CustomerDownloads(
				$c->get( OrderRepository::class ),
				$c->get( ProductRepository::class ),
				$c->get( LicenseService::class ),
				$c->get( ReleaseRepository::class ),
				$c->get( PackageSigner::class )
			)
		);
	}

	/**
	 * Does not register hooks.
	 *
	 * @since 1.0.0
	 *
	 * @param Container $container The shared plugin container.
	 * @return void
	 */
	public function boot( Container $container ): void {
		// This provider has no hooks.
	}
}
