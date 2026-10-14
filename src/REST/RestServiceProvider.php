<?php
/**
 * Service provider for the REST API layer.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\REST;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Core\Container;
use DrPlugin\WP\TierMint\Core\ServiceProvider;
use DrPlugin\WP\TierMint\Customers\CustomerService;
use DrPlugin\WP\TierMint\Frontend\CheckoutFieldSettings;
use DrPlugin\WP\TierMint\Frontend\ProductUrlProvider;
use DrPlugin\WP\TierMint\Licensing\ActivationRepository;
use DrPlugin\WP\TierMint\Licensing\ActivationService;
use DrPlugin\WP\TierMint\Licensing\LicenseService;
use DrPlugin\WP\TierMint\Licensing\LicenseValidationService;
use DrPlugin\WP\TierMint\Orders\OrderService;
use DrPlugin\WP\TierMint\Payments\CheckoutService;
use DrPlugin\WP\TierMint\Payments\RefundCoordinator;
use DrPlugin\WP\TierMint\Payments\WebhookProcessor;
use DrPlugin\WP\TierMint\Customers\CustomerRepository;
use DrPlugin\WP\TierMint\Database\Logger;
use DrPlugin\WP\TierMint\Licensing\LicenseRepository;
use DrPlugin\WP\TierMint\Orders\OrderBuyers;
use DrPlugin\WP\TierMint\Orders\OrderRepository;
use DrPlugin\WP\TierMint\Products\Fields\ProductFieldRegistry;
use DrPlugin\WP\TierMint\Products\ProductRepository;
use DrPlugin\WP\TierMint\Products\ProductService;
use DrPlugin\WP\TierMint\Products\TermService;
use DrPlugin\WP\TierMint\Storage\StorageManager;
use DrPlugin\WP\TierMint\Updates\CustomerDownloads;
use DrPlugin\WP\TierMint\Updates\PackageSigner;
use DrPlugin\WP\TierMint\Updates\ReleaseRepository;
use DrPlugin\WP\TierMint\Updates\ReleaseService;
use DrPlugin\WP\TierMint\Updates\UpdateService;
use DrPlugin\WP\TierMint\REST\Auth\ApiKeyAuthenticator;
use DrPlugin\WP\TierMint\REST\Auth\ApiKeyRepository;
use DrPlugin\WP\TierMint\REST\Auth\ApiKeyService;
use DrPlugin\WP\TierMint\Util\RateLimiter;

/**
 * Binds REST controllers and registers their route hooks.
 *
 * @since 1.0.0
 */
final class RestServiceProvider implements ServiceProvider {

