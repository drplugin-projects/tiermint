<?php
/**
 * Cart notice view-data helper for the storefront templates.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Maps cart redirect markers to template notice data.
 *
 * @since 1.0.0
 */
final class CartNotices {

	/**
	 * The notice view data for the current request.
	 *
	 * @since 1.0.0
	 *
	 * @param PageResolver|null $pages The checkout-page resolver, or null to use a default one.
	 * @return array{success: array{show: bool, text: string, checkout_url: string|null}, error: array{show: bool, text: string}}
	 */
	public static function view( ?PageResolver $pages = null ): array {
		return array(
			'success' => self::success( $pages ),
			'error'   => self::error(),
		);
	}

	/**
	 * Get the confirmation notice for a successful add.
	 *
	 * @since 1.0.0
	 *
	 * @param PageResolver|null $pages The checkout-page resolver, or null for a default.
	 * @return array{show: bool, text: string, checkout_url: string|null}
	 */
	private static function success( ?PageResolver $pages ): array {
		$added = isset( $_GET[ CartActionListener::ADDED_MARKER ] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of a redirect marker; no state change.
			? sanitize_key( wp_unslash( $_GET[ CartActionListener::ADDED_MARKER ] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of a redirect marker; no state change.
			: '';

		if ( '1' !== $added ) {
			return array(
				'show'         => false,
				'text'         => '',
				'checkout_url' => null,
			);
		}

		$pages = $pages ?? new PageResolver();

		return array(
			'show'         => true,
			'text'         => __( 'Added to your cart.', 'tiermint' ),
			'checkout_url' => $pages->url( PageResolver::CHECKOUT ),
		);
	}

	/**
	 * Get the notice for a recognised cart error.
	 *
	 * @since 1.0.0
	 *
	 * @return array{show: bool, text: string}
	 */
	private static function error(): array {
		$code = isset( $_GET[ CartActionListener::ERROR_MARKER ] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of a redirect marker; no state change.
			? sanitize_key( wp_unslash( $_GET[ CartActionListener::ERROR_MARKER ] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of a redirect marker; no state change.
			: '';

		if ( '' === $code ) {
			return array(
				'show' => false,
				'text' => '',
			);
		}

		$messages = array(
			'invalid' => __( 'That item could not be added to the cart.', 'tiermint' ),
			'failed'  => __( 'Something went wrong updating your cart. Please try again.', 'tiermint' ),
		);

		if ( ! isset( $messages[ $code ] ) ) {
			return array(
				'show' => false,
				'text' => '',
			);
		}

		return array(
			'show' => true,
			'text' => $messages[ $code ],
		);
	}
}
