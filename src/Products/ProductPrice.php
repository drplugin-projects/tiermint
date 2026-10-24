<?php
/**
 * Product price tier value object.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Products;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Immutable product price tier.
 *
 * Amounts use minor units.
 *
 * @since 1.0.0
 */
final class ProductPrice {

	/**
	 * Primary key, or null for a tier that has not been persisted yet.
	 *
	 * @var int|null
	 */
	private ?int $id;

	/**
	 * Owning product id, or null before the tier is attached to a product.
	 *
	 * @var int|null
	 */
	private ?int $productId;

	/**
	 * Human-readable tier label, e.g. "Professional: 5 sites".
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * Price in unsigned minor units of the store currency.
	 *
	 * @var int
	 */
	private int $amount;

	/**
	 * Maximum activations, or null for unlimited.
	 *
	 * @var int|null
	 */
	private ?int $activationLimit;

	/**
	 * `strtotime`-compatible relative length (e.g. '+1 year'), or null for lifetime.
	 *
	 * @var string|null
	 */
	private ?string $licenseLength;

	/**
	 * Display sort order among a product's tiers.
	 *
	 * @var int
	 */
	private int $sortOrder;

	/**
	 * The tier's status.
	 *
	 * @var PriceStatus
	 */
	private PriceStatus $status;

	/**
	 * Minimum units per purchase.
	 *
	 * @var int
	 */
	private int $minQuantity;

	/**
	 * Maximum units per purchase, or null for unlimited.
	 *
	 * @var int|null
	 */
	private ?int $maxQuantity;

