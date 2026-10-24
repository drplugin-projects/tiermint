<?php
/**
 * Cart domain service: the single entry point for cart operations.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Orders;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Products\Product;
use DrPlugin\WP\TierMint\Products\ProductPrice;
use DrPlugin\WP\TierMint\Products\ProductService;
use DrPlugin\WP\TierMint\Products\ProductStatus;

/**
 * Cart operations for shortcodes, blocks, REST and checkout.
 *
 * It validates catalog lines, snapshots their prices and stores carts by cookie token.
 *
 * @since 1.0.0
 */
class CartService {

	/**
	 * The repository carts are read from and written through (cookie + transient).
	 *
	 * @var CartRepository
	 */
	private CartRepository $repository;

	/**
	 * The product catalog used to resolve and price cart lines.
	 *
	 * @var ProductService
	 */
	private ProductService $products;

	/**
	 * The current request token. A new token is reused before the cookie returns.
	 *
	 * @var string|null
	 */
	private ?string $token = null;

	/**
	 * Whether this request cleared the cart, so the cookie it came with no longer counts.
	 *
	 * @var bool
	 */
	private bool $cleared = false;

	/**
	 * Wire the service with its repository and the product catalog.
	 *
	 * @since 1.0.0
	 *
	 * @param CartRepository $repository The cart repository (cookie + transient seam).
	 * @param ProductService $products   The product catalog for line resolution.
	 */
	public function __construct( CartRepository $repository, ProductService $products ) {
		$this->repository = $repository;
		$this->products   = $products;
	}

	/**
	 * Return the current cart, or an empty cart when no token exists.
	 *
	 * @since 1.0.0
	 *
	 * @return Cart The current cart.
	 */
	public function getCart(): Cart {
		$token = $this->currentToken();

		return null === $token ? new Cart() : $this->repository->find( $token );
	}

	/**
	 * Add a product price tier and save the cart.
	 *
	 * @since 1.0.0
	 *
	 * @param int $productId The product to add.
	 * @param int $priceId   The price tier to add.
	 * @param int $quantity  The number of units to add (must be at least 1).
	 * @return Cart The updated cart.
	 *
	 * @throws OrderValidationException When the quantity is below 1, or the product or
	 *                                  price is not available for purchase.
	 */
	public function addItem( int $productId, int $priceId, int $quantity = 1 ): Cart {
		if ( $quantity < 1 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw OrderValidationException::withErrors( array( __( 'A quantity must be at least 1.', 'tiermint' ) ) );
		}

		list( $product, $price ) = $this->resolvePurchasable( $productId, $priceId );

		$line = new CartLine( $productId, $priceId, $price->clampQuantity( $quantity ), $price->amount(), $product->name(), $price->name() );
		$cart = $this->getCart()->withItem( $line );

		// Re-clamp merged lines after a repeat add.
		$cart = $cart->withQuantity( $productId, $priceId, $price->clampQuantity( $cart->quantityFor( $productId, $priceId ) ) );

		$this->persist( $cart );

		return $cart;
	}

	/**
	 * Set, add or remove a cart line. Existing lines keep their price snapshots.
	 *
	 * @since 1.0.0
	 *
	 * @param int $productId The product to update.
	 * @param int $priceId   The price tier to update.
	 * @param int $quantity  The exact quantity (<= 0 removes the line).
	 * @return Cart The updated cart.
	 *
	 * @throws OrderValidationException When a newly added line's product or price is not
	 *                                  available for purchase.
	 */
	public function updateItem( int $productId, int $priceId, int $quantity ): Cart {
		$cart = $this->getCart();

		if ( $quantity <= 0 ) {
			$cart = $cart->without( $productId, $priceId );
		} elseif ( $cart->hasLine( $productId, $priceId ) ) {
			// Apply the live quantity rule without replacing the saved price.
			$price    = $this->findActivePrice( $productId, $priceId );
			$quantity = null !== $price ? $price->clampQuantity( $quantity ) : $quantity;
			$cart     = $cart->withQuantity( $productId, $priceId, $quantity );
		} else {
			$cart = $cart->withItem( $this->resolveLine( $productId, $priceId, $quantity ) );
		}

		$this->persist( $cart );

		return $cart;
	}

	/**
	 * Remove a line from the cart and persist the result.
	 *
	 * @since 1.0.0
	 *
	 * @param int $productId The product to remove.
	 * @param int $priceId   The price tier to remove.
	 * @return Cart The updated cart.
	 */
	public function removeItem( int $productId, int $priceId ): Cart {
		$cart = $this->getCart()->without( $productId, $priceId );

		$this->persist( $cart );

		return $cart;
	}

