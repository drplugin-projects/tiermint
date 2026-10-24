<?php
/**
 * Cart repository: the cookie token and transient persistence for carts.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Orders;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Stores carts in token-keyed transients and the token in a first-party cookie.
 *
 * Carts expire after 48 hours and do not require an account.
 *
 * @since 1.0.0
 */
class CartRepository {

	/**
	 * The name of the first-party cookie carrying the cart token.
	 *
	 * @var string
	 */
	public const COOKIE = 'tiermint_cart';

	/**
	 * The transient key prefix; the full key is this prefix plus the cart token.
	 *
	 * @var string
	 */
	private const PREFIX = 'tiermint_cart_';

	/**
	 * Cart lifetime in hours.
	 *
	 * @var int
	 */
	private const TTL_HOURS = 48;

	/**
	 * Read and sanitize the cart cookie token.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null The sanitized token, or null when no usable cookie is present.
	 */
	public function readToken(): ?string {
		if ( empty( $_COOKIE[ self::COOKIE ] ) ) {
			return null;
		}

		$token = sanitize_key( wp_unslash( $_COOKIE[ self::COOKIE ] ) );

		return '' === $token ? null : $token;
	}

	/**
	 * Store the cart token for the next request. Skip writes after headers are sent.
	 *
	 * @since 1.0.0
	 *
	 * @param string $token The cart token to store.
	 * @return void
	 */
	public function writeToken( string $token ): void {
		if ( headers_sent() ) {
			return;
		}

		setcookie(
			self::COOKIE,
			$token,
			time() + $this->ttlSeconds(),
			defined( 'COOKIEPATH' ) ? COOKIEPATH : '/',
			defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
			is_ssl(),
			true
		);
	}

	/**
	 * Expire the cart cookie by resending it empty with a past expiry, unless headers
	 * have already been sent.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function clearCookie(): void {
		if ( headers_sent() ) {
			return;
		}

		setcookie(
			self::COOKIE,
			'',
			time() - $this->ttlSeconds(),
			defined( 'COOKIEPATH' ) ? COOKIEPATH : '/',
			defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
			is_ssl(),
			true
		);
	}

	/**
	 * Mint a fresh, unguessable cart token.
	 *
	 * @since 1.0.0
	 *
	 * @return string A new UUID v4 token.
	 */
	public function generateToken(): string {
		return wp_generate_uuid4();
	}

	/**
	 * Return the cart lifetime in seconds.
	 *
	 * @since 1.0.0
	 *
	 * @return int The cart TTL in seconds.
	 */
	private function ttlSeconds(): int {
		return self::TTL_HOURS * HOUR_IN_SECONDS;
	}

	/**
	 * Load a cart, or return an empty cart for missing or invalid data.
	 *
	 * @since 1.0.0
	 *
	 * @param string $token The cart token.
	 * @return Cart The stored cart, or an empty one.
	 */
	public function find( string $token ): Cart {
		$data = get_transient( self::PREFIX . $token );

		return is_array( $data ) ? Cart::fromArray( $data ) : new Cart();
	}

	/**
	 * Store a cart under a token, refreshing its 48 hour TTL.
	 *
	 * @since 1.0.0
	 *
	 * @param string $token The cart token.
	 * @param Cart   $cart  The cart to store.
	 * @return void
	 */
	public function save( string $token, Cart $cart ): void {
		set_transient( self::PREFIX . $token, $cart->toArray(), $this->ttlSeconds() );
	}

	/**
	 * Delete the cart stored under a token.
	 *
	 * @since 1.0.0
	 *
	 * @param string $token The cart token.
	 * @return void
	 */
	public function delete( string $token ): void {
		delete_transient( self::PREFIX . $token );
	}
}
