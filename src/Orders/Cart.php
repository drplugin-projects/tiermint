<?php
/**
 * Cart aggregate value object.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Orders;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Immutable pre-checkout cart, keyed by product and price tier.
 *
 * CartRepository persists it and CartService sets price snapshots.
 *
 * @since 1.0.0
 */
final class Cart {

	/**
	 * The cart's lines, keyed by {@see CartLine::key()}.
	 *
	 * @var array<string, CartLine>
	 */
	private array $lines;

	/**
	 * The applied discount code. It is validated during pricing.
	 *
	 * @var string|null
	 */
	private ?string $discountCode;

	/**
	 * Construct a cart from lines and an optional discount code.
	 *
	 * Duplicate lines use the last value. Blank codes become null.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, CartLine> $lines        The lines to index (defaults to empty).
	 * @param string|null          $discountCode The applied discount code, or null.
	 */
	public function __construct( array $lines = array(), ?string $discountCode = null ) {
		$indexed = array();

		foreach ( $lines as $line ) {
			$indexed[ $line->key() ] = $line;
		}

		$this->lines        = $indexed;
		$this->discountCode = ( null === $discountCode || '' === trim( $discountCode ) ) ? null : trim( $discountCode );
	}

	/**
	 * Add a line, or increase the matching line without changing its price snapshot.
	 *
	 * @since 1.0.0
	 *
	 * @param CartLine $line The line to add or merge in.
	 * @return Cart A new cart with the line applied.
	 */
	public function withItem( CartLine $line ): Cart {
		$lines = $this->lines;
		$key   = $line->key();

		if ( isset( $lines[ $key ] ) ) {
			$existing      = $lines[ $key ];
			$lines[ $key ] = $existing->withQuantity( $existing->quantity() + $line->quantity() );
		} else {
			$lines[ $key ] = $line;
		}

		return new self( array_values( $lines ), $this->discountCode );
	}

	/**
	 * Set a line quantity. Zero removes it. Missing lines are unchanged.
	 *
	 * @since 1.0.0
	 *
	 * @param int $productId The product id of the line to set.
	 * @param int $priceId   The price id of the line to set.
	 * @param int $quantity  The exact quantity (<= 0 removes the line).
	 * @return Cart A new cart with the change applied (or this cart's data unchanged).
	 */
	public function withQuantity( int $productId, int $priceId, int $quantity ): Cart {
		$key = self::keyFor( $productId, $priceId );

		if ( ! isset( $this->lines[ $key ] ) ) {
			return new self( array_values( $this->lines ), $this->discountCode );
		}

		$lines = $this->lines;

		if ( $quantity <= 0 ) {
			unset( $lines[ $key ] );
		} else {
			$lines[ $key ] = $lines[ $key ]->withQuantity( $quantity );
		}

		return new self( array_values( $lines ), $this->discountCode );
	}

	/**
	 * Return a cart without the named line. Missing lines leave it unchanged.
	 *
	 * @since 1.0.0
	 *
	 * @param int $productId The product id of the line to remove.
	 * @param int $priceId   The price id of the line to remove.
	 * @return Cart A new cart without the line.
	 */
	public function without( int $productId, int $priceId ): Cart {
		$lines = $this->lines;
		unset( $lines[ self::keyFor( $productId, $priceId ) ] );

		return new self( array_values( $lines ), $this->discountCode );
	}

	/**
	 * Return an empty cart without a discount code.
	 *
	 * @since 1.0.0
	 *
	 * @return Cart The cleared cart.
	 */
	public function cleared(): Cart {
		return new self();
	}

	/**
	 * The applied discount code, or null when none is applied.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null The code.
	 */
	public function discountCode(): ?string {
		return $this->discountCode;
	}

	/**
	 * Return a cart with a discount code. The constructor clears blank codes.
	 *
	 * @since 1.0.0
	 *
	 * @param string $code The code to apply.
	 * @return Cart A new cart carrying the code.
	 */
	public function withDiscountCode( string $code ): Cart {
		return new self( array_values( $this->lines ), $code );
	}

