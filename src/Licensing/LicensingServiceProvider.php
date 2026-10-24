<?php
/**
 * Service provider for the Licensing domain.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Licensing;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Core\Container;
use DrPlugin\WP\TierMint\Core\ServiceProvider;
use DrPlugin\WP\TierMint\Database\AdvisoryLock;
use DrPlugin\WP\TierMint\Database\Logger;
use DrPlugin\WP\TierMint\Orders\OrderService;
use DrPlugin\WP\TierMint\Products\ProductService;

/**
 * Registers licensing services and listeners as container singletons.
 *
 * @since 1.0.0
 */
final class LicensingServiceProvider implements ServiceProvider {

	/**
	 * Bind the licensing primitives, repositories, and services.
	 *
	 * @since 1.0.0
	 *
	 * @param Container $container The shared plugin container.
	 * @return void
	 */
	public function register( Container $container ): void {
		$container->singleton(
			KeyGenerator::class,
			static fn (): KeyGenerator => new KeyGenerator()
		);

		$container->singleton(
			SiteUrlNormalizer::class,
			static fn (): SiteUrlNormalizer => new SiteUrlNormalizer()
		);

		$container->singleton(
			LicenseStateMachine::class,
			static fn (): LicenseStateMachine => new LicenseStateMachine()
		);

		$container->singleton(
			LastKnownGood::class,
			static fn (): LastKnownGood => new LastKnownGood()
		);

		$container->singleton(
			LicenseRepository::class,
			static function (): LicenseRepository {
				global $wpdb;

				return new LicenseRepository( $wpdb );
			}
		);

		$container->singleton(
			ActivationRepository::class,
			static function (): ActivationRepository {
				global $wpdb;

				return new ActivationRepository( $wpdb );
			}
		);

		$container->singleton(
			LicenseService::class,
			static function ( Container $c ): LicenseService {
				global $wpdb;

				return new LicenseService(
					$c->get( LicenseRepository::class ),
					$c->get( ProductService::class ),
					$c->get( KeyGenerator::class ),
					$c->get( LicenseStateMachine::class ),
					$c->get( LastKnownGood::class ),
					new AdvisoryLock( $wpdb, 'mint' ),
					$c->get( Logger::class )
				);
			}
		);

		$container->singleton(
			ActivationService::class,
			static fn ( Container $c ): ActivationService => new ActivationService(
				$c->get( LicenseRepository::class ),
				$c->get( ActivationRepository::class ),
				$c->get( LicenseService::class ),
				$c->get( SiteUrlNormalizer::class ),
				$c->get( Logger::class )
			)
		);

		$container->singleton(
			LicenseValidationService::class,
			static fn ( Container $c ): LicenseValidationService => new LicenseValidationService(
				$c->get( LicenseRepository::class ),
				$c->get( ActivationRepository::class ),
				$c->get( LicenseService::class ),
				$c->get( ProductService::class ),
				$c->get( SiteUrlNormalizer::class ),
				$c->get( LastKnownGood::class )
			)
		);

		$container->singleton(
			LicensingListener::class,
			static fn ( Container $c ): LicensingListener => new LicensingListener(
				$c->get( LicenseService::class ),
				$c->get( OrderService::class ),
				$c->get( Logger::class )
			)
		);
	}

	/**
	 * Attach the licensing engine to order events and cron.
	 *
	 * @since 1.0.0
	 *
	 * @param Container $container The shared plugin container.
	 * @return void
	 */
	public function boot( Container $container ): void {
		$container->get( LicensingListener::class )->hooks();
	}
}
