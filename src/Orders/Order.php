<?php
/**
 * Order aggregate value object.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Orders;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Immutable order aggregate with line items.
 *
 * Money is in minor units. Refund orders may have negative values.
 *
 * @since 1.0.0
 */
final class Order {

	/**
	 * Primary key, or null for an order that has not been persisted yet.
	 *
	 * @var int|null
	 */
	private ?int $id;

	/**
	 * Public order UUID.
	 *
	 * @var string
	 */
	private string $uuid;

	/**
	 * Lifecycle status.
	 *
	 * @var OrderStatus
	 */
	private OrderStatus $status;

	/**
	 * Customer id, or null for a guest.
	 *
	 * @var int|null
	 */
	private ?int $customerId;

	/**
	 * Valid buyer email.
	 *
	 * @var string
	 */
	private string $email;

	/**
	 * Uppercase ISO 4217 currency code.
	 *
	 * @var string
	 */
	private string $currency;

	/**
	 * Order subtotal before discount and tax, in minor units.
	 *
	 * @var int
	 */
	private int $subtotal;

	/**
	 * Total discount applied to the order, in minor units.
	 *
	 * @var int
	 */
	private int $discountTotal;

	/**
	 * Tax total in minor units.
	 *
	 * @var int
	 */
	private int $taxTotal;

	/**
	 * Order total after discount and tax, in minor units (negative for refunds).
	 *
	 * @var int
	 */
	private int $total;

	/**
	 * Payment gateway, or null.
	 *
	 * @var string|null
	 */
	private ?string $gateway;

	/**
	 * Gateway transaction id, or null.
	 *
	 * @var string|null
	 */
	private ?string $gatewayTxnId;

	/**
	 * Applied discount id, or null when no discount was used.
	 *
	 * @var int|null
	 */
	private ?int $discountId;

	/**
	 * Applied discount code snapshot, or null.
	 *
	 * @var string|null
	 */
	private ?string $discountCode;

	/**
	 * Order type (purchase, renewal, upgrade, refund).
	 *
	 * @var OrderType
	 */
	private OrderType $type;

	/**
	 * Parent order id, or null.
	 *
	 * @var int|null
	 */
	private ?int $parentId;

	/**
	 * Buyer IP address, or null.
	 *
	 * @var string|null
	 */
	private ?string $ipAddress;

	/**
	 * Billing country code, or null.
	 *
	 * @var string|null
	 */
	private ?string $country;

	/**
	 * UTC creation timestamp (Y-m-d H:i:s), or null before first persist.
	 *
	 * @var string|null
	 */
	private ?string $createdAt;

	/**
	 * UTC completion timestamp (Y-m-d H:i:s), or null until the order completes.
	 *
	 * @var string|null
	 */
	private ?string $completedAt;

	/**
	 * UTC last-update timestamp (Y-m-d H:i:s), or null before first persist.
	 *
	 * @var string|null
	 */
	private ?string $updatedAt;

	/**
	 * The order's line items.
	 *
	 * @var array<int, OrderItem>
	 */
	private array $items;

