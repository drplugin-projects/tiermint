<?php
/**
 * License aggregate value object.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Licensing;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DateTimeImmutable;

/**
 * Immutable license data and local validity checks.
 *
 * State changes belong to {@see LicenseStateMachine}. Typed timestamps keep expiry checks
 * timezone-safe.
 *
 * @since 1.0.0
 */
final class License {

	/**
	 * The widest a license key may be, matching the `license_key` CHAR(35) column.
	 *
	 * @var int
	 */
	private const MAX_KEY_LENGTH = 35;

	/**
	 * Primary key, or null for a license that has not been persisted yet.
	 *
	 * @var int|null
	 */
	private ?int $id;

	/**
	 * The license key: an unguessable bearer credential, unique across the table.
	 *
	 * @var string
	 */
	private string $licenseKey;

	/**
	 * Lifecycle status.
	 *
	 * @var LicenseStatus
	 */
	private LicenseStatus $status;

	/**
	 * The one concrete product this license unlocks (bundles mint one license per child).
	 *
	 * @var int
	 */
	private int $productId;

	/**
	 * The price tier the license was bought at, or null when it maps to no explicit price.
	 *
	 * @var int|null
	 */
	private ?int $priceId;

	/**
	 * The order that created this license.
	 *
	 * @var int
	 */
	private int $orderId;

	/**
	 * The specific order line that created it, or null.
	 *
	 * @var int|null
	 */
	private ?int $orderItemId;

	/**
	 * The customer who owns it (the dashboard "my licenses" key).
	 *
	 * @var int
	 */
	private int $customerId;

	/**
	 * Activation cap from the purchased price, or null when unlimited.
	 *
	 * @var int|null
	 */
	private ?int $activationLimit;

	/**
	 * Stored count for activation-limit checks.
	 *
	 * @var int
	 */
	private int $activationCount;

	/**
	 * The expiry instant, or null for a lifetime license that never expires.
	 *
	 * @var DateTimeImmutable|null
	 */
	private ?DateTimeImmutable $expiresAt;

	/**
	 * Creation instant, or null before the first persist.
	 *
	 * @var DateTimeImmutable|null
	 */
	private ?DateTimeImmutable $createdAt;

	/**
	 * Last-update instant, or null before the first persist.
	 *
	 * @var DateTimeImmutable|null
	 */
	private ?DateTimeImmutable $updatedAt;