	/**
	 * Store or clear a discount code on the current cart.
	 *
	 * Pricing validates the code. Empty carts are not stored.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $code The code to apply, or null/blank to clear.
	 * @return Cart The updated cart.
	 */
	public function setDiscountCode( ?string $code ): Cart {
		$cart = $this->getCart();
		$cart = ( null === $code || '' === trim( $code ) )
			? $cart->withoutDiscountCode()
			: $cart->withDiscountCode( $code );

		$this->persist( $cart );

		return $cart;
	}

	/**
	 * Delete the cart and forget its token.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function clear(): void {
		$token = $this->currentToken();

		if ( null !== $token ) {
			$this->repository->delete( $token );
		}

		$this->repository->clearCookie();
		$this->token   = null;
		$this->cleared = true;
	}

	/**
	 * Save a cart, or clear it when empty.
	 *
	 * @since 1.0.0
	 *
	 * @param Cart $cart The cart to persist.
	 * @return void
	 */
	private function persist( Cart $cart ): void {
		if ( $cart->isEmpty() ) {
			$this->clear();

			return;
		}

		$this->repository->save( $this->ensureToken(), $cart );
	}

	/**
	 * Resolve a purchasable product price into a cart line.
	 *
	 * @since 1.0.0
	 *
	 * @param int $productId The product to resolve.
	 * @param int $priceId   The price tier to resolve.
	 * @param int $quantity  The requested line quantity (clamped into the tier's rule).
	 * @return CartLine The resolved, priced, quantity-clamped line.
	 *
	 * @throws OrderValidationException When the product or price is not purchasable.
	 */
	private function resolveLine( int $productId, int $priceId, int $quantity ): CartLine {
		list( $product, $price ) = $this->resolvePurchasable( $productId, $priceId );

		return new CartLine( $productId, $priceId, $price->clampQuantity( $quantity ), $price->amount(), $product->name(), $price->name() );
	}

	/**
	 * Resolve a published product and an active purchasable price tier.
	 *
	 * @since 1.0.0
	 *
	 * @param int $productId The product to resolve.
	 * @param int $priceId   The price tier to resolve.
	 * @return array{0: Product, 1: ProductPrice} The published product and its active tier.
	 *
	 * @throws OrderValidationException When the product or price is not purchasable.
	 */
	private function resolvePurchasable( int $productId, int $priceId ): array {
		$product = $this->products->get( $productId );

		if ( null === $product ) {
			/* translators: %d: the product id. */
			$message = sprintf( __( 'Product %d was not found.', 'tiermint' ), $productId );

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw OrderValidationException::withErrors( array( $message ) );
		}

		if ( ProductStatus::Published !== $product->status() ) {
			/* translators: %d: the product id. */
			$message = sprintf( __( 'Product %d is not available for purchase.', 'tiermint' ), $productId );

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw OrderValidationException::withErrors( array( $message ) );
		}

		$price = $product->activePrice( $priceId );

		if ( null === $price ) {
			/* translators: 1: the price id, 2: the product id. */
			$message = sprintf( __( 'Price %1$d is not available for product %2$d.', 'tiermint' ), $priceId, $productId );

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw OrderValidationException::withErrors( array( $message ) );
		}

		return array( $product, $price );
	}

	/**
	 * Find an active price tier for an existing line, without validation errors.
	 *
	 * @since 1.0.0
	 *
	 * @param int $productId The product id.
	 * @param int $priceId   The price tier id.
	 * @return ProductPrice|null The active tier, or null.
	 */
	private function findActivePrice( int $productId, int $priceId ): ?ProductPrice {
		$product = $this->products->get( $productId );

		return null === $product ? null : $product->activePrice( $priceId );
	}

	/**
	 * Return the cached or cookie cart token.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null The current token, or null when none is present.
	 */
	private function currentToken(): ?string {
		if ( null !== $this->token ) {
			return $this->token;
		}

		// The request still carries the cookie a clear just dropped.
		if ( $this->cleared ) {
			return null;
		}

		$this->token = $this->repository->readToken();

		return $this->token;
	}

	/**
	 * Return a cart token, creating one when needed.
	 *
	 * @since 1.0.0
	 *
	 * @return string The current, guaranteed non-null token.
	 */
	private function ensureToken(): string {
		$token = $this->currentToken();

		if ( null !== $token ) {
			return $token;
		}

		$token = $this->repository->generateToken();
		$this->repository->writeToken( $token );
		$this->token = $token;

		return $token;
	}
}
