<?php
/**
 * Hides the front-end admin toolbar for customers.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend;

use DrPlugin\WP\TierMint\Core\Hookable;

defined( 'ABSPATH' ) || exit;

/**
 * Hides the storefront toolbar for customers when the setting is on.
 *
 * @since 1.0.0
 */
class AdminBarController implements Hookable {

	/**
	 * The settings option holding the toggle.
	 *
	 * @var string
	 */
	private const SETTINGS_OPTION = 'tiermint_settings';

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_filter( 'show_admin_bar', array( $this, 'maybeHide' ) );
	}

	/**
	 * Hide the toolbar for a logged-in customer when the setting is on.
	 *
	 * @param bool $show Whether WordPress would otherwise show the bar.
	 * @return bool The resolved visibility.
	 */
	public function maybeHide( $show ): bool {
		// WordPress controls the toolbar for logged-out visitors.
		if ( ! is_user_logged_in() ) {
			return (bool) $show;
		}

		if ( ! $this->hideEnabled() ) {
			return (bool) $show;
		}

		// Anyone who can edit content keeps the bar; only customers lose it.
		if ( current_user_can( 'edit_posts' ) ) {
			return (bool) $show;
		}

		return false;
	}

	/**
	 * Whether the hide-admin-bar setting is on, defaulting off when unconfigured.
	 *
	 * @return bool
	 */
	private function hideEnabled(): bool {
		$settings = get_option( self::SETTINGS_OPTION );

		if ( is_array( $settings ) && isset( $settings['frontend']['hide_admin_bar'] ) ) {
			return (bool) $settings['frontend']['hide_admin_bar'];
		}

		return false;
	}
}
