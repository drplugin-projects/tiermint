<?php
/**
 * Discount value object.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Discounts;

// Block direct access.
defined( 'ABSPATH' ) || exit;

/**
 * An immutable discount record for a percentage or fixed reduction.
 *
 * It validates local fields only. The unused amount field is always null.
 *
 * @since 1.0.0
 */
final class Discount {

	/**
	 * Primary key, if saved.
	 *
	 * @var int|null
	 */
	private ?int $id;

	/**
	 * Trimmed coupon code.
	 *
	 * @var string
	 */
	private string $code;

	/**
	 * Optional admin label.
	 *
	 * @var string|null
	 */
	private ?string $name;

	/**
	 * Reduction type.
	 *
	 * @var DiscountType
	 */
	private DiscountType $type;

	/**
	 * Percentage reduction, if applicable.
	 *
	 * @var float|null
	 */
	private ?float $amountPercent;

	/**
	 * Fixed reduction in minor units, if applicable.
	 *
	 * @var int|null
	 */
	private ?int $amountFixed;

	/**
	 * Product ids, or null for all products.
	 *
	 * @var array<int, int>|null
	 */
	private ?array $productIds;

	/**
	 * Required subtotal in minor units, if any.
	 *
	 * @var int|null
	 */
	private ?int $minTotal;

	/**
	 * Total use limit, if any.
	 *
	 * @var int|null
	 */
	private ?int $maxUses;

	/**
	 * Recorded use count.
	 *
	 * @var int
	 */
	private int $useCount;

	/**
	 * Limits customers to one redemption.
	 *
	 * @var bool
	 */
	private bool $oncePerCustomer;

	/**
	 * UTC start time, if any.
	 *
	 * @var string|null
	 */
	private ?string $startsAt;

	/**
	 * UTC end time, if any.
	 *
	 * @var string|null
	 */
	private ?string $expiresAt;

	/**
	 * Current status.
	 *
	 * @var DiscountStatus
	 */
	private DiscountStatus $status;

	/**
	 * UTC creation time, if saved.
	 *
	 * @var string|null
	 */
	private ?string $createdAt;

	/**
	 * UTC update time, if saved.
	 *
	 * @var string|null
	 */
	private ?string $updatedAt;

	/**
	 * Applies without a code.
	 *
	 * @var bool
	 */
	private bool $auto;

	/**
	 * Product scope. Code discounts derive this from product ids.
	 *
	 * @var DiscountScope
	 */
	private DiscountScope $scopeType;

	/**
	 * Category ids for automatic category scopes.
	 *
	 * @var array<int, int>|null
	 */
	private ?array $categoryIds;