	/**
	 * Construct and validate a license.
	 *
	 * @since 1.0.0
	 *
	 * @param int|null               $id              Primary key, or null when unsaved.
	 * @param string                 $licenseKey      The license key (present, at most 35 chars).
	 * @param LicenseStatus          $status          Lifecycle status.
	 * @param int                    $productId       Owning product id (must be positive).
	 * @param int|null               $priceId         Price tier id, or null.
	 * @param int                    $orderId         Owning order id (must be positive).
	 * @param int|null               $orderItemId     Owning order line id, or null.
	 * @param int                    $customerId      Owning customer id (must be positive).
	 * @param int|null               $activationLimit Activation cap, or null for unlimited (non-negative when set).
	 * @param int                    $activationCount Current activation count (non-negative).
	 * @param DateTimeImmutable|null $expiresAt       Expiry instant, or null for lifetime.
	 * @param DateTimeImmutable|null $createdAt       Creation instant, or null.
	 * @param DateTimeImmutable|null $updatedAt       Last-update instant, or null.
	 *
	 * @throws LicenseValidationException When the key, an owning id, the limit, or the count is invalid.
	 */
	public function __construct(
		?int $id,
		string $licenseKey,
		LicenseStatus $status,
		int $productId,
		?int $priceId,
		int $orderId,
		?int $orderItemId,
		int $customerId,
		?int $activationLimit,
		int $activationCount,
		?DateTimeImmutable $expiresAt,
		?DateTimeImmutable $createdAt,
		?DateTimeImmutable $updatedAt
	) {
		if ( '' === $licenseKey ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new LicenseValidationException( __( 'A license must have a key.', 'tiermint' ) );
		}

		// The key is stored in a fixed CHAR(35) column, so anything longer would be
		// truncated silently on write and never match on lookup. Reject it up front.
		if ( strlen( $licenseKey ) > self::MAX_KEY_LENGTH ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new LicenseValidationException( __( 'A license key cannot be longer than 35 characters.', 'tiermint' ) );
		}

		if ( $productId <= 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new LicenseValidationException( __( 'A license must belong to a product.', 'tiermint' ) );
		}

		if ( $orderId <= 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new LicenseValidationException( __( 'A license must belong to an order.', 'tiermint' ) );
		}

		if ( $customerId <= 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new LicenseValidationException( __( 'A license must belong to a customer.', 'tiermint' ) );
		}

		// NULL means unlimited; a negative cap is meaningless (the column is UNSIGNED).
		if ( null !== $activationLimit && $activationLimit < 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new LicenseValidationException( __( 'A license activation limit cannot be negative.', 'tiermint' ) );
		}

		if ( $activationCount < 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new LicenseValidationException( __( 'A license activation count cannot be negative.', 'tiermint' ) );
		}

		$this->id              = $id;
		$this->licenseKey      = $licenseKey;
		$this->status          = $status;
		$this->productId       = $productId;
		$this->priceId         = $priceId;
		$this->orderId         = $orderId;
		$this->orderItemId     = $orderItemId;
		$this->customerId      = $customerId;
		$this->activationLimit = $activationLimit;
		$this->activationCount = $activationCount;
		$this->expiresAt       = $expiresAt;
		$this->createdAt       = $createdAt;
		$this->updatedAt       = $updatedAt;
	}

	/**
	 * Whether this is a lifetime license (no expiry).
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when the license never expires.
	 */
	public function isLifetime(): bool {
		return null === $this->expiresAt;
	}

	/**
	 * Whether expiry is before the given moment.
	 *
	 * @since 1.0.0
	 *
	 * @param DateTimeImmutable $now The moment to judge expiry against.
	 * @return bool True when the license is expired at $now.
	 */
	public function isExpiredAt( DateTimeImmutable $now ): bool {
		return null !== $this->expiresAt && $this->expiresAt < $now;
	}

	/**
	 * Whether the license allows an unlimited number of activations.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when there is no activation cap.
	 */
	public function hasUnlimitedActivations(): bool {
		return null === $this->activationLimit;
	}

	/**
	 * Whether the activation count has reached its cap.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when no further activation may be counted.
	 */
	public function hasReachedActivationLimit(): bool {
		if ( null === $this->activationLimit ) {
			return false;
		}

		return $this->activationCount >= $this->activationLimit;
	}

	/**
	 * Return a copy with a new status.
	 *
	 * @since 1.0.0
	 *
	 * @param LicenseStatus $status The status the copy should carry.
	 * @return static The new license.
	 */
	public function withStatus( LicenseStatus $status ): self {
		return new self(
			$this->id,
			$this->licenseKey,
			$status,
			$this->productId,
			$this->priceId,
			$this->orderId,
			$this->orderItemId,
			$this->customerId,
			$this->activationLimit,
			$this->activationCount,
			$this->expiresAt,
			$this->createdAt,
			$this->updatedAt
		);
	}

	/**
	 * Return a copy with a new expiry.
	 *
	 * @since 1.0.0
	 *
	 * @param DateTimeImmutable|null $expiresAt The expiry the copy should carry, or null.
	 * @return static The new license.
	 */
	public function withExpiresAt( ?DateTimeImmutable $expiresAt ): self {
		return new self(
			$this->id,
			$this->licenseKey,
			$this->status,
			$this->productId,
			$this->priceId,
			$this->orderId,
			$this->orderItemId,
			$this->customerId,
			$this->activationLimit,
			$this->activationCount,
			$expiresAt,
			$this->createdAt,
			$this->updatedAt
		);
	}

