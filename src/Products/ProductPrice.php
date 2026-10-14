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

use DateTimeImmutable;
use Throwable;

/**
 * Immutable product price tier.
 *
 * Amounts use minor units. Pro handles recurring fields.
 *
 * @since 1.0.0
 */
final class ProductPrice {

	/**
	 * License length sentinel for one billing period.
	 *
	 * Date parsers reject it, so it cannot be a valid length.
	 *
	 * @var string
	 */
	public const LICENSE_LENGTH_BILLING = 'billing_period';

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
	 * Whether the tier bills on a recurring schedule (core stores it; Pro acts).
	 *
	 * @var bool
	 */
	private bool $isSubscription;

	/**
	 * Billing period (day, week, month, year), or null for a one-time tier.
	 *
	 * @var string|null
	 */
	private ?string $billingPeriod;

	/**
	 * Billing interval count (e.g. every 1 year), or null for a one-time tier.
	 *
	 * @var int|null
	 */
	private ?int $billingInterval;

	/**
	 * Number of trial days before the first charge.
	 *
	 * @var int
	 */
	private int $trialDays;

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
	 * Minimum units per purchase. Subscription tiers use one.
	 *
	 * @var int
	 */
	private int $minQuantity;

	/**
	 * Maximum units per purchase, or null for unlimited. Subscription tiers use one.
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
	 * @param bool        $isSubscription  Whether the tier is recurring.
	 * @param string|null $billingPeriod   Billing period, or null.
	 * @param int|null    $billingInterval Billing interval, or null.
	 * @param int         $trialDays       Trial days before first charge.
	 * @param int         $sortOrder       Display sort order.
	 * @param PriceStatus $status          Tier status.
	 * @param int         $minQuantity     Minimum units per purchase (>= 1; forced to 1 for a subscription).
	 * @param int|null    $maxQuantity     Maximum units per purchase, or null for unlimited (forced to 1 for a subscription).
	 *
	 * @throws ProductException When the name is empty, the amount is negative, or the
	 *                          quantity rule is invalid on a one-time tier.
	 */
	public function __construct(
		?int $id,
		?int $productId,
		string $name,
		int $amount,
		?int $activationLimit,
		?string $licenseLength,
		bool $isSubscription,
		?string $billingPeriod,
		?int $billingInterval,
		int $trialDays,
		int $sortOrder,
		PriceStatus $status,
		int $minQuantity,
		?int $maxQuantity
	) {
		if ( '' === trim( $name ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured validation message, surfaced as an API error, never echoed as HTML.
			throw new ProductException( __( 'A price tier must have a non-empty name.', 'tiermint' ) );
		}

		if ( $amount < 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured validation message, surfaced as an API error, never echoed as HTML.
			throw new ProductException( __( 'A price amount cannot be negative.', 'tiermint' ) );
		}

		// Reject values that unsigned columns would silently change to zero.
		if ( null !== $activationLimit && $activationLimit < 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured validation message, surfaced as an API error, never echoed as HTML.
			throw new ProductException( __( 'An activation limit cannot be negative.', 'tiermint' ) );
		}

		if ( $trialDays < 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured validation message, surfaced as an API error, never echoed as HTML.
			throw new ProductException( __( 'A trial length cannot be negative.', 'tiermint' ) );
		}

		if ( $sortOrder < 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured validation message, surfaced as an API error, never echoed as HTML.
			throw new ProductException( __( 'A sort order cannot be negative.', 'tiermint' ) );
		}

		if ( null !== $billingInterval && $billingInterval < 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured validation message, surfaced as an API error, never echoed as HTML.
			throw new ProductException( __( 'A billing interval cannot be negative.', 'tiermint' ) );
		}

		if ( $isSubscription ) {
			// Recurring tiers need a valid period and an interval of at least one.
			if ( ! in_array( $billingPeriod, array( 'day', 'week', 'month', 'year' ), true ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured validation message, surfaced as an API error, never echoed as HTML.
				throw new ProductException( __( 'A recurring price tier needs a billing period of day, week, month, or year.', 'tiermint' ) );
			}

			$billingInterval = $billingInterval ?? 1;

			if ( $billingInterval < 1 ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured validation message, surfaced as an API error, never echoed as HTML.
				throw new ProductException( __( 'A recurring price tier needs a billing interval of at least 1.', 'tiermint' ) );
			}
		} else {
			// One-time tiers do not store subscription fields.
			$billingPeriod   = null;
			$billingInterval = null;
			$trialDays       = 0;
		}

		if ( $isSubscription ) {
			// A subscription always represents one entitlement.
			$minQuantity = 1;
			$maxQuantity = 1;
		} else {
			if ( $minQuantity < 1 ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured validation message, surfaced as an API error, never echoed as HTML.
				throw new ProductException( __( 'A minimum quantity must be at least 1.', 'tiermint' ) );
			}

			if ( null !== $maxQuantity && $maxQuantity < $minQuantity ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured validation message, surfaced as an API error, never echoed as HTML.
				throw new ProductException( __( 'A maximum quantity cannot be less than the minimum quantity.', 'tiermint' ) );
			}
		}

		$this->id              = $id;
		$this->productId       = $productId;
		$this->name            = $name;
		$this->amount          = $amount;
		$this->activationLimit = $activationLimit;
		$this->licenseLength   = $licenseLength;
		$this->isSubscription  = $isSubscription;
		$this->billingPeriod   = $billingPeriod;
		$this->billingInterval = $billingInterval;
		$this->trialDays       = $trialDays;
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
			(bool) ( $data['is_subscription'] ?? false ),
			self::nullableString( $data['billing_period'] ?? null ),
			self::nullableInt( $data['billing_interval'] ?? null ),
			(int) ( $data['trial_days'] ?? 0 ),
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
			'is_subscription'  => $this->isSubscription ? 1 : 0,
			'billing_period'   => $this->billingPeriod,
			'billing_interval' => $this->billingInterval,
			'trial_days'       => $this->trialDays,
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
	 * Resolve the billing-period sentinel at mint time.
	 *
	 * An incomplete subscription resolves to a lifetime license.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null The strtotime-relative length, or null for lifetime.
	 */
	public function resolvedLicenseLength(): ?string {
		if ( self::LICENSE_LENGTH_BILLING !== $this->licenseLength ) {
			return $this->licenseLength;
		}

		if ( ! $this->isSubscription || null === $this->billingPeriod || null === $this->billingInterval ) {
			return null;
		}

		return sprintf( '+%d %s', $this->billingInterval, $this->billingPeriod );
	}

	/**
	 * Validate a license length before a create or update.
	 *
	 * Legacy rows can still hydrate with a historic value.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $length         The candidate license length.
	 * @param bool        $isSubscription Whether the owning tier is a subscription.
	 * @return string|null The validation message, or null when the value is valid.
	 */
	public static function licenseLengthError( ?string $length, bool $isSubscription ): ?string {
		if ( null === $length ) {
			return null;
		}

		if ( self::LICENSE_LENGTH_BILLING === $length ) {
			return $isSubscription
				? null
				: __( '"Same as billing interval" is only available on a subscription price tier.', 'tiermint' );
		}

		// A plain number is not a meaningful date interval.
		if ( 1 === preg_match( '/^-?\d+$/', $length ) ) {
			return __( 'A license length needs a unit, for example "+1 year". A plain number is not accepted; leave it blank for a lifetime license.', 'tiermint' );
		}

		try {
			$base   = new DateTimeImmutable( '2000-01-01 00:00:00' );
			$parsed = @$base->modify( $length ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Pre-8.3 modify() warns instead of throwing on a bad string; the false return is handled right below.
		} catch ( Throwable $t ) {
			$parsed = false;
		}

		if ( false === $parsed ) {
			/* translators: %s: the submitted license length value. */
			return sprintf( __( '"%s" is not a valid license length. Use a value like "+1 year", or leave it blank for a lifetime license.', 'tiermint' ), $length );
		}

		return null;
	}

	/**
	 * Whether the tier is a subscription.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function isSubscription(): bool {
		return $this->isSubscription;
	}

	/**
	 * The billing period, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public function billingPeriod(): ?string {
		return $this->billingPeriod;
	}

	/**
	 * The billing interval, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return int|null
	 */
	public function billingInterval(): ?int {
		return $this->billingInterval;
	}

	/**
	 * The trial length in days.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function trialDays(): int {
		return $this->trialDays;
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
	 * Whether the tier can be sold on this site.
	 *
	 * Recurring tiers need a billing provider.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $recurringSupported Whether recurring tiers can be billed on this install.
	 * @return bool True when the tier is active and sellable under that verdict.
	 */
	public function isPurchasable( bool $recurringSupported ): bool {
		return $this->isActive() && ( $recurringSupported || ! $this->isSubscription );
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
