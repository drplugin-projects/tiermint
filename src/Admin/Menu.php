<?php
/**
 * The TierMint admin menu.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Admin;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Core\Capabilities;
use DrPlugin\WP\TierMint\Core\Hookable;

/**
 * Registers the TierMint SPA menu and its hash-based submenus.
 * Extensions add their screens through `tiermint_admin_menu_items`.
 *
 * @since 1.0.0
 */
final class Menu implements Hookable {

	/**
	 * The menu icon, a 20x20 SVG of two stacked layers as a base64 data URI.
	 *
	 * A literal, so no admin page encodes it at runtime. wp-admin recolours it.
	 *
	 * @var string
	 */
	private const ICON = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMCAyMCI+PHBhdGggZmlsbD0iYmxhY2siIGQ9Ik0xMCAyIDIgNmw4IDQgOC00LTgtNHptLTggOCA4IDQgOC00djJsLTggNC04LTR2LTJ6Ii8+PC9zdmc+';

	/**
	 * The SPA controller rendering the mount page.
	 *
	 * @var SpaController
	 */
	private SpaController $spa;

	/**
	 * Wire the menu with the SPA controller.
	 *
	 * @since 1.0.0
	 *
	 * @param SpaController $spa The SPA controller rendering the mount page.
	 */
	public function __construct( SpaController $spa ) {
		$this->spa = $spa;
	}

	/**
	 * Register the menu when wp-admin builds its navigation.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'register' ) );
	}

	/**
	 * Register the top-level menu and every registry item as a submenu.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_menu_page(
			__( 'TierMint', 'tiermint' ),
			__( 'TierMint', 'tiermint' ),
			$this->parentCapability(),
			SpaController::PAGE_SLUG,
			array( $this->spa, 'render' ),
			self::ICON,
			56
		);

		foreach ( $this->items() as $item ) {
			// The dashboard is the mount page. Other items link to SPA routes.
			$isMount = '#/' === $item['hash'];
			$slug    = $isMount
				? SpaController::PAGE_SLUG
				: 'admin.php?page=' . SpaController::PAGE_SLUG . $item['hash'];

			add_submenu_page(
				SpaController::PAGE_SLUG,
				$item['title'],
				$item['title'],
				// WordPress checks this mount entry before the SPA route capability.
				// Use the parent capability so limited staff can open their allowed route.
				$isMount ? $this->parentCapability() : $item['cap'],
				$slug,
				$isMount ? array( $this->spa, 'render' ) : '' // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- ternary callback.
			);
		}
	}

	/**
	 * Return valid submenu items after the extension filter.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array<string, string>> Items as `{id, title, hash, cap}` rows.
	 */
	public function items(): array {
		/**
		 * Filters the TierMint admin menu registry.
		 *
		 * Extensions add SPA menu items here and register matching routes.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, array<string, string>> $items The registry items.
		 */
		$items = apply_filters( 'tiermint_admin_menu_items', $this->coreItems() );

		$clean = array();
		foreach ( (array) $items as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['id'], $item['title'], $item['hash'], $item['cap'] ) ) {
				continue;
			}
			$clean[] = array(
				'id'    => (string) $item['id'],
				'title' => (string) $item['title'],
				'hash'  => (string) $item['hash'],
				'cap'   => (string) $item['cap'],
			);
		}

		return $clean;
	}

	/**
	 * The core screen registry.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array<string, string>> The built-in items.
	 */
	private function coreItems(): array {
		return array(
			array(
				'id'    => 'dashboard',
				'title' => __( 'Dashboard', 'tiermint' ),
				'hash'  => '#/',
				'cap'   => Capabilities::MANAGE,
			),
			array(
				'id'    => 'products',
				'title' => __( 'Products', 'tiermint' ),
				'hash'  => '#/products',
				'cap'   => Capabilities::MANAGE,
			),
			array(
				'id'    => 'terms',
				'title' => __( 'Categories & Tags', 'tiermint' ),
				'hash'  => '#/products/terms',
				'cap'   => Capabilities::MANAGE,
			),
			array(
				'id'    => 'orders',
				'title' => __( 'Orders', 'tiermint' ),
				'hash'  => '#/orders',
				'cap'   => Capabilities::MANAGE,
			),
			array(
				'id'    => 'customers',
				'title' => __( 'Customers', 'tiermint' ),
				'hash'  => '#/customers',
				'cap'   => Capabilities::MANAGE,
			),
			array(
				'id'    => 'licenses',
				'title' => __( 'Licenses', 'tiermint' ),
				'hash'  => '#/licenses',
				'cap'   => Capabilities::MANAGE,
			),
			array(
				'id'    => 'discounts',
				'title' => __( 'Discounts', 'tiermint' ),
				'hash'  => '#/discounts',
				'cap'   => Capabilities::MANAGE,
			),
			array(
				'id'    => 'reports',
				'title' => __( 'Reports', 'tiermint' ),
				'hash'  => '#/reports',
				'cap'   => Capabilities::VIEW_REPORTS,
			),
			array(
				'id'    => 'settings',
				'title' => __( 'Settings', 'tiermint' ),
				'hash'  => '#/settings',
				'cap'   => Capabilities::MANAGE_SETTINGS,
			),
		);
	}

	/**
	 * Use the first capability the user holds so limited staff can open the SPA.
	 *
	 * @since 1.0.0
	 *
	 * @return string The capability to register the mount page with.
	 */
	private function parentCapability(): string {
		foreach ( Capabilities::all() as $cap ) {
			if ( current_user_can( $cap ) ) {
				return $cap;
			}
		}

		return Capabilities::MANAGE;
	}
}
