<?php
/**
 * Cart line value object.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Orders;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Immutable pre-checkout cart line.
 *
 * It keeps an add-time price for display. Checkout prices the line again.
 *
 * @since 1.0.0
 */
final class CartLine {

	/**
	 * The product this line refers to.
	 *
	 * @var int
	 */
	private int $productId;

	/**
	 * The price tier this line refers to.
	 *
	 * @var int
	 */
	private int $priceId;

	/**
	 * The number of units, always at least one.
	 *
	 * @var int
	 */
	private int $quantity;

	/**
	 * The per-unit amount in minor units, snapshotted when the line was added.
	 *
	 * @var int
	 */
	private int $unitAmount;

	/**
	 * The product name at add time, carried for display without a product lookup.
	 *
	 * @var string
	 */
	private string $productName;

	/**
	 * The price tier label at add time, or null when the tier has no label.
	 *
	 * @var string|null
	 */
	private ?string $priceName;

	/**
	 * Construct and validate a cart line.
	 *
	 * @since 1.0.0
	 *
	 * @param int         $productId   The product id (must be positive).
	 * @param int         $priceId     The price tier id (must be positive).
	 * @param int         $quantity    The unit count (must be at least 1).
	 * @param int         $unitAmount  The snapshotted per-unit minor-unit amount (>= 0).
	 * @param string      $productName The non-empty product name at add time.
	 * @param string|null $priceName   The price tier label at add time, or null.
	 *
	 * @throws OrderException When any invariant is violated.
	 */
	public function __construct(
		int $productId,
		int $priceId,
		int $quantity,
		int $unitAmount,
		string $productName,
		?string $priceName
	) {
		if ( $productId <= 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new OrderException( __( 'A cart line requires a valid product id.', 'tiermint' ) );
		}

		if ( $priceId <= 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new OrderException( __( 'A cart line requires a valid price id.', 'tiermint' ) );
		}

		if ( $quantity < 1 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new OrderException( __( 'A cart line quantity must be at least 1.', 'tiermint' ) );
		}

		if ( $unitAmount < 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new OrderException( __( 'A cart line unit amount cannot be negative.', 'tiermint' ) );
		}

		if ( '' === trim( $productName ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new OrderException( __( 'A cart line requires a product name.', 'tiermint' ) );
		}

		$this->productId   = $productId;
		$this->priceId     = $priceId;
		$this->quantity    = $quantity;
		$this->unitAmount  = $unitAmount;
		$this->productName = $productName;
		$this->priceName   = $priceName;
	}

	/**
	 * Return the product and price tier key.
	 *
	 * @since 1.0.0
	 *
	 * @return string The `{productId}:{priceId}` key.
	 */
	public function key(): string {
		return $this->productId . ':' . $this->priceId;
	}

	/**
	 * The line total: the snapshotted unit amount times the quantity, in minor units.
	 *
	 * @since 1.0.0
	 *
	 * @return int The line subtotal.
	 */
	public function subtotal(): int {
		return $this->unitAmount * $this->quantity;
	}

	/**
	 * Return a line with a new validated quantity.
	 *
	 * @since 1.0.0
	 *
	 * @param int $quantity The new quantity (must be at least 1).
	 * @return CartLine A new line at the requested quantity.
	 *
	 * @throws OrderException When the quantity is below 1.
	 */
	public function withQuantity( int $quantity ): CartLine {
		return new self(
			$this->productId,
			$this->priceId,
			$quantity,
			$this->unitAmount,
			$this->productName,
			$this->priceName
		);
	}

	/**
	 * The serialized shape stored in the cart transient.
	 *
	 * @since 1.0.0
	 *
	 * @return array{product_id: int, price_id: int, quantity: int, unit_amount: int, product_name: string, price_name: string|null}
	 */
	public function toArray(): array {
		return array(
			'product_id'   => $this->productId,
			'price_id'     => $this->priceId,
			'quantity'     => $this->quantity,
			'unit_amount'  => $this->unitAmount,
			'product_name' => $this->productName,
			'price_name'   => $this->priceName,
		);
	}

	/**
	 * Rebuild a line from a transient payload. The constructor validates it.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $data The serialized line.
	 * @return self
	 *
	 * @throws OrderException When the resulting line is invalid.
	 */
	public static function fromArray( array $data ): self {
		$priceName = $data['price_name'] ?? null;

		return new self(
			(int) ( $data['product_id'] ?? 0 ),
			(int) ( $data['price_id'] ?? 0 ),
			(int) ( $data['quantity'] ?? 0 ),
			(int) ( $data['unit_amount'] ?? 0 ),
			(string) ( $data['product_name'] ?? '' ),
			null === $priceName ? null : (string) $priceName
		);
	}

	/**
	 * The product id.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function productId(): int {
		return $this->productId;
	}

	/**
	 * The price tier id.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function priceId(): int {
		return $this->priceId;
	}

	/**
	 * The unit count.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function quantity(): int {
		return $this->quantity;
	}

	/**
	 * The snapshotted per-unit amount in minor units.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function unitAmount(): int {
		return $this->unitAmount;
	}

	/**
	 * The product name captured at add time.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function productName(): string {
		return $this->productName;
	}

	/**
	 * The price tier label captured at add time, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public function priceName(): ?string {
		return $this->priceName;
	}
}
