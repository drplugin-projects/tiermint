<?php
/**
 * Service provider for the Discounts domain.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Discounts;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Core\Container;
use DrPlugin\WP\TierMint\Core\ServiceProvider;
use DrPlugin\WP\TierMint\Database\Logger;
use DrPlugin\WP\TierMint\Orders\OrderRepository;
use DrPlugin\WP\TierMint\Products\TermRelationshipRepository;

/**
 * Registers Discounts services and their shared dependencies.
 *
 * @since 1.0.0
 */
final class DiscountsServiceProvider implements ServiceProvider {

	/**
	 * Bind the discounts repository, service, and listener.
	 *
	 * @since 1.0.0
	 *
	 * @param Container $container The shared plugin container.
	 * @return void
	 */
	public function register( Container $container ): void {
		$container->singleton(
			DiscountRepository::class,
			static function (): DiscountRepository {
				global $wpdb;

				return new DiscountRepository( $wpdb );
			}
		);

		$container->singleton(
			DiscountService::class,
			static fn ( Container $c ): DiscountService => new DiscountService(
				$c->get( DiscountRepository::class ),
				$c->get( OrderRepository::class )
			)
		);

		$container->singleton(
			DiscountsListener::class,
			static fn ( Container $c ): DiscountsListener => new DiscountsListener(
				$c->get( DiscountService::class ),
				$c->get( Logger::class )
			)
		);

		$container->singleton(
			DiscountCalculator::class,
			static fn (): DiscountCalculator => new DiscountCalculator()
		);

		$container->singleton(
			DiscountSettings::class,
			static fn (): DiscountSettings => new DiscountSettings()
		);

		$container->singleton(
			AutomaticDiscountResolver::class,
			static fn ( Container $c ): AutomaticDiscountResolver => new AutomaticDiscountResolver(
				$c->get( DiscountRepository::class ),
				$c->get( TermRelationshipRepository::class ),
				$c->get( OrderRepository::class ),
				$c->get( DiscountCalculator::class )
			)
		);

		$container->singleton(
			DiscountComposer::class,
			static fn ( Container $c ): DiscountComposer => new DiscountComposer(
				$c->get( AutomaticDiscountResolver::class ),
				$c->get( DiscountSettings::class ),
				$c->get( DiscountService::class )
			)
		);
	}

	/**
	 * Attach the discount-usage listener to order completion.
	 *
	 * @since 1.0.0
	 *
	 * @param Container $container The shared plugin container.
	 * @return void
	 */
	public function boot( Container $container ): void {
		$container->get( DiscountsListener::class )->hooks();
	}
}
