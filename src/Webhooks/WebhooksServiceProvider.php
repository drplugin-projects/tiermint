<?php
/**
 * Service provider for the outbound Webhooks domain.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Webhooks;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Core\Container;
use DrPlugin\WP\TierMint\Core\ServiceProvider;
use DrPlugin\WP\TierMint\Database\Logger;
use DrPlugin\WP\TierMint\Util\Crypto;

/**
 * Registers the outbound webhook services.
 *
 * Boot attaches their hooks without sending HTTP requests.
 *
 * @since 1.0.0
 */
final class WebhooksServiceProvider implements ServiceProvider {

	/**
	 * Bind the webhook services.
	 *
	 * @since 1.0.0
	 *
	 * @param Container $container The shared plugin container.
	 * @return void
	 */
	public function register( Container $container ): void {
		$container->singleton(
			WebhookSigner::class,
			static fn (): WebhookSigner => new WebhookSigner()
		);

		$container->singleton(
			WebhookEndpoints::class,
			static fn ( Container $c ): WebhookEndpoints => new WebhookEndpoints( $c->get( Crypto::class ) )
		);

		$container->singleton(
			WebhookDispatcher::class,
			static fn ( Container $c ): WebhookDispatcher => new WebhookDispatcher(
				$c->get( WebhookEndpoints::class ),
				$c->get( Logger::class )
			)
		);

		$container->singleton(
			WebhookDeliverer::class,
			static fn ( Container $c ): WebhookDeliverer => new WebhookDeliverer(
				$c->get( WebhookEndpoints::class ),
				$c->get( WebhookSigner::class ),
				$c->get( Logger::class )
			)
		);

		$container->singleton(
			WebhookFailureNotice::class,
			static fn ( Container $c ): WebhookFailureNotice => new WebhookFailureNotice( $c->get( WebhookEndpoints::class ) )
		);
	}

	/**
	 * Attach the dispatcher, deliverer, and admin notice to their hooks.
	 *
	 * @since 1.0.0
	 *
	 * @param Container $container The shared plugin container.
	 * @return void
	 */
	public function boot( Container $container ): void {
		$container->get( WebhookDispatcher::class )->hooks();
		$container->get( WebhookDeliverer::class )->hooks();
		$container->get( WebhookFailureNotice::class )->hooks();
	}
}
