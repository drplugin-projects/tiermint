<?php
/**
 * The admin SPA mount: page render, bundle enqueue, and boot data.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Admin;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Catalog\Countries;
use DrPlugin\WP\TierMint\Catalog\Currencies;
use DrPlugin\WP\TierMint\Core\Capabilities;
use DrPlugin\WP\TierMint\Core\Hookable;
use DrPlugin\WP\TierMint\Products\Fields\CoreProductFields;
use DrPlugin\WP\TierMint\Products\Fields\ProductFieldRegistry;
use DrPlugin\WP\TierMint\Upsell\Dismissals;
use DrPlugin\WP\TierMint\Upsell\ProFeatures;
use DrPlugin\WP\TierMint\Upsell\ProStatus;

/**
 * Renders the admin SPA page and enqueues its bundle.
 *
 * Build probes are overridable because unit tests do not build assets.
 *
 * @since 1.0.0
 */
class SpaController implements Hookable {

	/**
	 * The admin page slug the SPA mounts on.
	 *
	 * @var string
	 */
	public const PAGE_SLUG = 'tiermint';

	/**
	 * The script handle of the built SPA bundle.
	 *
	 * @var string
	 */
	public const HANDLE = 'tiermint-admin';

	/**
	 * The admin screen hook of the SPA page.
	 *
	 * @var string
	 */
	private const PAGE_HOOK = 'toplevel_page_tiermint';

	/**
	 * The Pro-presence seam.
	 *
	 * @var ProStatus
	 */
	private ProStatus $proStatus;

	/**
	 * The upsell copy source.
	 *
	 * @var ProFeatures
	 */
	private ProFeatures $proFeatures;

	/**
	 * The per-user upsell dismissal store.
	 *
	 * @var Dismissals
	 */
	private Dismissals $dismissals;

	/**
	 * Wire the controller with the upsell seams.
	 *
	 * They default so the many `new SpaController()` call sites and tests need no
	 * change; the container injects the shared singletons.
	 *
	 * @since 1.0.0
	 *
	 * @param ProStatus|null   $proStatus   The Pro-presence seam.
	 * @param ProFeatures|null $proFeatures The upsell copy source.
	 * @param Dismissals|null  $dismissals  The dismissal store.
	 */
	public function __construct( ?ProStatus $proStatus = null, ?ProFeatures $proFeatures = null, ?Dismissals $dismissals = null ) {
		$this->proStatus   = $proStatus ?? new ProStatus();
		$this->proFeatures = $proFeatures ?? new ProFeatures();
		$this->dismissals  = $dismissals ?? new Dismissals();
	}

