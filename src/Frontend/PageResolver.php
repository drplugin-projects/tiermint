<?php
/**
 * Resolves the storefront page configured for each surface.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves storefront page keys to page IDs and published URLs.
 *
 * The privacy key falls back to the WordPress privacy page.
 *
 * @since 1.0.0
 */
class PageResolver {

	/**
	 * The storefront landing page listing products.
	 *
	 * @var string
	 */
	public const STORE = 'store';

	/**
	 * The single-product details page.
	 *
	 * @var string
	 */
	public const PRODUCT = 'product';

	/**
	 * The checkout page carrying the cart and payment form.
	 *
	 * @var string
	 */
	public const CHECKOUT = 'checkout';

	/**
	 * The customer account dashboard page.
	 *
	 * @var string
	 */
	public const ACCOUNT = 'account';

	/**
	 * The sign-in page.
	 *
	 * @var string
	 */
	public const LOGIN = 'login';

	/**
	 * The registration page.
	 *
	 * @var string
	 */
	public const REGISTER = 'register';

	/**
	 * The password reset page (lost-password and set-new-password form).
	 *
	 * @var string
	 */
	public const PASSWORD_RESET = 'password_reset';

	/**
	 * The terms and conditions page.
	 *
	 * @var string
	 */
	public const TERMS = 'terms';

	/**
	 * The privacy policy page.
	 *
	 * @var string
	 */
	public const PRIVACY = 'privacy';

	/**
	 * The settings option holding the pages map.
	 *
	 * @var string
	 */
	private const SETTINGS_OPTION = 'tiermint_settings';

	/**
	 * Every page key, in display order.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, string> The keys.
	 */
	public static function keys(): array {
		return array(
			self::STORE,
			self::PRODUCT,
			self::CHECKOUT,
			self::ACCOUNT,
			self::LOGIN,
			self::REGISTER,
			self::PASSWORD_RESET,
			self::TERMS,
			self::PRIVACY,
		);
	}

	/**
	 * Get the configured page ID, with the WordPress privacy fallback.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key One of the class key constants.
	 * @return int The page id, or 0.
	 */
	public function pageId( string $key ): int {
		$settings = get_option( self::SETTINGS_OPTION );
		$id       = 0;

		if ( is_array( $settings ) && isset( $settings['pages'][ $key ] ) ) {
			$id = (int) $settings['pages'][ $key ];
		}

		if ( $id <= 0 && self::PRIVACY === $key ) {
			$id = (int) get_option( 'wp_page_for_privacy_policy' );
		}

		return max( 0, $id );
	}

	/**
	 * Get a published page URL, or null.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key One of the class key constants.
	 * @return string|null The permalink, or null.
	 */
	public function url( string $key ): ?string {
		$id = $this->pageId( $key );

		if ( $id <= 0 ) {
			return null;
		}

		if ( 'publish' !== get_post_status( $id ) ) {
			return null;
		}

		$permalink = get_permalink( $id );

		return is_string( $permalink ) && '' !== $permalink ? $permalink : null;
	}

	/**
	 * Whether a post id is the page configured for a key.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key    One of the class key constants.
	 * @param int    $postId The post id to test.
	 * @return bool True when the key resolves to exactly this post id.
	 */
	public function isPage( string $key, int $postId ): bool {
		$id = $this->pageId( $key );

		return $id > 0 && $id === $postId;
	}
}
