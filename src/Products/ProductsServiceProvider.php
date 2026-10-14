<?php
/**
 * Service provider for the Products domain.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Products;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Core\Container;
use DrPlugin\WP\TierMint\Core\ServiceProvider;
use DrPlugin\WP\TierMint\Products\Fields\CoreProductFields;
use DrPlugin\WP\TierMint\Products\Fields\ProductFieldRegistry;

/**
 * Registers Product domain services.
 *
 * Repositories resolve `$wpdb` only when the container creates them.
 *
 * @since 1.0.0
 */
final class ProductsServiceProvider implements ServiceProvider {

	/**
	 * Bind the products and term repositories and services.
	 *
	 * @since 1.0.0
	 *
	 * @param Container $container The shared plugin container.
	 * @return void
	 */
	public function register( Container $container ): void {
		$container->singleton(
			ProductRepository::class,
			static function (): ProductRepository {
				global $wpdb;

				return new ProductRepository( $wpdb );
			}
		);

		$container->singleton(
			TermRepository::class,
			static function (): TermRepository {
				global $wpdb;

				return new TermRepository( $wpdb );
			}
		);

		$container->singleton(
			TermRelationshipRepository::class,
			static function (): TermRelationshipRepository {
				global $wpdb;

				return new TermRelationshipRepository( $wpdb );
			}
		);

		$container->singleton(
			TermService::class,
			static fn ( Container $c ): TermService => new TermService(
				$c->get( TermRepository::class ),
				$c->get( TermRelationshipRepository::class )
			)
		);

		$container->singleton(
			ProductService::class,
			static fn ( Container $c ): ProductService => new ProductService(
				$c->get( ProductRepository::class ),
				$c->get( TermService::class )
			)
		);

		$container->singleton(
			ProductFieldRegistry::class,
			static fn (): ProductFieldRegistry => new ProductFieldRegistry( new CoreProductFields() )
		);
	}

	/**
	 * No hooks to register.
	 *
	 * @since 1.0.0
	 *
	 * @param Container $container The shared plugin container.
	 * @return void
	 */
	public function boot( Container $container ): void {
		// No hooks to register.
	}
}
