<?php
/**
 * Customer aggregate value object.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Customers;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Customer data with no database or WordPress access.
 *
 * Email is normalised. A non-positive user ID is a guest. The service handles lookups.
 *
 * @since 1.0.0
 */
final class Customer {

	/**
	 * Primary key, or null for a customer that has not been persisted yet.
	 *
	 * @var int|null
	 */
	private ?int $id;

	/**
	 * The linked WordPress user id, or null for a guest.
	 *
	 * @var int|null
	 */
	private ?int $userId;

	/**
	 * Normalized (trimmed, lowercased) email address: the customer's identity.
	 *
	 * @var string
	 */
	private string $email;

	/**
	 * Display name, or null.
	 *
	 * @var string|null
	 */
	private ?string $name;

	/**
	 * Given name, or null. Checkout mirrors it to WordPress user meta.
	 *
	 * @var string|null
	 */
	private ?string $firstName;

	/**
	 * Family name, or null. The `last_name` counterpart to {@see $firstName}.
	 *
	 * @var string|null
	 */
	private ?string $lastName;

	/**
	 * Number of completed purchases; denormalized, maintained by the order layer.
	 *
	 * @var int
	 */
	private int $purchaseCount;

	/**
	 * Lifetime spend in minor units, maintained by the order layer.
	 *
	 * @var int
	 */
	private int $purchaseTotal;

	/**
	 * UTC creation timestamp (Y-m-d H:i:s), or null before first persist.
	 *
	 * @var string|null
	 */
	private ?string $createdAt;

	/**
	 * UTC last-update timestamp (Y-m-d H:i:s), or null before first persist.
	 *
	 * @var string|null
	 */
	private ?string $updatedAt;

	/**
	 * Create and validate a customer.
	 *
	 * @since 1.0.0
	 *
	 * @param int|null    $id            Primary key, or null when unsaved.
	 * @param int|null    $userId        Linked WordPress user id, or null for a guest.
	 * @param string      $email         Email address (validated and normalized here).
	 * @param string|null $name          Display name, or null.
	 * @param int         $purchaseCount Completed purchase count.
	 * @param int         $purchaseTotal Lifetime spend in minor units.
	 * @param string|null $createdAt     UTC created timestamp, or null.
	 * @param string|null $updatedAt     UTC updated timestamp, or null.
	 * @param string|null $firstName     Given name, or null. Trailing and optional so the
	 *                                   many existing positional call sites stay valid.
	 * @param string|null $lastName      Family name, or null.
	 *
	 * @throws CustomerException When the email is empty or malformed.
	 */
	public function __construct(
		?int $id,
		?int $userId,
		string $email,
		?string $name,
		int $purchaseCount,
		int $purchaseTotal,
		?string $createdAt,
		?string $updatedAt,
		?string $firstName = null,
		?string $lastName = null
	) {
		$normalizedEmail = self::normalizeEmail( $email );

		if ( '' === $normalizedEmail || false === filter_var( $normalizedEmail, FILTER_VALIDATE_EMAIL ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new CustomerException( __( 'A customer requires a valid email address.', 'tiermint' ) );
		}

		$name = null === $name ? null : trim( $name );

		// A user_id of 0 or below is not a real WordPress user, so treat it as a guest.
		$linkedUserId = ( null !== $userId && $userId > 0 ) ? $userId : null;

		$this->id            = $id;
		$this->userId        = $linkedUserId;
		$this->email         = $normalizedEmail;
		$this->name          = ( null === $name || '' === $name ) ? null : $name;
		$this->firstName     = self::blankToNull( $firstName );
		$this->lastName      = self::blankToNull( $lastName );
		$this->purchaseCount = max( 0, $purchaseCount );
		$this->purchaseTotal = $purchaseTotal;
		$this->createdAt     = $createdAt;
		$this->updatedAt     = $updatedAt;
	}

	/**
	 * Trim a value and return null if it is empty.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $value The raw value.
	 * @return string|null The trimmed value, or null when blank.
	 */
	private static function blankToNull( ?string $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		$trimmed = trim( $value );

		return '' === $trimmed ? null : $trimmed;
	}

	/**
	 * Build a customer from input data with guest defaults.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $data Scalar fields keyed by column name.
	 * @return self
	 *
	 * @throws CustomerException When the email is empty or malformed.
	 */
	public static function fromArray( array $data ): self {
		return new self(
			isset( $data['id'] ) ? (int) $data['id'] : null,
			isset( $data['user_id'] ) && null !== $data['user_id'] ? (int) $data['user_id'] : null,
			(string) ( $data['email'] ?? '' ),
			isset( $data['name'] ) && null !== $data['name'] ? (string) $data['name'] : null,
			(int) ( $data['purchase_count'] ?? 0 ),
			(int) ( $data['purchase_total'] ?? 0 ),
			isset( $data['created_at'] ) && null !== $data['created_at'] ? (string) $data['created_at'] : null,
			isset( $data['updated_at'] ) && null !== $data['updated_at'] ? (string) $data['updated_at'] : null,
			isset( $data['first_name'] ) && null !== $data['first_name'] ? (string) $data['first_name'] : null,
			isset( $data['last_name'] ) && null !== $data['last_name'] ? (string) $data['last_name'] : null
		);
	}

	/**
	 * Build a customer from a database row.
	 *
	 * @since 1.0.0
	 *
	 * @param object $row A `tiermint_customers` row.
	 * @return self
	 *
	 * @throws CustomerException When the stored email is malformed.
	 */
	public static function fromRow( object $row ): self {
		return self::fromArray( (array) $row );
	}

	/**
	 * Return repository columns without IDs or timestamps.
	 *
	 * A guest has a null user ID, so the unique index does not match another guest.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed> Column name => value.
	 */
	public function toRow(): array {
		return array(
			'user_id'        => $this->userId,
			'email'          => $this->email,
			'name'           => $this->name,
			'first_name'     => $this->firstName,
			'last_name'      => $this->lastName,
			'purchase_count' => $this->purchaseCount,
			'purchase_total' => $this->purchaseTotal,
		);
	}

	/**
	 * Trim and lowercase an email for comparisons.
	 *
	 * @since 1.0.0
	 *
	 * @param string $email The raw email address.
	 * @return string The normalized email.
	 */
	public static function normalizeEmail( string $email ): string {
		return strtolower( trim( $email ) );
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
	 * The linked WordPress user id, or null for a guest.
	 *
	 * @since 1.0.0
	 *
	 * @return int|null
	 */
	public function userId(): ?int {
		return $this->userId;
	}

	/**
	 * The normalized email address.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function email(): string {
		return $this->email;
	}

	/**
	 * The display name, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public function name(): ?string {
		return $this->name;
	}

	/**
	 * The given name, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public function firstName(): ?string {
		return $this->firstName;
	}

	/**
	 * The family name, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public function lastName(): ?string {
		return $this->lastName;
	}

	/**
	 * The denormalized completed-purchase count.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function purchaseCount(): int {
		return $this->purchaseCount;
	}

	/**
	 * The denormalized lifetime spend in minor units.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function purchaseTotal(): int {
		return $this->purchaseTotal;
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
	 * Whether this customer is a guest (no linked WordPress user account).
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function isGuest(): bool {
		return null === $this->userId;
	}

	/**
	 * Whether this customer is linked to a WordPress user account.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function isLinked(): bool {
		return null !== $this->userId;
	}
}