	/**
	 * Construct and validate a price tier.
	 *
	 * @since 1.0.0
	 *
	 * @param int|null    $id              Primary key, or null when unsaved.
	 * @param int|null    $productId       Owning product id, or null when detached.
	 * @param string      $name            Non-empty tier label.
	 * @param int         $amount          Unsigned minor units (must be >= 0).
	 * @param int|null    $activationLimit Activation cap, or null for unlimited.
	 * @param string|null $licenseLength   Relative length string, or null for lifetime.
	 * @param int         $sortOrder       Display sort order.
	 * @param PriceStatus $status          Tier status.
	 * @param int         $minQuantity     Minimum units per purchase (>= 1).
	 * @param int|null    $maxQuantity     Maximum units per purchase, or null for unlimited.
	 *
	 * @throws ProductException When the name is empty, the amount is negative, or the
	 *                          quantity rule is invalid.
	 */
	public function __construct(
		?int $id,
		?int $productId,
		string $name,
		int $amount,
		?int $activationLimit,
		?string $licenseLength,
		int $sortOrder,
		PriceStatus $status,
		int $minQuantity,
		?int $maxQuantity
	) {
		if ( '' === trim( $name ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new ProductException( __( 'A price tier must have a non-empty name.', 'tiermint' ) );
		}

		if ( $amount < 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new ProductException( __( 'A price amount cannot be negative.', 'tiermint' ) );
		}

		// Reject values that unsigned columns would silently change to zero.
		if ( null !== $activationLimit && $activationLimit < 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new ProductException( __( 'An activation limit cannot be negative.', 'tiermint' ) );
		}

		if ( $sortOrder < 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new ProductException( __( 'A sort order cannot be negative.', 'tiermint' ) );
		}

		if ( $minQuantity < 1 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new ProductException( __( 'A minimum quantity must be at least 1.', 'tiermint' ) );
		}

		if ( null !== $maxQuantity && $maxQuantity < $minQuantity ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new ProductException( __( 'A maximum quantity cannot be less than the minimum quantity.', 'tiermint' ) );
		}

		$this->id              = $id;
		$this->productId       = $productId;
		$this->name            = $name;
		$this->amount          = $amount;
		$this->activationLimit = $activationLimit;
		$this->licenseLength   = $licenseLength;
		$this->sortOrder       = $sortOrder;
		$this->status          = $status;
		$this->minQuantity     = $minQuantity;
		$this->maxQuantity     = $maxQuantity;
	}

	/**
	 * Build a tier from REST, admin or service input.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $data Input keyed by column name.
	 * @return self
	 *
	 * @throws ProductException When the resulting tier is invalid.
	 */
	public static function fromArray( array $data ): self {
		$statusValue = isset( $data['status'] ) ? (string) $data['status'] : PriceStatus::Active->value;
		$status      = PriceStatus::tryFrom( $statusValue ) ?? PriceStatus::Active;

		return new self(
			isset( $data['id'] ) ? (int) $data['id'] : null,
			isset( $data['product_id'] ) ? (int) $data['product_id'] : null,
			(string) ( $data['name'] ?? '' ),
			(int) ( $data['amount'] ?? 0 ),
			self::nullableInt( $data['activation_limit'] ?? null ),
			self::nullableString( $data['license_length'] ?? null ),
			(int) ( $data['sort_order'] ?? 0 ),
			$status,
			(int) ( $data['min_quantity'] ?? 1 ),
			self::nullableInt( $data['max_quantity'] ?? null )
		);
	}

	/**
	 * Build a tier from a database row (all scalars are stringy from `$wpdb`).
	 *
	 * @since 1.0.0
	 *
	 * @param object $row A `tiermint_product_prices` row.
	 * @return self
	 *
	 * @throws ProductException When the row violates a tier invariant.
	 */
	public static function fromRow( object $row ): self {
		return self::fromArray( (array) $row );
	}

	/**
	 * Map persistable fields to storage scalars.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed> Column name => value.
	 */
	public function toRow(): array {
		return array(
			'name'             => $this->name,
			'amount'           => $this->amount,
			'activation_limit' => $this->activationLimit,
			'license_length'   => $this->licenseLength,
			'sort_order'       => $this->sortOrder,
			'min_quantity'     => $this->minQuantity,
			'max_quantity'     => $this->maxQuantity,
			'status'           => $this->status->value,
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
	 * The owning product id, or null when detached.
	 *
	 * @since 1.0.0
	 *
	 * @return int|null
	 */
	public function productId(): ?int {
		return $this->productId;
	}

	/**
	 * The tier label.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * The amount in minor units.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function amount(): int {
		return $this->amount;
	}

	/**
	 * The activation cap, or null for unlimited.
	 *
	 * @since 1.0.0
	 *
	 * @return int|null
	 */
	public function activationLimit(): ?int {
		return $this->activationLimit;
	}

	/**
	 * The relative license length, or null for lifetime.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public function licenseLength(): ?string {
		return $this->licenseLength;
	}

	/**
	 * Validate a license length before a create or update.
	 *
	 * Legacy rows can still hydrate with a historic value.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $length The candidate license length.
	 * @return string|null The validation message, or null when the value is valid.
	 */
	public static function licenseLengthError( ?string $length ): ?string {
		if ( null === $length ) {
			return null;
		}

		// A plain number is not a meaningful date interval.
		if ( 1 === preg_match( '/^-?\d+$/', $length ) ) {
			return __( 'A license length needs a unit, for example "+1 year". A plain number is not accepted; leave it blank for a lifetime license.', 'tiermint' );
		}

		// Valid when PHP's date parser reads it without errors. date_parse() never warns.
		if ( 0 < date_parse( $length )['error_count'] ) {
			/* translators: %s: the submitted license length value. */
			return sprintf( __( '"%s" is not a valid license length. Use a value like "+1 year", or leave it blank for a lifetime license.', 'tiermint' ), $length );
		}

		return null;
	}

	/**
	 * The display sort order.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function sortOrder(): int {
		return $this->sortOrder;
	}

	/**
	 * The tier status.
	 *
	 * @since 1.0.0
	 *
	 * @return PriceStatus
	 */
	public function status(): PriceStatus {
		return $this->status;
	}

	/**
	 * The minimum units per purchase (always at least 1).
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function minQuantity(): int {
		return $this->minQuantity;
	}

	/**
	 * The maximum units per purchase, or null for unlimited.
	 *
	 * @since 1.0.0
	 *
	 * @return int|null
	 */
	public function maxQuantity(): ?int {
		return $this->maxQuantity;
	}

	/**
	 * Clamp a requested quantity to this tier's limits.
	 *
	 * @since 1.0.0
	 *
	 * @param int $quantity The requested quantity.
	 * @return int The clamped quantity (always at least the minimum).
	 */
	public function clampQuantity( int $quantity ): int {
		$capped = null === $this->maxQuantity ? $quantity : min( $quantity, $this->maxQuantity );

		return max( $this->minQuantity, $capped );
	}

	/**
	 * Whether the tier allows exactly one unit.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function isFixedSingle(): bool {
		return 1 === $this->minQuantity && 1 === $this->maxQuantity;
	}

	/**
	 * Whether this tier is active (offered for purchase and default-eligible).
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function isActive(): bool {
		return PriceStatus::Active === $this->status;
	}

	/**
	 * Whether the tier can be sold on this site: it is active.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function isPurchasable(): bool {
		return $this->isActive();
	}

	/**
	 * Cast a value to int, preserving null (used for nullable numeric columns).
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
	 * Cast a value to a non-empty string, preserving null (nullable text columns).
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