	/**
	 * Create and validate an order.
	 *
	 * @since 1.0.0
	 *
	 * @param int|null              $id            Database id.
	 * @param string                $uuid          Public UUID.
	 * @param OrderStatus           $status        Order status.
	 * @param int|null              $customerId    Customer id.
	 * @param string                $email         Buyer email.
	 * @param string                $currency      Currency code.
	 * @param int                   $subtotal      Minor-unit subtotal.
	 * @param int                   $discountTotal Minor-unit discount.
	 * @param int                   $taxTotal      Minor-unit tax.
	 * @param int                   $total         Minor-unit total.
	 * @param string|null           $gateway       Gateway id.
	 * @param string|null           $gatewayTxnId  Gateway transaction id.
	 * @param int|null              $discountId    Discount id.
	 * @param string|null           $discountCode  Discount code.
	 * @param OrderType             $type          Order type.
	 * @param int|null              $parentId      Parent order id.
	 * @param string|null           $ipAddress     Buyer IP address.
	 * @param string|null           $country       Billing country.
	 * @param string|null           $createdAt     UTC creation time.
	 * @param string|null           $completedAt   UTC completion time.
	 * @param string|null           $updatedAt     UTC update time.
	 * @param array<int, OrderItem> $items         Line items.
	 *
	 * @throws OrderException When the email is invalid or the currency is malformed.
	 */
	public function __construct(
		?int $id,
		string $uuid,
		OrderStatus $status,
		?int $customerId,
		string $email,
		string $currency,
		int $subtotal,
		int $discountTotal,
		int $taxTotal,
		int $total,
		?string $gateway,
		?string $gatewayTxnId,
		?int $discountId,
		?string $discountCode,
		OrderType $type,
		?int $parentId,
		?string $ipAddress,
		?string $country,
		?string $createdAt,
		?string $completedAt,
		?string $updatedAt,
		array $items
	) {
		if ( false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured validation message, surfaced as an API error, never echoed as HTML.
			throw new OrderException( __( 'An order must have a valid buyer email.', 'tiermint' ) );
		}

		// Store one uppercase three-letter currency value.
		if ( 1 !== preg_match( '/^[A-Za-z]{3}$/', $currency ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured validation message, surfaced as an API error, never echoed as HTML.
			throw new OrderException( __( 'An order currency must be a three-letter code.', 'tiermint' ) );
		}

		// Refund orders can have negative totals.

		$this->id            = $id;
		$this->uuid          = $uuid;
		$this->status        = $status;
		$this->customerId    = $customerId;
		$this->email         = $email;
		$this->currency      = strtoupper( $currency );
		$this->subtotal      = $subtotal;
		$this->discountTotal = $discountTotal;
		$this->taxTotal      = $taxTotal;
		$this->total         = $total;
		$this->gateway       = $gateway;
		$this->gatewayTxnId  = $gatewayTxnId;
		$this->discountId    = $discountId;
		$this->discountCode  = $discountCode;
		$this->type          = $type;
		$this->parentId      = $parentId;
		$this->ipAddress     = $ipAddress;
		$this->country       = $country;
		$this->createdAt     = $createdAt;
		$this->completedAt   = $completedAt;
		$this->updatedAt     = $updatedAt;
		$this->items         = array_values( $items );
	}

	/**
	 * Build an order from scalar data and line items.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed>  $data  Scalar fields.
	 * @param array<int, OrderItem> $items Line items.
	 * @return self
	 *
	 * @throws OrderException When the resulting order is invalid.
	 */
	public static function fromArray( array $data, array $items = array() ): self {
		$status = OrderStatus::tryFrom( (string) ( $data['status'] ?? OrderStatus::Pending->value ) ) ?? OrderStatus::Pending;
		$type   = OrderType::tryFrom( (string) ( $data['type'] ?? OrderType::Purchase->value ) ) ?? OrderType::Purchase;

		return new self(
			self::nullableInt( $data['id'] ?? null ),
			(string) ( $data['uuid'] ?? '' ),
			$status,
			self::nullableInt( $data['customer_id'] ?? null ),
			(string) ( $data['email'] ?? '' ),
			(string) ( $data['currency'] ?? '' ),
			(int) ( $data['subtotal'] ?? 0 ),
			(int) ( $data['discount_total'] ?? 0 ),
			(int) ( $data['tax_total'] ?? 0 ),
			(int) ( $data['total'] ?? 0 ),
			self::nullableString( $data['gateway'] ?? null ),
			self::nullableString( $data['gateway_txn_id'] ?? null ),
			self::nullableInt( $data['discount_id'] ?? null ),
			self::nullableString( $data['discount_code'] ?? null ),
			$type,
			self::nullableInt( $data['parent_id'] ?? null ),
			self::nullableString( $data['ip_address'] ?? null ),
			self::nullableString( $data['country'] ?? null ),
			self::nullableString( $data['created_at'] ?? null ),
			self::nullableString( $data['completed_at'] ?? null ),
			self::nullableString( $data['updated_at'] ?? null ),
			$items
		);
	}

	/**
	 * Build an order from a database row.
	 *
	 * @since 1.0.0
	 *
	 * @param object                $row   Order row.
	 * @param array<int, OrderItem> $items Line items.
	 * @return self
	 *
	 * @throws OrderException When the row violates an order invariant.
	 */
	public static function fromRow( object $row, array $items = array() ): self {
		return self::fromArray( (array) $row, $items );
	}

	/**
	 * Get persistable order fields.
	 *
	 * Keep an imported creation time when present.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed> Column values.
	 */
	public function toRow(): array {
		$row = array(
			'uuid'           => $this->uuid,
			'status'         => $this->status->value,
			'customer_id'    => $this->customerId,
			'email'          => $this->email,
			'currency'       => $this->currency,
			'subtotal'       => $this->subtotal,
			'discount_total' => $this->discountTotal,
			'tax_total'      => $this->taxTotal,
			'total'          => $this->total,
			'gateway'        => $this->gateway,
			'gateway_txn_id' => $this->gatewayTxnId,
			'discount_id'    => $this->discountId,
			'discount_code'  => $this->discountCode,
			'type'           => $this->type->value,
			'parent_id'      => $this->parentId,
			'ip_address'     => $this->ipAddress,
			'country'        => $this->country,
			'completed_at'   => $this->completedAt,
		);

		if ( null !== $this->createdAt ) {
			$row['created_at'] = $this->createdAt;
		}

		return $row;
	}

	/**
	 * Whether the order has completed (payment confirmed).
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function isCompleted(): bool {
		return OrderStatus::Completed === $this->status;
	}

	/**
	 * Check whether a refund can be applied.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function isRefundable(): bool {
		return OrderStatus::Completed === $this->status
			|| OrderStatus::PartiallyRefunded === $this->status;
	}

	/**
	 * Copy the order with a new status.
	 *
	 * @since 1.0.0
	 *
	 * @param OrderStatus $status New status.
	 * @return Order Updated copy.
	 */
	public function withStatus( OrderStatus $status ): Order {
		return new self(
			$this->id,
			$this->uuid,
			$status,
			$this->customerId,
			$this->email,
			$this->currency,
			$this->subtotal,
			$this->discountTotal,
			$this->taxTotal,
			$this->total,
			$this->gateway,
			$this->gatewayTxnId,
			$this->discountId,
			$this->discountCode,
			$this->type,
			$this->parentId,
			$this->ipAddress,
			$this->country,
			$this->createdAt,
			$this->completedAt,
			$this->updatedAt,
			$this->items
		);
	}

	/**
	 * Copy the order with a new completion time.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $completedAt Completion time.
	 * @return Order Updated copy.
	 */
	public function withCompletedAt( ?string $completedAt ): Order {
		return new self(
			$this->id,
			$this->uuid,
			$this->status,
			$this->customerId,
			$this->email,
			$this->currency,
			$this->subtotal,
			$this->discountTotal,
			$this->taxTotal,
			$this->total,
			$this->gateway,
			$this->gatewayTxnId,
			$this->discountId,
			$this->discountCode,
			$this->type,
			$this->parentId,
			$this->ipAddress,
			$this->country,
			$this->createdAt,
			$completedAt,
			$this->updatedAt,
			$this->items
		);
	}

	/**
	 * Copy the order with gateway details.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $gateway      Gateway id.
	 * @param string|null $gatewayTxnId Gateway transaction id.
	 * @return Order Updated copy.
	 */
	public function withGateway( string $gateway, ?string $gatewayTxnId ): Order {
		return new self(
			$this->id,
			$this->uuid,
			$this->status,
			$this->customerId,
			$this->email,
			$this->currency,
			$this->subtotal,
			$this->discountTotal,
			$this->taxTotal,
			$this->total,
			$gateway,
			$gatewayTxnId,
			$this->discountId,
			$this->discountCode,
			$this->type,
			$this->parentId,
			$this->ipAddress,
			$this->country,
			$this->createdAt,
			$this->completedAt,
			$this->updatedAt,
			$this->items
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
	 * The public order identifier (UUID).
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function uuid(): string {
		return $this->uuid;
	}

	/**
	 * The lifecycle status.
	 *
	 * @since 1.0.0
	 *
	 * @return OrderStatus
	 */
	public function status(): OrderStatus {
		return $this->status;
	}

	/**
	 * The linked customer id, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return int|null
	 */
	public function customerId(): ?int {
		return $this->customerId;
	}

	/**
	 * The buyer email.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function email(): string {
		return $this->email;
	}

	/**
	 * The uppercased currency code.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function currency(): string {
		return $this->currency;
	}

	/**
	 * The subtotal in minor units.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function subtotal(): int {
		return $this->subtotal;
	}

	/**
	 * The discount total in minor units.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function discountTotal(): int {
		return $this->discountTotal;
	}

	/**
	 * The tax total in minor units.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function taxTotal(): int {
		return $this->taxTotal;
	}

	/**
	 * The order total in minor units (negative for refunds).
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function total(): int {
		return $this->total;
	}

	/**
	 * The gateway slug, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public function gateway(): ?string {
		return $this->gateway;
	}

	/**
	 * The gateway transaction id, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public function gatewayTxnId(): ?string {
		return $this->gatewayTxnId;
	}

	/**
	 * The applied discount id, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return int|null
	 */
	public function discountId(): ?int {
		return $this->discountId;
	}

	/**
	 * The applied discount code snapshot, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public function discountCode(): ?string {
		return $this->discountCode;
	}

	/**
	 * The order type.
	 *
	 * @since 1.0.0
	 *
	 * @return OrderType
	 */
	public function type(): OrderType {
		return $this->type;
	}

	/**
	 * The parent order id, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return int|null
	 */
	public function parentId(): ?int {
		return $this->parentId;
	}

	/**
	 * The buyer IP address, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public function ipAddress(): ?string {
		return $this->ipAddress;
	}

	/**
	 * The billing country code, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public function country(): ?string {
		return $this->country;
	}

	/**
	 * The UTC creation timestamp, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public function createdAt(): ?string {
		return $this->createdAt;
	}

	/**
	 * The UTC completion timestamp, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public function completedAt(): ?string {
		return $this->completedAt;
	}

	/**
	 * The UTC last-update timestamp, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public function updatedAt(): ?string {
		return $this->updatedAt;
	}

	/**
	 * The line items.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, OrderItem>
	 */
	public function items(): array {
		return $this->items;
	}

	/**
	 * Cast a nullable value to int.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Raw value.
	 * @return int|null Integer value, or null.
	 */
	private static function nullableInt( $value ): ?int {
		return null === $value ? null : (int) $value;
	}

	/**
	 * Cast a nullable value to a non-empty string.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Raw value.
	 * @return string|null String value, or null.
	 */
	private static function nullableString( $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		$string = (string) $value;

		return '' === $string ? null : $string;
	}
}