	/**
	 * Enqueue the bundle on the SPA page only.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Render the SPA root and noscript notice.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render(): void {
		/*
		 * App.js adds the shell inside this node. Do not duplicate its class.
		 */
		echo '<div id="tiermint-admin-root"></div>';
		echo '<noscript><div class="notice notice-error"><p>';
		echo esc_html__( 'The TierMint admin requires JavaScript. Please enable it to manage your store.', 'tiermint' );
		echo '</p></div></noscript>';
	}

	/**
	 * Enqueue the SPA bundle and boot data when a build exists.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook The current admin screen hook.
	 * @return void
	 */
	public function enqueue( string $hook ): void {
		if ( self::PAGE_HOOK !== $hook ) {
			return;
		}

		$asset = $this->buildAsset();
		if ( null !== $asset ) {
			$version = (string) ( $asset['version'] ?? TIERMINT_VERSION );

			wp_enqueue_script(
				self::HANDLE,
				TIERMINT_URL . 'assets/admin/build/index.js',
				// The classic-editor handles the rich text control drives are added
				// to the dependency list so they load, and so the bundle evaluates
				// with window.wp.editor present. Readiness (the default settings,
				// printed later at footer priority 45) is handled in the control.
				array_merge( $asset['dependencies'] ?? array(), array( 'editor', 'quicktags' ) ),
				$version,
				true
			);

			/*
			 * Load translations for the SPA bundle.
			 */
			wp_set_script_translations( self::HANDLE, 'tiermint', TIERMINT_PATH . 'languages' );

			/*
			 * Product image controls use the WordPress media library.
			 */
			wp_enqueue_media();

			/*
			 * Product rich text controls use the WordPress classic editor.
			 */
			wp_enqueue_editor();

			// The SPA renders @wordpress/components; their stylesheet ships with core.
			wp_enqueue_style( 'wp-components' );

			/*
			 * The stylesheet can be absent from older asset builds.
			 */
			if ( $this->styleBuilt() ) {
				wp_enqueue_style(
					self::HANDLE,
					TIERMINT_URL . 'assets/admin/build/index.css',
					array(),
					$version
				);

				/*
				 * Use the generated RTL stylesheet when WordPress selects it.
				 */
				wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
			}

			wp_add_inline_script(
				self::HANDLE,
				// The hex flags keep the payload safe inside a script element even
				// if a future boot field ever carries markup-like characters.
				'window.tierMint = ' . wp_json_encode(
					$this->bootData(),
					JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
				) . ';',
				'before'
			);
		}

		/**
		 * Fires after the core admin bundle enqueue.
		 *
		 * @since 1.0.0
		 *
		 * @param string $handle The core SPA bundle script handle.
		 */
		do_action( 'tiermint_admin_enqueue', self::HANDLE );
	}

	/**
	 * Read the built asset manifest, or null when absent.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>|null The manifest, or null when absent.
	 */
	protected function buildAsset(): ?array {
		$assetFile = TIERMINT_PATH . 'assets/admin/build/index.asset.php';

		if ( ! file_exists( $assetFile ) ) {
			return null;
		}

		$asset = include $assetFile;

		// A manifest that is not an array is as unusable as a missing one, but
		// an empty array still enqueues: the defaults below cover both fields.
		return is_array( $asset ) ? $asset : array();
	}

	/**
	 * Whether the build emitted the SPA stylesheet.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when `assets/admin/build/index.css` exists.
	 */
	protected function styleBuilt(): bool {
		return file_exists( TIERMINT_PATH . 'assets/admin/build/index.css' );
	}

	/**
	 * The boot data printed before the bundle.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed> The boot payload.
	 */
	public function bootData(): array {
		$settings = get_option( 'tiermint_settings' );
		$settings = is_array( $settings ) ? $settings : array();

		$caps = array();
		foreach ( Capabilities::all() as $cap ) {
			$caps[ $cap ] = current_user_can( $cap );
		}

		// A WordPress capability rather than one of ours, because the lists link
		// registered customers straight into the user editor. A shop manager runs the
		// store without it, and for them the link would land on a permission wall, so
		// the screens print plain text instead.
		$caps['edit_users'] = current_user_can( 'edit_users' );

		$fields = new ProductFieldRegistry( new CoreProductFields() );

		$data = array(
			'apiRoot'       => esc_url_raw( rest_url( 'tiermint/v1/' ) ),
			'nonce'         => wp_create_nonce( 'wp_rest' ),
			'caps'          => $caps,
			'settings'      => array(
				'currency' => isset( $settings['currency'] ) ? (string) $settings['currency'] : 'USD',
			),
			// What to put between the items of a list the admin screens print in
			// a sentence or a table cell. WordPress translates it per locale, and
			// a hardcoded comma and space is wrong in several of them, so the
			// bundles read it from here rather than each writing their own.
			'listSeparator' => wp_get_list_item_separator(),
			'pro'           => $this->proStatus->isActive(),
			// The product editor renders these fields and their panels; the same
			// registry sanitizes them on save, so the two never drift.
			'productFields' => array(
				'fields' => $fields->forClient(),
				'panels' => $fields->panels(),
			),
			// The settings dropdowns (currency, country, page) read these here so
			// the screen fills them without a second request. The same lists are
			// served publicly by CatalogController for logged-out consumers. The
			// page list is gated on the settings capability, matching that
			// endpoint: it discloses site structure, and only the settings screen
			// (which needs that capability) renders a page picker, so it stays out
			// of the payload for staff without it, which also spares them the
			// get_pages() query on every screen.
			'catalog'       => array(
				'countries'  => $this->countryList(),
				'currencies' => $this->currencyList(),
				'pages'      => ! empty( $caps[ Capabilities::MANAGE_SETTINGS ] ) ? $this->pageList() : array(),
			),
			'admin'         => array(
				'routes' => array(),
			),
		);

		// The upsell copy and dismissals ride the boot object only while Pro is
		// absent, so the SPA surfaces need no round-trip and cannot render when
		// Pro is active.
		if ( ! $this->proStatus->isActive() ) {
			$data['upsell'] = array(
				'url'       => $this->proStatus->upgradeUrl(),
				'intro'     => $this->proFeatures->intro(),
				'notice'    => $this->proFeatures->notice(),
				'linkLabel' => $this->proFeatures->linkLabel(),
				'ctaLabel'  => $this->proFeatures->ctaLabel(),
				'features'  => $this->proFeatures->all(),
				'dismissed' => $this->dismissals->topics(),
			);
		}

		return $data;
	}

	/**
	 * Return the country catalog for SPA dropdowns.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array{code: string, name: string}>
	 */
	private function countryList(): array {
		$out = array();

		foreach ( Countries::all() as $code => $name ) {
			$out[] = array(
				'code' => (string) $code,
				'name' => (string) $name,
			);
		}

		return $out;
	}

	/**
	 * The currency catalog as an ordered list of `{code, label, symbol, decimals}`.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array{code: string, label: string, symbol: string, decimals: int}>
	 */
	private function currencyList(): array {
		$out = array();

		foreach ( Currencies::all() as $code => $entry ) {
			$out[] = array(
				'code'     => (string) $code,
				'label'    => (string) $entry['label'],
				'symbol'   => (string) $entry['symbol'],
				'decimals' => (int) $entry['decimals'],
			);
		}

		return $out;
	}

	/**
	 * Return published pages for settings page pickers.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array{id: int, title: string}>
	 */
	private function pageList(): array {
		$pages = get_pages();
		if ( ! is_array( $pages ) ) {
			$pages = array();
		}

		$out = array();

		foreach ( $pages as $page ) {
			$title = trim( (string) $page->post_title );

			$out[] = array(
				'id'    => (int) $page->ID,
				'title' => '' !== $title ? $title : __( '(no title)', 'tiermint' ),
			);
		}

		return $out;
	}
}
