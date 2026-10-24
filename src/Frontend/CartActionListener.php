<?php
/**
 * Server-side handler for the no-JS cart forms.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend;

use DrPlugin\WP\TierMint\Core\Hookable;
use DrPlugin\WP\TierMint\Discounts\DiscountPersistenceException;
use DrPlugin\WP\TierMint\Discounts\DiscountService;
use DrPlugin\WP\TierMint\Discounts\DiscountValidationException;
use DrPlugin\WP\TierMint\Orders\Cart;
use DrPlugin\WP\TierMint\Orders\CartService;
use DrPlugin\WP\TierMint\Orders\OrderException;
use DrPlugin\WP\TierMint\Orders\OrderValidationException;
use DrPlugin\WP\TierMint\Util\RateLimiter;
use DrPlugin\WP\TierMint\Util\RequestUrl;

defined( 'ABSPATH' ) || exit;

/**
 * Handles nonce-verified cart form posts without JavaScript.
 *
 * Invalid discount codes share the checkout quote rate limit.
 *
 * @since 1.0.0
 */
class CartActionListener implements Hookable {

	/**
	 * Nonce action for every cart form.
	 *
	 * @var string
	 */
	public const NONCE_ACTION = 'tiermint_cart';

	/**
	 * Nonce field name for every cart form.
	 *
	 * @var string
	 */
	public const NONCE_FIELD = '_tiermint_cart_nonce';

	/**
	 * The query marker a successful add appends so the storefront can confirm it.
	 *
	 * @var string
	 */
	public const ADDED_MARKER = 'tiermint_cart_added';

	/**
	 * The query marker a failed action appends so the storefront can explain it.
	 *
	 * @var string
	 */
	public const ERROR_MARKER = 'tiermint_cart_error';

	/**
	 * The autoloaded settings option carrying the cart-redirect preference.
	 *
	 * @var string
	 */
	private const SETTINGS_OPTION = 'tiermint_settings';

	/**
	 * The rate-limit scope guarding the discount probe. Shared with the quote route
	 * so "is this code real" draws on one budget across both entry points.
	 *
	 * @var string
	 */
	private const DISCOUNT_SCOPE = 'checkout_discount';

	/**
	 * The cart service performing the mutations.
	 *
	 * @var CartService
	 */
	private CartService $cart;

	/**
	 * Resolves the checkout page for the redirect-to-checkout mode.
	 *
	 * @var PageResolver
	 */
	private PageResolver $pages;

	/**
	 * The rate limiter metering the discount probe on the shared checkout_discount scope.
	 *
	 * @var RateLimiter
	 */
	private RateLimiter $limiter;

	/**
	 * The discount engine a typed code is validated through, or null in a minimal
	 * wiring without the Discounts domain (an apply is then refused, never charged).
	 *
	 * @var DiscountService|null
	 */
	private ?DiscountService $discounts;

	/**
	 * Request terminator run after the redirect header is queued. Injected
	 * so tests can observe the exit instead of dying.
	 *
	 * @var callable(): void
	 */
	private $terminator;

