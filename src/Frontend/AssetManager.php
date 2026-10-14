<?php
/**
 * Frontend asset registration and render-time enqueueing.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend;

use DrPlugin\WP\TierMint\Core\Hookable;

defined( 'ABSPATH' ) || exit;

/**
 * Registers component assets and enqueues them during rendering.
 *
 * The bootstrap handle supplies data before component ES modules run.
 *
 * @since 1.0.0
 */
class AssetManager implements Hookable {

	/**
	 * Handle of the design-token stylesheet every component inherits.
	 *
	 * @var string
	 */
	public const TOKENS_HANDLE = 'tiermint-tokens';

	/**
	 * Shared primitives stylesheet handle.
	 *
	 * @var string
	 */
	public const BASE_HANDLE = 'tiermint-base';

	/**
	 * Handle of the classic bootstrap script carrying window.tierMint.
	 *
	 * @var string
	 */
	public const BOOTSTRAP_HANDLE = 'tiermint-frontend';

	/**
	 * Stripe.js v3 handle, loaded only by checkout.
	 *
	 * @var string
	 */
	public const STRIPE_JS_HANDLE = 'tiermint-stripe-js';

	/**
	 * The component registry whose assets get registered.
	 *
	 * @var ComponentRegistry
	 */
	private ComponentRegistry $registry;

	/**
	 * Script handles registered as ES modules, keyed by handle.
	 *
	 * @var array<string, bool>
	 */
	private array $moduleHandles = array();

	/**
	 * Wire the registry.
	 *
	 * @param ComponentRegistry $registry Registered frontend components.
	 */
	public function __construct( ComponentRegistry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'init', array( $this, 'registerAssets' ) );
		add_filter( 'script_loader_tag', array( $this, 'filterScriptTag' ), 10, 2 );

		// The editor canvas needs styles before server-rendered previews load.
		add_action( 'enqueue_block_assets', array( $this, 'enqueueEditorStyles' ) );
	}

	/**
	 * Register the tokens stylesheet, the bootstrap data handle, Stripe.js,
	 * and every component's own CSS/ES-module files that exist on disk.
	 *
	 * @return void
	 */
	public function registerAssets(): void {
		wp_register_style(
			self::TOKENS_HANDLE,
			TIERMINT_URL . 'assets/frontend/tiermint-tokens.css',
			array(),
			TIERMINT_VERSION
		);

		wp_register_style(
			self::BASE_HANDLE,
			TIERMINT_URL . 'assets/frontend/tiermint-base.css',
			array( self::TOKENS_HANDLE ),
			TIERMINT_VERSION
		);

		// This src-less handle carries data for deferred ES modules.
		wp_register_script( self::BOOTSTRAP_HANDLE, false, array(), TIERMINT_VERSION, true );
		wp_add_inline_script( self::BOOTSTRAP_HANDLE, $this->bootstrapConfig(), 'before' );

		// Stripe requires its evergreen v3 URL.
		wp_register_script( self::STRIPE_JS_HANDLE, 'https://js.stripe.com/v3/', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Stripe requires the evergreen v3 URL, never a pinned copy.

		foreach ( $this->registry->all() as $slug => $component ) {
			if ( $this->componentAssetExists( 'assets/frontend/components/' . $slug . '.css' ) ) {
				wp_register_style(
					'tiermint-' . $slug,
					TIERMINT_URL . 'assets/frontend/components/' . $slug . '.css',
					array( self::TOKENS_HANDLE, self::BASE_HANDLE ),
					TIERMINT_VERSION
				);
			}

			if ( $this->componentAssetExists( 'assets/frontend/components/' . $slug . '.js' ) ) {
				wp_register_script(
					'tiermint-' . $slug,
					TIERMINT_URL . 'assets/frontend/components/' . $slug . '.js',
					array( self::BOOTSTRAP_HANDLE ),
					TIERMINT_VERSION,
					true
				);
				$this->moduleHandles[ 'tiermint-' . $slug ] = true;
			}
		}
	}

	/**
	 * Enqueue all component styles for block-editor previews.
	 *
	 * @return void
	 */
	public function enqueueEditorStyles(): void {
		if ( ! is_admin() ) {
			return;
		}

		wp_enqueue_style( self::TOKENS_HANDLE );
		wp_enqueue_style( self::BASE_HANDLE );

		foreach ( $this->registry->all() as $component ) {
			$styles = $component->assets()['styles'] ?? array();

			foreach ( array_filter( (array) $styles, 'is_string' ) as $handle ) {
				wp_enqueue_style( $handle );
			}
		}
	}

	/**
	 * Enqueue shared and component-specific assets.
	 *
	 * @param Component $component The component being rendered.
	 * @return void
	 */
	public function enqueueFor( Component $component ): void {
		wp_enqueue_style( self::TOKENS_HANDLE );
		wp_enqueue_style( self::BASE_HANDLE );
		wp_enqueue_script( self::BOOTSTRAP_HANDLE );

		$assets = $component->assets();

		foreach ( (array) ( $assets['styles'] ?? array() ) as $handle ) {
			wp_enqueue_style( $handle );
		}

		foreach ( (array) ( $assets['scripts'] ?? array() ) as $handle ) {
			wp_enqueue_script( $handle );
		}
	}

	/**
	 * Change registered component scripts to ES modules.
	 *
	 * @param string $tag    The complete script tag HTML.
	 * @param string $handle The script's registered handle.
	 * @return string
	 */
	public function filterScriptTag( string $tag, string $handle ): string {
		if ( ! isset( $this->moduleHandles[ $handle ] ) ) {
			return $tag;
		}

		if ( str_contains( $tag, 'type="module"' ) ) {
			return $tag;
		}

		return str_replace( '<script ', '<script type="module" ', $tag );
	}

	/**
	 * The inline window.tierMint bootstrap: REST root always, and a REST
	 * nonce only for logged-in visitors so anonymous pages stay fully
	 * cacheable.
	 *
	 * @return string JavaScript statement.
	 */
	private function bootstrapConfig(): string {
		$config = array(
			'apiRoot' => rest_url( 'tiermint/v1/' ),
		);

		if ( is_user_logged_in() ) {
			$config['nonce'] = wp_create_nonce( 'wp_rest' );
		}

		return 'window.tierMint = ' . wp_json_encode( $config ) . ';';
	}

	/**
	 * Whether a plugin-relative asset file exists. Extracted so unit tests
	 * can substitute the filesystem probe.
	 *
	 * @param string $relative Path relative to the plugin root.
	 * @return bool
	 */
	protected function componentAssetExists( string $relative ): bool {
		return file_exists( TIERMINT_PATH . $relative );
	}
}
