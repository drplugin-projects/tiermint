<?php
/**
 * Order line item value object.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Orders;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Immutable order line with product and price snapshots.
 *
 * Amounts are non-negative minor units. Refunds use a separate order.
 *
 * @since 1.0.0
 */
final class OrderItem {

	/**
	 * Primary key, or null for a line that has not been persisted yet.
	 *
	 * @var int|null
	 */
	private ?int $id;

	/**
	 * Owning order id, or null before the line is attached to an order.
	 *
	 * @var int|null
	 */
	private ?int $orderId;

	/**
	 * Purchased product id (always a real product, so strictly positive).
	 *
	 * @var int
	 */
	private int $productId;

	/**
	 * Purchased price tier id, or null for a line with no stored tier.
	 *
	 * @var int|null
	 */
	private ?int $priceId;

	/**
	 * Product name snapshot as it read at purchase time.
	 *
	 * @var string
	 */
	private string $productName;

	/**
	 * Price tier name snapshot at purchase time, or null when there was no tier.
	 *
	 * @var string|null
	 */
	private ?string $priceName;

	/**
	 * Number of units purchased on this line (at least one).
	 *
	 * @var int
	 */
	private int $quantity;

	/**
	 * Line subtotal before discount and tax, in unsigned minor units.
	 *
	 * @var int
	 */
	private int $subtotal;

	/**
	 * Discount applied to this line, in unsigned minor units.
	 *
	 * @var int
	 */
	private int $discount;

	/**
	 * Tax charged on this line, in unsigned minor units (0 unless an extension supplies tax).
	 *
	 * @var int
	 */
	private int $tax;

	/**
	 * Line total after discount and tax, in unsigned minor units.
	 *
	 * @var int
	 */
	private int $total;