	/**
	 * Creates and validates a discount.
	 *
	 * @since 1.0.0
	 *
	 * @param int|null             $id              Primary key, if saved.
	 * @param string               $code            Coupon code.
	 * @param string|null          $name            Optional label.
	 * @param DiscountType         $type            Reduction type.
	 * @param float|null           $amountPercent   Percentage reduction.
	 * @param int|null             $amountFixed     Fixed reduction in minor units.
	 * @param array<int, int>|null $productIds      Product ids, if scoped.
	 * @param int|null             $minTotal        Required subtotal in minor units.
	 * @param int|null             $maxUses         Total use limit.
	 * @param int                  $useCount        Recorded use count.
	 * @param bool                 $oncePerCustomer Limits customers to one redemption.
	 * @param string|null          $startsAt        UTC start time.
	 * @param string|null          $expiresAt       UTC end time.
	 * @param DiscountStatus       $status          Current status.
	 * @param string|null          $createdAt       UTC creation time.
	 * @param string|null          $updatedAt       UTC update time.
	 * @param bool                 $auto            Applies without a code.
	 * @param DiscountScope|null   $scopeType       Scope, if automatic.
	 * @param array<int, int>|null $categoryIds     Category ids, if scoped.
	 *
	 * @throws DiscountValidationException When any field-level invariant is broken.
	 */
	public function __construct(
		?int $id,
		string $code,
		?string $name,
		DiscountType $type,
		?float $amountPercent,
		?int $amountFixed,
		?array $productIds,
		?int $minTotal,
		?int $maxUses,
		int $useCount,
		bool $oncePerCustomer,
		?string $startsAt,
		?string $expiresAt,
		DiscountStatus $status,
		?string $createdAt,
		?string $updatedAt,
		bool $auto = false,
		?DiscountScope $scopeType = null,
		?array $categoryIds = null
	) {
		$code   = trim( $code );
		$errors = array();

		if ( '' === $code ) {
			$errors[] = __( 'A discount code is required.', 'tiermint' );
		} elseif ( mb_strlen( $code ) > 50 ) {
			$errors[] = __( 'A discount code may be at most 50 characters.', 'tiermint' );
		}

		$name = null === $name ? null : trim( $name );
		$name = ( null === $name || '' === $name ) ? null : $name;

		if ( null !== $name && mb_strlen( $name ) > 200 ) {
			$errors[] = __( 'A discount name may be at most 200 characters.', 'tiermint' );
		}

		if ( DiscountType::Percent === $type ) {
			// Keep only the amount for the selected type.
			$amountFixed = null;

			if ( null === $amountPercent || $amountPercent <= 0 || $amountPercent > 100 ) {
				$errors[] = __( 'A percentage discount must be greater than 0 and at most 100.', 'tiermint' );
			} else {
				// Match the database precision.
				$amountPercent = round( $amountPercent, 2 );
			}
		} else {
			$amountPercent = null;

			if ( null === $amountFixed || $amountFixed <= 0 ) {
				$errors[] = __( 'A fixed discount amount must be greater than zero.', 'tiermint' );
			}
		}

		$productIds  = $this->normalizeProductIds( $productIds, $errors );
		$categoryIds = $this->normalizeCategoryIds( $categoryIds, $errors );

		// Automatic discounts keep only ids for their selected scope.
		if ( $auto ) {
			$scopeType = $scopeType ?? self::deriveScope( $productIds, $categoryIds );

			switch ( $scopeType ) {
				case DiscountScope::All:
					$productIds  = null;
					$categoryIds = null;
					break;
				case DiscountScope::Products:
					$categoryIds = null;
					if ( null === $productIds ) {
						$errors[] = __( 'A product-scoped automatic discount needs at least one product.', 'tiermint' );
					}
					break;
				case DiscountScope::Categories:
					$productIds = null;
					if ( null === $categoryIds ) {
						$errors[] = __( 'A category-scoped automatic discount needs at least one category.', 'tiermint' );
					}
					break;
			}
		} else {
			// Code discounts derive scope from product ids.
			$categoryIds = null;
			$scopeType   = null === $productIds ? DiscountScope::All : DiscountScope::Products;
		}

		if ( null !== $minTotal && $minTotal < 0 ) {
			$errors[] = __( 'A minimum order total cannot be negative.', 'tiermint' );
		}

		if ( null !== $maxUses && $maxUses <= 0 ) {
			$errors[] = __( 'A usage limit must be greater than zero.', 'tiermint' );
		}

		if ( $useCount < 0 ) {
			$errors[] = __( 'A use count cannot be negative.', 'tiermint' );
		}

		// Reject dates MySQL would silently normalize.
		foreach ( array( $startsAt, $expiresAt ) as $window ) {
			if ( null !== $window && ! self::isMysqlDatetime( $window ) ) {
				/* translators: %s: the rejected timestamp value. */
				$errors[] = sprintf( __( 'The window timestamp "%s" is not valid; use the Y-m-d H:i:s format in UTC.', 'tiermint' ), $window );
			}
		}

		// The start cannot follow the end.
		if ( null !== $startsAt && null !== $expiresAt && strtotime( $startsAt ) > strtotime( $expiresAt ) ) {
			$errors[] = __( 'A discount cannot expire before it starts.', 'tiermint' );
		}

		if ( array() !== $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw DiscountValidationException::withErrors( $errors );
		}

		$this->id              = $id;
		$this->code            = $code;
		$this->name            = $name;
		$this->type            = $type;
		$this->amountPercent   = $amountPercent;
		$this->amountFixed     = $amountFixed;
		$this->productIds      = $productIds;
		$this->minTotal        = $minTotal;
		$this->maxUses         = $maxUses;
		$this->useCount        = max( 0, $useCount );
		$this->oncePerCustomer = $oncePerCustomer;
		$this->startsAt        = $startsAt;
		$this->expiresAt       = $expiresAt;
		$this->status          = $status;
		$this->createdAt       = $createdAt;
		$this->updatedAt       = $updatedAt;
		$this->auto            = $auto;
		$this->scopeType       = $scopeType;
		$this->categoryIds     = $categoryIds;
	}

	/**
	 * Derives scope from category and product ids.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, int>|null $productIds  Product ids.
	 * @param array<int, int>|null $categoryIds Category ids.
	 * @return DiscountScope Derived scope.
	 */
	private static function deriveScope( ?array $productIds, ?array $categoryIds ): DiscountScope {
		if ( null !== $categoryIds ) {
			return DiscountScope::Categories;
		}

		if ( null !== $productIds ) {
			return DiscountScope::Products;
		}

		return DiscountScope::All;
	}

	/**
	 * Checks an exact MySQL UTC datetime.
	 *
	 * @param string $value Candidate timestamp.
	 * @return bool Whether the value is valid.
	 */
	private static function isMysqlDatetime( string $value ): bool {
		$parsed = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );

		return false !== $parsed && $parsed->format( 'Y-m-d H:i:s' ) === $value;
	}

	/**
	 * Builds a discount from a database row.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $row Discount row.
	 * @return self
	 *
	 * @throws DiscountValidationException When the stored row violates an invariant.
	 */
	public static function fromRow( array $row ): self {
		return new self(
			isset( $row['id'] ) ? (int) $row['id'] : null,
			(string) ( $row['code'] ?? '' ),
			isset( $row['name'] ) && null !== $row['name'] ? (string) $row['name'] : null,
			DiscountType::from( (string) ( $row['type'] ?? DiscountType::Percent->value ) ),
			isset( $row['amount_percent'] ) && null !== $row['amount_percent'] ? (float) $row['amount_percent'] : null,
			isset( $row['amount_fixed'] ) && null !== $row['amount_fixed'] ? (int) $row['amount_fixed'] : null,
			self::decodeProductIds( $row['product_ids'] ?? null ),
			isset( $row['min_total'] ) && null !== $row['min_total'] ? (int) $row['min_total'] : null,
			isset( $row['max_uses'] ) && null !== $row['max_uses'] ? (int) $row['max_uses'] : null,
			(int) ( $row['use_count'] ?? 0 ),
			(bool) ( $row['once_per_customer'] ?? false ),
			isset( $row['starts_at'] ) && null !== $row['starts_at'] ? (string) $row['starts_at'] : null,
			isset( $row['expires_at'] ) && null !== $row['expires_at'] ? (string) $row['expires_at'] : null,
			DiscountStatus::from( (string) ( $row['status'] ?? DiscountStatus::Active->value ) ),
			isset( $row['created_at'] ) && null !== $row['created_at'] ? (string) $row['created_at'] : null,
			isset( $row['updated_at'] ) && null !== $row['updated_at'] ? (string) $row['updated_at'] : null,
			(bool) ( $row['auto'] ?? false ),
			DiscountScope::tryFrom( (string) ( $row['scope_type'] ?? DiscountScope::All->value ) ) ?? DiscountScope::All,
			self::decodeCategoryIds( $row['category_ids'] ?? null )
		);
	}

	/**
	 * Converts the discount to repository columns.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed> Column values.
	 */
	public function toRow(): array {
		return array(
			'code'              => $this->code,
			'name'              => $this->name,
			'type'              => $this->type->value,
			'amount_percent'    => $this->amountPercent,
			'amount_fixed'      => $this->amountFixed,
			'product_ids'       => null === $this->productIds ? null : wp_json_encode( array_values( $this->productIds ) ),
			'min_total'         => $this->minTotal,
			'max_uses'          => $this->maxUses,
			'use_count'         => $this->useCount,
			'once_per_customer' => $this->oncePerCustomer ? 1 : 0,
			'starts_at'         => $this->startsAt,
			'expires_at'        => $this->expiresAt,
			'status'            => $this->status->value,
			'auto'              => $this->auto ? 1 : 0,
			'scope_type'        => $this->scopeType->value,
			'category_ids'      => null === $this->categoryIds ? null : wp_json_encode( array_values( $this->categoryIds ) ),
		);
	}

	/**
	 * Checks the status flag only.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function isActive(): bool {
		return DiscountStatus::Active === $this->status;
	}

	/**
	 * Checks whether a time is within the configured window.
	 *
	 * @since 1.0.0
	 *
	 * @param int|null $now Unix time, or null for now.
	 * @return bool Whether the time is in the window.
	 */
	public function isWithinWindow( ?int $now = null ): bool {
		$now = null === $now ? time() : $now;

		if ( null !== $this->startsAt && $now < strtotime( $this->startsAt . ' UTC' ) ) {
			return false;
		}

		if ( null !== $this->expiresAt && $now > strtotime( $this->expiresAt . ' UTC' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Checks the total use limit.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function hasUsesRemaining(): bool {
		return null === $this->maxUses || $this->useCount < $this->maxUses;
	}

	/**
	 * Checks whether the discount covers a product.
	 *
	 * @since 1.0.0
	 *
	 * @param int $productId Product id.
	 * @return bool Whether the product is covered.
	 */
	public function appliesToProduct( int $productId ): bool {
		return null === $this->productIds || in_array( $productId, $this->productIds, true );
	}

	/**
	 * Whether the discount is unscoped (applies to every product).
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function appliesToAllProducts(): bool {
		return null === $this->productIds;
	}

	/**
	 * Checks whether the discount applies without a code.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function isAuto(): bool {
		return $this->auto;
	}

	/**
	 * Gets the product scope.
	 *
	 * @since 1.0.0
	 *
	 * @return DiscountScope
	 */
	public function scopeType(): DiscountScope {
		return $this->scopeType;
	}

	/**
	 * Gets category ids for an automatic category scope.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, int>|null
	 */
	public function categoryIds(): ?array {
		return $this->categoryIds;
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
	 * The coupon code.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function code(): string {
		return $this->code;
	}

	/**
	 * The label, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public function name(): ?string {
		return $this->name;
	}

	/**
	 * The reduction type.
	 *
	 * @since 1.0.0
	 *
	 * @return DiscountType
	 */
	public function type(): DiscountType {
		return $this->type;
	}

	/**
	 * The percentage off in (0, 100], or null for a fixed discount.
	 *
	 * @since 1.0.0
	 *
	 * @return float|null
	 */
	public function amountPercent(): ?float {
		return $this->amountPercent;
	}

	/**
	 * The flat reduction in minor units, or null for a percent discount.
	 *
	 * @since 1.0.0
	 *
	 * @return int|null
	 */
	public function amountFixed(): ?int {
		return $this->amountFixed;
	}

	/**
	 * The scoped product ids, or null for all products.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, int>|null
	 */
	public function productIds(): ?array {
		return $this->productIds;
	}

	/**
	 * The minimum order subtotal in minor units, or null for no minimum.
	 *
	 * @since 1.0.0
	 *
	 * @return int|null
	 */
	public function minTotal(): ?int {
		return $this->minTotal;
	}

	/**
	 * The total usage cap, or null for unlimited.
	 *
	 * @since 1.0.0
	 *
	 * @return int|null
	 */
	public function maxUses(): ?int {
		return $this->maxUses;
	}

	/**
	 * The current use count.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function useCount(): int {
		return $this->useCount;
	}

	/**
	 * Whether a customer may redeem the discount only once.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function isOncePerCustomer(): bool {
		return $this->oncePerCustomer;
	}

	/**
	 * The UTC start timestamp, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public function startsAt(): ?string {
		return $this->startsAt;
	}

	/**
	 * The UTC expiry timestamp, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public function expiresAt(): ?string {
		return $this->expiresAt;
	}

	/**
	 * The on/off status.
	 *
	 * @since 1.0.0
	 *
	 * @return DiscountStatus
	 */
	public function status(): DiscountStatus {
		return $this->status;
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
	 * Validates and normalizes product ids.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, int>|null $productIds Product ids.
	 * @param array<int, string>   $errors     Validation errors.
	 * @return array<int, int>|null Product ids, or null for all products.
	 */
	private function normalizeProductIds( ?array $productIds, array &$errors ): ?array {
		if ( null === $productIds || array() === $productIds ) {
			return null;
		}

		$clean = array();

		foreach ( $productIds as $productId ) {
			$id = (int) $productId;

			if ( $id <= 0 ) {
				$errors[] = __( 'Every scoped product id must be a positive integer.', 'tiermint' );

				continue;
			}

			$clean[ $id ] = $id;
		}

		return array() === $clean ? null : array_values( $clean );
	}

	/**
	 * Validates and normalizes category ids.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, int>|null $categoryIds Category ids.
	 * @param array<int, string>   $errors      Validation errors.
	 * @return array<int, int>|null Category ids, if any.
	 */
	private function normalizeCategoryIds( ?array $categoryIds, array &$errors ): ?array {
		if ( null === $categoryIds || array() === $categoryIds ) {
			return null;
		}

		$clean = array();

		foreach ( $categoryIds as $categoryId ) {
			$id = (int) $categoryId;

			if ( $id <= 0 ) {
				$errors[] = __( 'Every scoped category id must be a positive integer.', 'tiermint' );

				continue;
			}

			$clean[ $id ] = $id;
		}

		return array() === $clean ? null : array_values( $clean );
	}

	/**
	 * Decodes stored product ids.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Raw column value.
	 * @return array<int, int>|null Product ids, or null for all products.
	 */
	private static function decodeProductIds( $value ): ?array {
		if ( null === $value || '' === $value ) {
			return null;
		}

		if ( is_array( $value ) ) {
			$decoded = $value;
		} else {
			$decoded = json_decode( (string) $value, true );
		}

		if ( ! is_array( $decoded ) || array() === $decoded ) {
			return null;
		}

		$ids = array();

		foreach ( $decoded as $id ) {
			$id = (int) $id;

			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}

		return array() === $ids ? null : array_values( $ids );
	}

	/**
	 * Decodes stored category ids.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Raw column value.
	 * @return array<int, int>|null Category ids, if any.
	 */
	private static function decodeCategoryIds( $value ): ?array {
		return self::decodeProductIds( $value );
	}
}