	/**
	 * Wire cart form dependencies.
	 *
	 * @param CartService          $cart       Cart mutations.
	 * @param PageResolver         $pages      Checkout page resolver for the redirect mode.
	 * @param callable|null        $terminator Replaces exit in tests.
	 * @param RateLimiter|null     $limiter    Meters the discount probe; a default is built when omitted.
	 * @param DiscountService|null $discounts  Validates a typed discount code; null disables the apply path.
	 */
	public function __construct(
		CartService $cart,
		PageResolver $pages,
		?callable $terminator = null,
		?RateLimiter $limiter = null,
		?DiscountService $discounts = null
	) {
		$this->cart       = $cart;
		$this->pages      = $pages;
		$this->limiter    = $limiter ?? new RateLimiter();
		$this->discounts  = $discounts;
		$this->terminator = $terminator ?? static function (): void {
			exit;
		};
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'template_redirect', array( $this, 'maybeHandle' ) );
	}

	/**
	 * Handle a cart form POST when its nonce is valid.
	 *
	 * @return void
	 */
	public function maybeHandle(): void {
		if ( ! isset( $_POST['tiermint_cart_action'] ) ) {
			return;
		}

		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['tiermint_cart_action'] ) );

		if ( ! in_array( $action, array( 'add', 'remove', 'update', 'apply_discount', 'remove_discount' ), true ) ) {
			return;
		}

		// Discount actions use a code, not a cart line.
		if ( 'apply_discount' === $action ) {
			$code = isset( $_POST['discount_code'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['discount_code'] ) ) ) : '';
			$this->redirect( $this->applyDiscount( $code ), $action );

			return;
		}

		if ( 'remove_discount' === $action ) {
			$this->cart->setDiscountCode( null );
			$this->redirect( null, $action );

			return;
		}

		$productId = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		$priceId   = isset( $_POST['price_id'] ) ? absint( wp_unslash( $_POST['price_id'] ) ) : 0;
		$quantity  = isset( $_POST['quantity'] ) ? absint( wp_unslash( $_POST['quantity'] ) ) : 1;

		$error = null;

		try {
			switch ( $action ) {
				case 'add':
					$this->cart->addItem( $productId, $priceId, $quantity );
					break;
				case 'remove':
					$this->cart->removeItem( $productId, $priceId );
					break;
				case 'update':
					$this->cart->updateItem( $productId, $priceId, $quantity );
					break;
			}
		} catch ( OrderValidationException $e ) {
			$error = 'invalid';
		} catch ( OrderException $e ) {
			// Persistence and other domain failures: a generic marker for the
			// visitor. The exception's own message is log-only (English): it
			// is never shown to anyone.
			$error = 'failed';
		}

		$this->redirect( $error, $action );
	}

	/**
	 * Validate and store a discount code for the current cart.
	 *
	 * Refused codes use a shared rate-limit budget and one generic error marker.
	 *
	 * @param string $code The typed discount code (already unslashed and trimmed).
	 * @return string|null The `tiermint_cart_error` marker, or null on success.
	 */
	private function applyDiscount( string $code ): ?string {
		$ip                     = $this->clientIp();
		list( $limit, $window ) = $this->discountRateLimit();

		if ( null !== $this->limiter->peek( self::DISCOUNT_SCOPE, $ip, $limit, $window ) ) {
			return 'discount';
		}

		if ( '' === $code || null === $this->discounts ) {
			return 'discount';
		}

		$cart = $this->cart->getCart();

		if ( $cart->isEmpty() ) {
			return 'discount';
		}

		try {
			$application = $this->discounts->applyToCart( $code, $this->discountItems( $cart ), null );
		} catch ( DiscountValidationException $e ) {
			// A refused guess spends a probe unit, the same discipline the quote route
			// applies, so brute-forcing codes burns the shared budget.
			$this->limiter->check( self::DISCOUNT_SCOPE, $ip, $limit, $window );

			return 'discount';
		} catch ( DiscountPersistenceException $e ) {
			// A database fault, not a bad code: a generic failure, and no probe spent.
			return 'failed';
		}

		// Store the canonical code the engine matched, never the raw typed casing.
		$this->cart->setDiscountCode( $application->discount()->code() );

		return null;
	}

	/**
	 * Build discount-engine input from cart lines.
	 *
	 * @param Cart $cart The current cart.
	 * @return array<int, array{product_id: int, subtotal: int}> The priced lines.
	 */
	private function discountItems( Cart $cart ): array {
		$items = array();

		foreach ( $cart->lines() as $line ) {
			$items[] = array(
				'product_id' => $line->productId(),
				'subtotal'   => $line->subtotal(),
			);
		}

		return $items;
	}

	/**
	 * Get the checkout discount limit and window.
	 *
	 * @return array{0: int, 1: int} The resolved limit and window.
	 */
	private function discountRateLimit(): array {
		$defaults = array(
			'checkout_intent'   => array(
				'limit'  => 10,
				'window' => 600,
			),
			'checkout_quote'    => array(
				'limit'  => 60,
				'window' => 600,
			),
			'checkout_discount' => array(
				'limit'  => 10,
				'window' => 600,
			),
		);

		/** This filter is documented in {@see \DrPlugin\WP\TierMint\REST\CheckoutController::limitFor()}. */
		$limits = apply_filters( 'tiermint_rest_rate_limits', $defaults );

		$fallback   = $defaults[ self::DISCOUNT_SCOPE ];
		$configured = is_array( $limits ) && isset( $limits[ self::DISCOUNT_SCOPE ] ) && is_array( $limits[ self::DISCOUNT_SCOPE ] )
			? $limits[ self::DISCOUNT_SCOPE ]
			: $fallback;
		$limit      = max( 1, (int) ( $configured['limit'] ?? $fallback['limit'] ) );
		$window     = max( 1, (int) ( $configured['window'] ?? $fallback['window'] ) );

		return array( $limit, $window );
	}

	/**
	 * Get the client IP for the discount limit.
	 *
	 * @return string The client IP, or 'unknown' when unavailable.
	 */
	private function clientIp(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';

		return '' !== $ip ? $ip : 'unknown';
	}

	/**
	 * Redirect after a cart action.
	 *
	 * Keep the current URL and clear old notice markers.
	 *
	 * @param string|null $error  Whitelisted error code, or null on success.
	 * @param string      $action The cart action handled ('add'|'remove'|'update'|'apply_discount'|'remove_discount').
	 *                            Only 'add' is confirmed with a marker; the rest succeed silently.
	 * @return void
	 */
	private function redirect( ?string $error, string $action ): void {
		$target = esc_url_raw( RequestUrl::current() );
		$target = remove_query_arg( array( self::ERROR_MARKER, self::ADDED_MARKER ), $target );

		if ( null !== $error ) {
			$this->send( add_query_arg( self::ERROR_MARKER, $error, $target ) );

			return;
		}

		// Only an add is confirmed: remove and update succeed silently.
		if ( 'add' === $action ) {
			$checkout = $this->checkoutRedirectTarget();

			if ( null !== $checkout ) {
				$this->send( $checkout );

				return;
			}

			$target = add_query_arg( self::ADDED_MARKER, '1', $target );
		}

		$this->send( $target );
	}

	/**
	 * Get the checkout page after an add, or stay here.
	 *
	 * @return string|null The checkout URL, or null.
	 */
	private function checkoutRedirectTarget(): ?string {
		if ( 'checkout' !== $this->redirectMode() ) {
			return null;
		}

		if ( $this->pages->isPage( PageResolver::CHECKOUT, get_queried_object_id() ) ) {
			return null;
		}

		$url = $this->pages->url( PageResolver::CHECKOUT );

		if ( null === $url ) {
			return null;
		}

		return remove_query_arg( array( self::ERROR_MARKER, self::ADDED_MARKER ), esc_url_raw( $url ) );
	}

	/**
	 * The configured post-add redirect mode, defaulting to 'stay' for any value
	 * other than the explicit 'checkout'.
	 *
	 * @return string Either 'stay' or 'checkout'.
	 */
	private function redirectMode(): string {
		$settings = get_option( self::SETTINGS_OPTION );

		$mode = is_array( $settings ) && isset( $settings['storefront']['cart_redirect'] )
			? (string) $settings['storefront']['cart_redirect']
			: 'stay';

		return 'checkout' === $mode ? 'checkout' : 'stay';
	}

	/**
	 * Queue the redirect header and run the request terminator.
	 *
	 * @param string $target The already-built, host-safe redirect URL.
	 * @return void
	 */
	private function send( string $target ): void {
		wp_safe_redirect( $target );
		( $this->terminator )();
	}
}