	/**
	 * Construct and validate an order line item.
	 *
	 * @since 1.0.0
	 *
	 * @param int|null    $id          Primary key, or null when unsaved.
	 * @param int|null    $orderId     Owning order id, or null when detached.
	 * @param int         $productId   Purchased product id (must be > 0).
	 * @param int|null    $priceId     Purchased price tier id, or null.
	 * @param string      $productName Non-empty product name snapshot.
	 * @param string|null $priceName   Price tier name snapshot, or null.
	 * @param int         $quantity    Units purchased (must be >= 1).
	 * @param int         $subtotal    Line subtotal in minor units (must be >= 0).
	 * @param int         $discount    Line discount in minor units (must be >= 0).
	 * @param int         $tax         Line tax in minor units (must be >= 0).
	 * @param int         $total       Line total in minor units (must be >= 0).
	 *
	 * @throws OrderException When the product id, name, quantity, or any amount is invalid.
	 */
	public function __construct(
		?int $id,
		?int $orderId,
		int $productId,
		?int $priceId,
		string $productName,
		?string $priceName,
		int $quantity,
		int $subtotal,
		int $discount,
		int $tax,
		int $total
	) {
		if ( $productId <= 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new OrderException( __( 'An order item must reference a valid product.', 'tiermint' ) );
		}

		if ( '' === trim( $productName ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new OrderException( __( 'An order item must have a non-empty product name.', 'tiermint' ) );
		}

		if ( $quantity < 1 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new OrderException( __( 'An order item quantity must be at least one.', 'tiermint' ) );
		}

		// A line item, unlike the order aggregate, only ever carries non-negative
		// amounts: refunds live on their own negative-total order rows, never as a
		// negative line here. Reject negatives so they cannot reach the columns.
		if ( $subtotal < 0 || $discount < 0 || $tax < 0 || $total < 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new OrderException( __( 'An order item amount cannot be negative.', 'tiermint' ) );
		}

		$this->id          = $id;
		$this->orderId     = $orderId;
		$this->productId   = $productId;
		$this->priceId     = $priceId;
		$this->productName = $productName;
		$this->priceName   = $priceName;
		$this->quantity    = $quantity;
		$this->subtotal    = $subtotal;
		$this->discount    = $discount;
		$this->tax         = $tax;
		$this->total       = $total;
	}

	/**
	 * Build a line from request or storage data.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $data Input keyed by column name.
	 * @return self
	 *
	 * @throws OrderException When the resulting line is invalid.
	 */
	public static function fromArray( array $data ): self {
		return new self(
			self::nullableInt( $data['id'] ?? null ),
			self::nullableInt( $data['order_id'] ?? null ),
			(int) ( $data['product_id'] ?? 0 ),
			self::nullableInt( $data['price_id'] ?? null ),
			(string) ( $data['product_name'] ?? '' ),
			self::nullableString( $data['price_name'] ?? null ),
			(int) ( $data['quantity'] ?? 1 ),
			(int) ( $data['subtotal'] ?? 0 ),
			(int) ( $data['discount'] ?? 0 ),
			(int) ( $data['tax'] ?? 0 ),
			(int) ( $data['total'] ?? 0 )
		);
	}

	/**
	 * Build a line from a database row (all scalars are stringy from `$wpdb`).
	 *
	 * @since 1.0.0
	 *
	 * @param object $row A `tiermint_order_items` row.
	 * @return self
	 *
	 * @throws OrderException When the row violates a line invariant.
	 */
	public static function fromRow( object $row ): self {
		return self::fromArray( (array) $row );
	}

	/**
	 * Copy the line with tax and a recalculated total.
	 *
	 * Tax applies after discounts. The constructor rejects negative values.
	 *
	 * @since 1.0.0
	 *
	 * @param int $tax The line tax in minor units (must be >= 0).
	 * @return self The new line item.
	 *
	 * @throws OrderException When the tax is negative.
	 */
	public function withTax( int $tax ): self {
		return new self(
			$this->id,
			$this->orderId,
			$this->productId,
			$this->priceId,
			$this->productName,
			$this->priceName,
			$this->quantity,
			$this->subtotal,
			$this->discount,
			$tax,
			$this->subtotal - $this->discount + $tax
		);
	}

	/**
	 * Copy the line with new money figures and a total derived from them.
	 *
	 * The constructor rejects negative values.
	 *
	 * @since 1.0.0
	 *
	 * @param int $subtotal The line subtotal in minor units.
	 * @param int $discount The line discount in minor units.
	 * @param int $tax      The line tax in minor units.
	 * @return self The new line item.
	 *
	 * @throws OrderException When an amount or the derived total is negative.
	 */
	public function withAmounts( int $subtotal, int $discount, int $tax ): self {
		return new self(
			$this->id,
			$this->orderId,
			$this->productId,
			$this->priceId,
			$this->productName,
			$this->priceName,
			$this->quantity,
			$subtotal,
			$discount,
			$tax,
			$subtotal - $discount + $tax
		);
	}

	/**
	 * Get columns written by the repository.
	 *
	 * The repository supplies the id and order id.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed> Column name => value.
	 */
	public function toRow(): array {
		return array(
			'product_id'   => $this->productId,
			'price_id'     => $this->priceId,
			'product_name' => $this->productName,
			'price_name'   => $this->priceName,
			'quantity'     => $this->quantity,
			'subtotal'     => $this->subtotal,
			'discount'     => $this->discount,
			'tax'          => $this->tax,
			'total'        => $this->total,
		);
	}

	/**
	 * The primary key, or null when unsaved.
	 *
	 * @since 1.0.0
	 *
	 * @return int|null
	 */
	public function id(): ?int {
		return $this->id;
	}

	/**
	 * The owning order id, or null when detached.
	 *
	 * @since 1.0.0
	 *
	 * @return int|null
	 */
	public function orderId(): ?int {
		return $this->orderId;
	}

	/**
	 * The purchased product id.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function productId(): int {
		return $this->productId;
	}

	/**
	 * The purchased price tier id, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return int|null
	 */
	public function priceId(): ?int {
		return $this->priceId;
	}

	/**
	 * The product name snapshot.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function productName(): string {
		return $this->productName;
	}

	/**
	 * The price tier name snapshot, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public function priceName(): ?string {
		return $this->priceName;
	}

	/**
	 * The purchased quantity.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function quantity(): int {
		return $this->quantity;
	}

	/**
	 * The line subtotal in minor units.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function subtotal(): int {
		return $this->subtotal;
	}

	/**
	 * The line discount in minor units.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function discount(): int {
		return $this->discount;
	}

	/**
	 * The line tax in minor units.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function tax(): int {
		return $this->tax;
	}

	/**
	 * The line total in minor units.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function total(): int {
		return $this->total;
	}

	/**
	 * Cast a nullable numeric value to int.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value The raw value.
	 * @return int|null The int value, or null when the input was null.
	 */
	private static function nullableInt( $value ): ?int {
		return null === $value ? null : (int) $value;
	}

	/**
	 * Cast a nullable text value to a non-empty string.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value The raw value.
	 * @return string|null The string value, or null when the input was null or empty.
	 */
	private static function nullableString( $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		$string = (string) $value;

		return '' === $string ? null : $string;
	}
}