	/**
	 * Return a copy with a new price tier.
	 *
	 * @since 1.0.0
	 *
	 * @param int|null $priceId The price tier id the copy should carry, or null.
	 * @return static The new license.
	 */
	public function withPriceId( ?int $priceId ): self {
		return new self(
			$this->id,
			$this->licenseKey,
			$this->status,
			$this->productId,
			$priceId,
			$this->orderId,
			$this->orderItemId,
			$this->customerId,
			$this->activationLimit,
			$this->activationCount,
			$this->expiresAt,
			$this->createdAt,
			$this->updatedAt
		);
	}

	/**
	 * Return a copy with a new activation cap.
	 *
	 * Existing activations remain after a lower cap.
	 *
	 * @since 1.0.0
	 *
	 * @param int|null $activationLimit The activation cap the copy should carry, or null for unlimited.
	 * @return static The new license.
	 *
	 * @throws LicenseValidationException When the cap is negative.
	 */
	public function withActivationLimit( ?int $activationLimit ): self {
		return new self(
			$this->id,
			$this->licenseKey,
			$this->status,
			$this->productId,
			$this->priceId,
			$this->orderId,
			$this->orderItemId,
			$this->customerId,
			$activationLimit,
			$this->activationCount,
			$this->expiresAt,
			$this->createdAt,
			$this->updatedAt
		);
	}

	/**
	 * Return a copy with a new activation count.
	 *
	 * @since 1.0.0
	 *
	 * @param int $count The activation count the copy should carry.
	 * @return static The new license.
	 *
	 * @throws LicenseValidationException When the count is negative.
	 */
	public function withActivationCount( int $count ): self {
		return new self(
			$this->id,
			$this->licenseKey,
			$this->status,
			$this->productId,
			$this->priceId,
			$this->orderId,
			$this->orderItemId,
			$this->customerId,
			$this->activationLimit,
			$count,
			$this->expiresAt,
			$this->createdAt,
			$this->updatedAt
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
	 * The license key.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function licenseKey(): string {
		return $this->licenseKey;
	}

	/**
	 * The lifecycle status.
	 *
	 * @since 1.0.0
	 *
	 * @return LicenseStatus
	 */
	public function status(): LicenseStatus {
		return $this->status;
	}

	/**
	 * The owning product id.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function productId(): int {
		return $this->productId;
	}

	/**
	 * The price tier id, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return int|null
	 */
	public function priceId(): ?int {
		return $this->priceId;
	}

	/**
	 * The owning order id.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function orderId(): int {
		return $this->orderId;
	}

	/**
	 * The owning order line id, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return int|null
	 */
	public function orderItemId(): ?int {
		return $this->orderItemId;
	}

	/**
	 * The owning customer id.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function customerId(): int {
		return $this->customerId;
	}

	/**
	 * The activation limit, or null for unlimited.
	 *
	 * @since 1.0.0
	 *
	 * @return int|null
	 */
	public function activationLimit(): ?int {
		return $this->activationLimit;
	}

	/**
	 * The current activation count.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function activationCount(): int {
		return $this->activationCount;
	}

	/**
	 * The expiry instant, or null for lifetime.
	 *
	 * @since 1.0.0
	 *
	 * @return DateTimeImmutable|null
	 */
	public function expiresAt(): ?DateTimeImmutable {
		return $this->expiresAt;
	}

	/**
	 * The creation instant, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return DateTimeImmutable|null
	 */
	public function createdAt(): ?DateTimeImmutable {
		return $this->createdAt;
	}

	/**
	 * The last-update instant, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return DateTimeImmutable|null
	 */
	public function updatedAt(): ?DateTimeImmutable {
		return $this->updatedAt;
	}
}