	/**
	 * A copy of this cart with any applied discount code removed, keeping the lines.
	 *
	 * @since 1.0.0
	 *
	 * @return Cart A new cart with no code.
	 */
	public function withoutDiscountCode(): Cart {
		return new self( array_values( $this->lines ), null );
	}

	/**
	 * Whether a line for the given product and price is present.
	 *
	 * @since 1.0.0
	 *
	 * @param int $productId The product id to look for.
	 * @param int $priceId   The price id to look for.
	 * @return bool True when such a line exists.
	 */
	public function hasLine( int $productId, int $priceId ): bool {
		return isset( $this->lines[ self::keyFor( $productId, $priceId ) ] );
	}

	/**
	 * Return a line quantity, or 0 when the line is absent.
	 *
	 * @since 1.0.0
	 *
	 * @param int $productId The product id to look for.
	 * @param int $priceId   The price id to look for.
	 * @return int The line quantity, or 0 when absent.
	 */
	public function quantityFor( int $productId, int $priceId ): int {
		$key = self::keyFor( $productId, $priceId );

		return isset( $this->lines[ $key ] ) ? $this->lines[ $key ]->quantity() : 0;
	}

	/**
	 * Whether the cart holds no lines.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when empty.
	 */
	public function isEmpty(): bool {
		return array() === $this->lines;
	}

	/**
	 * Return the total units across all lines.
	 *
	 * @since 1.0.0
	 *
	 * @return int The item count.
	 */
	public function itemCount(): int {
		$count = 0;

		foreach ( $this->lines as $line ) {
			$count += $line->quantity();
		}

		return $count;
	}

	/**
	 * The number of distinct lines in the cart.
	 *
	 * @since 1.0.0
	 *
	 * @return int The line count.
	 */
	public function lineCount(): int {
		return count( $this->lines );
	}

	/**
	 * The cart's lines as a zero-indexed list.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, CartLine> The lines.
	 */
	public function lines(): array {
		return array_values( $this->lines );
	}

	/**
	 * Return the subtotal from cart price snapshots. Checkout prices again.
	 *
	 * @since 1.0.0
	 *
	 * @return int The subtotal.
	 */
	public function subtotal(): int {
		$total = 0;

		foreach ( $this->lines as $line ) {
			$total += $line->subtotal();
		}

		return $total;
	}

	/**
	 * Return the transient payload. fromArray() also accepts legacy line lists.
	 *
	 * @since 1.0.0
	 *
	 * @return array{lines: array<int, array<string, mixed>>, discount_code: string|null} The serialized cart.
	 */
	public function toArray(): array {
		return array(
			'lines'         => array_map(
				static fn ( CartLine $line ): array => $line->toArray(),
				array_values( $this->lines )
			),
			'discount_code' => $this->discountCode,
		);
	}

	/**
	 * Rebuild a cart from the current payload or a legacy line list.
	 *
	 * Invalid rows are ignored. Legacy carts have no discount code.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int|string, mixed> $data The serialized cart (wrapped map or legacy list).
	 * @return self
	 *
	 * @throws OrderException When an array row describes an invalid line.
	 */
	public static function fromArray( array $data ): self {
		$wrapped = isset( $data['lines'] ) && is_array( $data['lines'] );
		$rows    = $wrapped ? $data['lines'] : $data;
		$code    = $wrapped && isset( $data['discount_code'] ) && is_string( $data['discount_code'] )
			? $data['discount_code']
			: null;

		$lines = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$lines[] = CartLine::fromArray( $row );
		}

		return new self( $lines, $code );
	}

	/**
	 * The identity key for a product and price pair, matching {@see CartLine::key()}.
	 *
	 * @since 1.0.0
	 *
	 * @param int $productId The product id.
	 * @param int $priceId   The price id.
	 * @return string The `{productId}:{priceId}` key.
	 */
	private static function keyFor( int $productId, int $priceId ): string {
		return $productId . ':' . $priceId;
	}
}
