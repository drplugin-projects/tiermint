<?php
/**
 * The shared block markup for provisioned storefront pages and starter patterns.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Builds shared block markup for storefront pages.
 *
 * @since 1.0.0
 */
final class StorefrontPageContent {

	/**
	 * The store page: the product grid.
	 *
	 * @since 1.0.0
	 *
	 * @return string Block markup.
	 */
	public static function store(): string {
		return '<!-- wp:tiermint/product-grid /-->';
	}

	/**
	 * Block markup for a granular product details page.
	 *
	 * @since 1.0.0
	 *
	 * @return string Block markup.
	 */
	public static function productDetails(): string {
		return '<!-- wp:tiermint/product-images /-->

<!-- wp:tiermint/product-overview /-->

<!-- wp:tiermint/product-pricing-tiers /-->

<!-- wp:tiermint/product-description /-->

<!-- wp:tiermint/product-screenshots /-->

<!-- wp:tiermint/product-meta /-->';
	}

	/**
	 * Block markup for a wide two-column product page.
	 *
	 * @since 1.0.0
	 *
	 * @return string Block markup.
	 */
	public static function productDetailsTwoColumn(): string {
		return '<!-- wp:tiermint/product-details {"align":"wide"} /-->

<!-- wp:tiermint/product-meta {"align":"wide"} /-->';
	}

	/**
	 * Block markup for the checkout page.
	 *
	 * @since 1.0.0
	 *
	 * @return string Block markup.
	 */
	public static function checkout(): string {
		return '<!-- wp:tiermint/purchase-confirmation /-->

<!-- wp:tiermint/checkout {"align":"wide"} /-->';
	}

	/**
	 * Block markup for the account page.
	 *
	 * @since 1.0.0
	 *
	 * @return string Block markup.
	 */
	public static function account(): string {
		return '<!-- wp:tiermint/purchase-confirmation /-->

<!-- wp:tiermint/account-dashboard {"align":"wide"} /-->';
	}

	/**
	 * The sign-in page: the login form.
	 *
	 * @since 1.0.0
	 *
	 * @return string Block markup.
	 */
	public static function login(): string {
		return '<!-- wp:tiermint/login /-->';
	}

	/**
	 * The registration page: the registration form.
	 *
	 * @since 1.0.0
	 *
	 * @return string Block markup.
	 */
	public static function registration(): string {
		return '<!-- wp:tiermint/registration-form /-->';
	}

	/**
	 * The password reset page: the lost-password and set-new-password form.
	 *
	 * @since 1.0.0
	 *
	 * @return string Block markup.
	 */
	public static function passwordReset(): string {
		return '<!-- wp:tiermint/password-reset-form /-->';
	}
}
