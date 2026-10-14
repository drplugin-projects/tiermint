<?php
/**
 * Service provider for the Customers domain.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Customers;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Core\Container;
use DrPlugin\WP\TierMint\Core\ServiceProvider;
use DrPlugin\WP\TierMint\Database\Logger;
use DrPlugin\WP\TierMint\Frontend\CheckoutFieldSettings;
use DrPlugin\WP\TierMint\Licensing\LicenseRepository;
use DrPlugin\WP\TierMint\Orders\OrderRepository;

/**
 * Registers customer services and their hooks.
 * Repository creation defers global `$wpdb` access.
 *
 * @since 1.0.0
 */
final class CustomersServiceProvider implements ServiceProvider {

	/**
	 * Bind the customer repository, service, and account-linking adapter.
	 *
	 * @since 1.0.0
	 *
	 * @param Container $container The shared plugin container.
	 * @return void
	 */
	public function register( Container $container ): void {
		$container->singleton(
			CustomerRepository::class,
			static function (): CustomerRepository {
				global $wpdb;

				return new CustomerRepository( $wpdb );
			}
		);

		$container->singleton(
			CustomerService::class,
			static fn ( Container $c ): CustomerService => new CustomerService(
				$c->get( CustomerRepository::class )
			)
		);

		$container->singleton(
			CustomerLinker::class,
			static fn ( Container $c ): CustomerLinker => new CustomerLinker(
				$c->get( CustomerService::class )
			)
		);

		$container->singleton(
			CustomerDeleteGuard::class,
			static fn ( Container $c ): CustomerDeleteGuard => new CustomerDeleteGuard(
				$c->get( OrderRepository::class ),
				$c->get( LicenseRepository::class )
			)
		);

		$container->singleton(
			UserProvisioner::class,
			static fn ( Container $c ): UserProvisioner => new UserProvisioner(
				new CheckoutFieldSettings(),
				$c->get( CustomerService::class ),
				$c->get( Logger::class )
			)
		);
	}

	/**
	 * Register customer-domain hooks.
	 *
	 * @since 1.0.0
	 *
	 * @param Container $container The shared plugin container.
	 * @return void
	 */
	public function boot( Container $container ): void {
		$container->get( CustomerLinker::class )->hooks();
		$container->get( UserProvisioner::class )->hooks();
		$container->get( CustomerDeleteGuard::class )->hooks();
	}
}
