<?php
/**
 * Service provider for the Privacy (GDPR) domain.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Privacy;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Core\Container;
use DrPlugin\WP\TierMint\Core\ServiceProvider;
use DrPlugin\WP\TierMint\Customers\CustomerRepository;
use DrPlugin\WP\TierMint\Database\LogRepository;
use DrPlugin\WP\TierMint\Licensing\ActivationRepository;
use DrPlugin\WP\TierMint\Licensing\LicenseRepository;
use DrPlugin\WP\TierMint\Licensing\LicenseService;
use DrPlugin\WP\TierMint\Orders\OrderRepository;
use DrPlugin\WP\TierMint\Payments\GatewayEventRepository;

/**
 * Register privacy services as container singletons.
 *
 * @since 1.0.0
 */
final class PrivacyServiceProvider implements ServiceProvider {

	/**
	 * Bind the exporter, eraser, and registrar.
	 *
	 * @since 1.0.0
	 *
	 * @param Container $container The shared plugin container.
	 * @return void
	 */
	public function register( Container $container ): void {
		$container->singleton(
			PersonalDataExporter::class,
			static fn ( Container $c ): PersonalDataExporter => new PersonalDataExporter(
				$c->get( CustomerRepository::class ),
				$c->get( OrderRepository::class ),
				$c->get( LicenseRepository::class ),
				$c->get( ActivationRepository::class ),
				$c->get( LogRepository::class )
			)
		);

		$container->singleton(
			PersonalDataEraser::class,
			static fn ( Container $c ): PersonalDataEraser => new PersonalDataEraser(
				$c->get( CustomerRepository::class ),
				$c->get( OrderRepository::class ),
				$c->get( LicenseRepository::class ),
				$c->get( ActivationRepository::class ),
				$c->get( LogRepository::class ),
				$c->get( LicenseService::class ),
				$c->get( GatewayEventRepository::class )
			)
		);

		$container->singleton(
			PrivacyRegistrar::class,
			static fn ( Container $c ): PrivacyRegistrar => new PrivacyRegistrar(
				$c->get( PersonalDataExporter::class ),
				$c->get( PersonalDataEraser::class )
			)
		);
	}

	/**
	 * Register the privacy hooks once the plugin has booted.
	 *
	 * @since 1.0.0
	 *
	 * @param Container $container The shared plugin container.
	 * @return void
	 */
	public function boot( Container $container ): void {
		$container->get( PrivacyRegistrar::class )->hooks();
	}
}
