<?php
/**
 * Service provider for the Payments layer.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Payments;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Core\Container;
use DrPlugin\WP\TierMint\Core\ServiceProvider;
use DrPlugin\WP\TierMint\Orders\OrderRepository;
use DrPlugin\WP\TierMint\Orders\OrderService;
use DrPlugin\WP\TierMint\Orders\RefundService;
use DrPlugin\WP\TierMint\Payments\Stripe\StripeApiClient;
use DrPlugin\WP\TierMint\Payments\Stripe\StripeEventHandlers;
use DrPlugin\WP\TierMint\Payments\Stripe\StripeGateway;
use DrPlugin\WP\TierMint\Payments\Stripe\StripeSettings;
use DrPlugin\WP\TierMint\Payments\Stripe\StripeWebhookVerifier;
use DrPlugin\WP\TierMint\Util\Crypto;
use DrPlugin\WP\TierMint\Util\RateLimiter;

/**
 * Registers the payment services before REST controllers resolve them.
 *
 * @since 1.0.0
 */
final class PaymentsServiceProvider implements ServiceProvider {

	/**
	 * Bind the payments services as lazy singletons.
	 *
	 * @since 1.0.0
	 *
	 * @param Container $container The shared plugin container.
	 * @return void
	 */
	public function register( Container $container ): void {
		$container->singleton( Crypto::class, static fn (): Crypto => new Crypto() );

		$container->singleton( RateLimiter::class, static fn (): RateLimiter => new RateLimiter() );

		$container->singleton(
			StripeSettings::class,
			static fn ( Container $c ): StripeSettings => new StripeSettings( $c->get( Crypto::class ) )
		);

		$container->singleton( StripeApiClient::class, static fn (): StripeApiClient => new StripeApiClient() );

		$container->singleton(
			StripeWebhookVerifier::class,
			static fn (): StripeWebhookVerifier => new StripeWebhookVerifier()
		);

		$container->singleton(
			StripeGateway::class,
			static fn ( Container $c ): StripeGateway => new StripeGateway(
				$c->get( StripeApiClient::class ),
				$c->get( StripeSettings::class ),
				$c->get( StripeWebhookVerifier::class )
			)
		);

		$container->singleton(
			GatewayRegistry::class,
			static fn ( Container $c ): GatewayRegistry => new GatewayRegistry(
				array( 'stripe' => $c->get( StripeGateway::class ) )
			)
		);

		$container->singleton(
			GatewayEventRepository::class,
			static function (): GatewayEventRepository {
				global $wpdb;

				return new GatewayEventRepository( $wpdb );
			}
		);

		$container->singleton(
			RefundLock::class,
			static function (): RefundLock {
				global $wpdb;

				return new RefundLock( $wpdb );
			}
		);

		$container->singleton(
			RefundCoordinator::class,
			static fn ( Container $c ): RefundCoordinator => new RefundCoordinator(
				$c->get( OrderRepository::class ),
				$c->get( RefundService::class ),
				$c->get( GatewayRegistry::class ),
				$c->get( RefundLock::class )
			)
		);

		$container->singleton(
			StripeEventHandlers::class,
			static fn ( Container $c ): StripeEventHandlers => new StripeEventHandlers(
				$c->get( OrderService::class ),
				$c->get( RefundCoordinator::class )
			)
		);

		$container->singleton(
			WebhookProcessor::class,
			static fn ( Container $c ): WebhookProcessor => new WebhookProcessor(
				$c->get( GatewayRegistry::class ),
				$c->get( GatewayEventRepository::class ),
				// Resolve extension handlers after all providers register.
				array( 'stripe' => $c->get( StripeEventHandlers::class )->handlers() )
			)
		);

		$container->singleton(
			CheckoutService::class,
			static fn ( Container $c ): CheckoutService => new CheckoutService(
				$c->get( OrderService::class ),
				$c->get( GatewayRegistry::class )
			)
		);
	}

	/**
	 * No payment service registers WordPress hooks.
	 *
	 * @since 1.0.0
	 *
	 * @param Container $container The shared plugin container.
	 * @return void
	 */
	public function boot( Container $container ): void {
	}
}