	/**
	 * Bind the REST controllers.
	 *
	 * @since 1.0.0
	 *
	 * @param Container $container The shared plugin container.
	 * @return void
	 */
	public function register( Container $container ): void {
		$container->singleton(
			ProductsController::class,
			static fn ( Container $c ): ProductsController => new ProductsController(
				$c->get( ProductService::class ),
				$c->get( TermService::class ),
				$c->get( ProductFieldRegistry::class ),
				$c->get( ProductUrlProvider::class )
			)
		);

		$container->singleton(
			TermsController::class,
			static fn ( Container $c ): TermsController => new TermsController( $c->get( TermService::class ) )
		);

		$container->singleton(
			CustomersController::class,
			static fn ( Container $c ): CustomersController => new CustomersController( $c->get( CustomerService::class ) )
		);

		$container->singleton(
			EditorSearchController::class,
			static fn ( Container $c ): EditorSearchController => new EditorSearchController(
				$c->get( ProductService::class ),
				$c->get( TermService::class )
			)
		);

		$container->singleton(
			CatalogController::class,
			static fn (): CatalogController => new CatalogController()
		);

		$container->singleton(
			UsersController::class,
			static fn (): UsersController => new UsersController()
		);

		$container->singleton(
			OrderBuyers::class,
			static fn ( Container $c ): OrderBuyers => new OrderBuyers(
				$c->get( CustomerService::class )
			)
		);

		$container->singleton(
			OrdersController::class,
			static fn ( Container $c ): OrdersController => new OrdersController(
				$c->get( OrderService::class ),
				$c->get( RefundCoordinator::class ),
				$c->get( OrderBuyers::class )
			)
		);

		$container->singleton(
			CheckoutController::class,
			static fn ( Container $c ): CheckoutController => new CheckoutController(
				$c->get( CheckoutService::class ),
				$c->get( RateLimiter::class ),
				$c->get( CheckoutFieldSettings::class )
			)
		);

		$container->singleton(
			WebhooksController::class,
			static fn ( Container $c ): WebhooksController => new WebhooksController(
				$c->get( WebhookProcessor::class )
			)
		);

		$container->singleton(
			ApiKeyRepository::class,
			static function (): ApiKeyRepository {
				global $wpdb;

				return new ApiKeyRepository( $wpdb );
			}
		);

		$container->singleton(
			ApiKeyService::class,
			static fn ( Container $c ): ApiKeyService => new ApiKeyService(
				$c->get( ApiKeyRepository::class )
			)
		);

		$container->singleton(
			ApiKeyAuthenticator::class,
			static fn ( Container $c ): ApiKeyAuthenticator => new ApiKeyAuthenticator(
				$c->get( ApiKeyService::class ),
				$c->get( RateLimiter::class )
			)
		);

		$container->singleton(
			ApiKeysController::class,
			static fn ( Container $c ): ApiKeysController => new ApiKeysController(
				$c->get( ApiKeyService::class ),
				$c->get( ApiKeyAuthenticator::class )
			)
		);

		$container->singleton(
			LicenseRouteGuard::class,
			static fn ( Container $c ): LicenseRouteGuard => new LicenseRouteGuard(
				$c->get( RateLimiter::class )
			)
		);

		$container->singleton(
			LicenseController::class,
			static fn ( Container $c ): LicenseController => new LicenseController(
				$c->get( LicenseService::class ),
				$c->get( ActivationService::class ),
				$c->get( LicenseValidationService::class ),
				$c->get( ProductService::class ),
				$c->get( CustomerService::class )
			)
		);

		$container->singleton(
			ReleasesController::class,
			static fn ( Container $c ): ReleasesController => new ReleasesController(
				$c->get( ReleaseService::class ),
				$c->get( ProductRepository::class )
			)
		);

		$container->singleton(
			UpdateCheckController::class,
			static fn ( Container $c ): UpdateCheckController => new UpdateCheckController(
				$c->get( UpdateService::class )
			)
		);

		$container->singleton(
			DiscountsController::class,
			static fn ( Container $c ): DiscountsController => new DiscountsController(
				$c->get( \DrPlugin\WP\TierMint\Discounts\DiscountService::class )
			)
		);

		$container->singleton(
			ReportsController::class,
			static fn ( Container $c ): ReportsController => new ReportsController(
				$c->get( \DrPlugin\WP\TierMint\Reports\ReportService::class )
			)
		);

		$container->singleton(
			\DrPlugin\WP\TierMint\Reports\CsvWriter::class,
			static fn (): \DrPlugin\WP\TierMint\Reports\CsvWriter => new \DrPlugin\WP\TierMint\Reports\CsvWriter()
		);

		$container->singleton(
			ReportsExportController::class,
			static fn ( Container $c ): ReportsExportController => new ReportsExportController(
				$c->get( \DrPlugin\WP\TierMint\Reports\ReportService::class ),
				$c->get( \DrPlugin\WP\TierMint\Reports\CsvWriter::class ),
				$c->get( OrderRepository::class ),
				$c->get( OrderBuyers::class )
			)
		);

		$container->singleton(
			LicensesController::class,
			static fn ( Container $c ): LicensesController => new LicensesController(
				$c->get( LicenseRepository::class ),
				$c->get( ActivationRepository::class ),
				$c->get( ActivationService::class ),
				$c->get( CustomerRepository::class ),
				$c->get( ProductRepository::class ),
				$c->get( LicenseService::class )
			)
		);

		$container->singleton(
			ReleasePackageDownloadController::class,
			static fn ( Container $c ): ReleasePackageDownloadController => new ReleasePackageDownloadController(
				$c->get( ReleaseRepository::class ),
				$c->get( ProductRepository::class ),
				$c->get( StorageManager::class ),
				$c->get( Logger::class )
			)
		);

		$container->singleton(
			DownloadController::class,
			static fn ( Container $c ): DownloadController => new DownloadController(
				$c->get( PackageSigner::class ),
				$c->get( LicenseRepository::class ),
				$c->get( LicenseService::class ),
				$c->get( ReleaseRepository::class ),
				$c->get( ProductRepository::class ),
				$c->get( OrderRepository::class ),
				$c->get( CustomerRepository::class ),
				$c->get( StorageManager::class ),
				$c->get( Logger::class ),
				$c->get( CustomerDownloads::class )
			)
		);
	}

	/**
	 * Resolve each controller and register its rest_api_init hook.
	 *
	 * @since 1.0.0
	 *
	 * @param Container $container The shared plugin container.
	 * @return void
	 */
	public function boot( Container $container ): void {
		$container->get( ProductsController::class )->hooks();
		$container->get( TermsController::class )->hooks();
		$container->get( EditorSearchController::class )->hooks();
		$container->get( CustomersController::class )->hooks();
		$container->get( CatalogController::class )->hooks();
		$container->get( UsersController::class )->hooks();
		$container->get( OrdersController::class )->hooks();
		$container->get( CheckoutController::class )->hooks();
		$container->get( WebhooksController::class )->hooks();
		$container->get( LicenseController::class )->hooks();
		$container->get( ReleasesController::class )->hooks();
		$container->get( UpdateCheckController::class )->hooks();
		$container->get( ReleasePackageDownloadController::class )->hooks();
		$container->get( DownloadController::class )->hooks();
		$container->get( LicenseRouteGuard::class )->hooks();
		$container->get( ApiKeyAuthenticator::class )->hooks();
		$container->get( ApiKeysController::class )->hooks();
		$container->get( DiscountsController::class )->hooks();
		$container->get( ReportsController::class )->hooks();
		$container->get( ReportsExportController::class )->hooks();
		$container->get( LicensesController::class )->hooks();
	}
}
