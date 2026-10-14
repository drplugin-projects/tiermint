<?php
/**
 * Plugin bootstrap orchestrator.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Core;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Boots service providers through a shared container.
 *
 * Hook registration stays outside construction so tests do not require WordPress.
 *
 * @since 1.0.0
 */
final class Plugin implements Hookable {

	/**
	 * Absolute path to the main plugin file (for the activation hooks).
	 *
	 * @var string
	 */
	private string $file;

	/**
	 * The shared service container.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Ordered service providers that make up the plugin.
	 *
	 * @var array<int, ServiceProvider>
	 */
	private array $providers;

	/**
	 * Whether providers are registered.
	 *
	 * @var bool
	 */
	private bool $providersRegistered = false;

	/**
	 * Wire the plugin with its main file, container, and providers.
	 *
	 * @since 1.0.0
	 *
	 * @param string                      $file      Absolute path to the main plugin file.
	 * @param Container                   $container The shared service container.
	 * @param array<int, ServiceProvider> $providers Ordered service providers.
	 */
	public function __construct( string $file, Container $container, array $providers = array() ) {
		$this->file      = $file;
		$this->container = $container;
		$this->providers = $providers;
	}

	/**
	 * Register plugin lifecycle hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function hooks(): void {
		register_activation_hook( $this->file, array( $this, 'activate' ) );
		register_deactivation_hook( $this->file, array( $this, 'deactivate' ) );
		add_action( 'plugins_loaded', array( $this, 'boot' ), 10 );
	}

	/**
	 * Boot the plugin: register then boot every provider, then announce readiness.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function boot(): void {
		$this->registerProviders();

		foreach ( $this->providers as $provider ) {
			$provider->boot( $this->container );
		}

		/**
		 * Fires after TierMint core boots.
		 *
		 * Add-ons register service providers here.
		 *
		 * @since 1.0.0
		 *
		 * @param Container $container The shared plugin container.
		 */
		do_action( 'tiermint_loaded', $this->container );
	}

	/**
	 * Run activation services.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function activate(): void {
		$this->registerProviders();
		$this->container->get( Activator::class )->activate();
	}

	/**
	 * Run deactivation services.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function deactivate(): void {
		$this->registerProviders();
		$this->container->get( Deactivator::class )->deactivate();
	}

	/**
	 * Return the shared service container.
	 *
	 * @since 1.0.0
	 *
	 * @return Container
	 */
	public function container(): Container {
		return $this->container;
	}

	/**
	 * Register each provider once.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function registerProviders(): void {
		if ( $this->providersRegistered ) {
			return;
		}

		foreach ( $this->providers as $provider ) {
			$provider->register( $this->container );
		}

		$this->providersRegistered = true;
	}
}
