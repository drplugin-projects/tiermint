<?php
/**
 * The management API key entity.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\REST\Auth;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DateTimeImmutable;

/**
 * Immutable management API credential.
 *
 * The secret is shown once and never stored. The repository handles persistence.
 *
 * @since 1.0.0
 */
final class ApiKey {

	/**
	 * The public key format.
	 *
	 * @var string
	 */
	public const PUBLIC_KEY_PATTERN = '/^tmk_[0-9a-f]{32}$/';

	/**
	 * The row id, or null before the first persist.
	 *
	 * @var int|null
	 */
	private ?int $id;

	/**
	 * The owning WordPress user ID.
	 *
	 * @var int
	 */
	private int $userId;

	/**
	 * The human label the admin gave the key.
	 *
	 * @var string
	 */
	private string $label;

	/**
	 * The public lookup key.
	 *
	 * @var string
	 */
	private string $publicKey;

	/**
	 * The stored secret hash.
	 *
	 * @var string
	 */
	private string $secretHash;

	/**
	 * The permission allowed by the key.
	 *
	 * @var ApiKeyPermission
	 */
	private ApiKeyPermission $permission;

	/**
	 * The lifecycle status.
	 *
	 * @var ApiKeyStatus
	 */
	private ApiKeyStatus $status;

	/**
	 * When the key last authenticated a request, or null if never.
	 *
	 * @var DateTimeImmutable|null
	 */
	private ?DateTimeImmutable $lastUsedAt;

	/**
	 * When the key was created, or null before the first persist.
	 *
	 * @var DateTimeImmutable|null
	 */
	private ?DateTimeImmutable $createdAt;

	/**
	 * Create and validate the credential.
	 *
	 * @since 1.0.0
	 *
	 * @param int|null               $id         The row id, or null before persisting.
	 * @param int                    $userId     The owning WP user id.
	 * @param string                 $label      The human label.
	 * @param string                 $publicKey  The tmk_-prefixed public key.
	 * @param string                 $secretHash The hash of the secret.
	 * @param ApiKeyPermission       $permission The read/write permission.
	 * @param ApiKeyStatus           $status     The lifecycle status.
	 * @param DateTimeImmutable|null $lastUsedAt When the key last authenticated, or null.
	 * @param DateTimeImmutable|null $createdAt  When the key was created, or null.
	 *
	 * @throws ApiKeyValidationException When a field is missing, malformed, or
	 *                                    the label exceeds 200 characters.
	 */
	public function __construct(
		?int $id,
		int $userId,
		string $label,
		string $publicKey,
		string $secretHash,
		ApiKeyPermission $permission,
		ApiKeyStatus $status,
		?DateTimeImmutable $lastUsedAt,
		?DateTimeImmutable $createdAt
	) {
		$errors = array();

		if ( $userId <= 0 ) {
			$errors[] = __( 'An API key must belong to a user.', 'tiermint' );
		}

		if ( '' === trim( $label ) ) {
			$errors[] = __( 'An API key needs a label.', 'tiermint' );
		}

		if ( strlen( $label ) > 200 ) {
			$errors[] = __( 'The API key label must be 200 characters or fewer.', 'tiermint' );
		}

		if ( 1 !== preg_match( self::PUBLIC_KEY_PATTERN, $publicKey ) ) {
			$errors[] = __( 'The public key is malformed.', 'tiermint' );
		}

		if ( '' === $secretHash ) {
			$errors[] = __( 'An API key cannot exist without a secret.', 'tiermint' );
		}

		if ( array() !== $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw ApiKeyValidationException::withErrors( $errors );
		}

		$this->id         = $id;
		$this->userId     = $userId;
		$this->label      = $label;
		$this->publicKey  = $publicKey;
		$this->secretHash = $secretHash;
		$this->permission = $permission;
		$this->status     = $status;
		$this->lastUsedAt = $lastUsedAt;
		$this->createdAt  = $createdAt;
	}

	/**
	 * The row id, or null before the first persist.
	 *
	 * @since 1.0.0
	 *
	 * @return int|null The id.
	 */
	public function id(): ?int {
		return $this->id;
	}

	/**
	 * The owning WP user id.
	 *
	 * @since 1.0.0
	 *
	 * @return int The user id.
	 */
	public function userId(): int {
		return $this->userId;
	}

	/**
	 * The human label the admin gave the key.
	 *
	 * @since 1.0.0
	 *
	 * @return string The label.
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * The public lookup key.
	 *
	 * @since 1.0.0
	 *
	 * @return string The tmk_-prefixed public key.
	 */
	public function publicKey(): string {
		return $this->publicKey;
	}

	/**
	 * The hash of the secret (never the plaintext).
	 *
	 * @since 1.0.0
	 *
	 * @return string The wp_hash_password() hash.
	 */
	public function secretHash(): string {
		return $this->secretHash;
	}

	/**
	 * The read/write permission.
	 *
	 * @since 1.0.0
	 *
	 * @return ApiKeyPermission The permission.
	 */
	public function permission(): ApiKeyPermission {
		return $this->permission;
	}

	/**
	 * The lifecycle status.
	 *
	 * @since 1.0.0
	 *
	 * @return ApiKeyStatus The status.
	 */
	public function status(): ApiKeyStatus {
		return $this->status;
	}

	/**
	 * When the key last authenticated a request.
	 *
	 * @since 1.0.0
	 *
	 * @return DateTimeImmutable|null The last-used instant, or null if never.
	 */
	public function lastUsedAt(): ?DateTimeImmutable {
		return $this->lastUsedAt;
	}

	/**
	 * When the key was created.
	 *
	 * @since 1.0.0
	 *
	 * @return DateTimeImmutable|null The creation instant, or null before persisting.
	 */
	public function createdAt(): ?DateTimeImmutable {
		return $this->createdAt;
	}

	/**
	 * Whether the key may authenticate at all.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True while the key is active.
	 */
	public function isActive(): bool {
		return ApiKeyStatus::Active === $this->status;
	}

	/**
	 * Whether the key may perform non-safe HTTP methods.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True for a write key.
	 */
	public function canWrite(): bool {
		return ApiKeyPermission::Write === $this->permission;
	}

	/**
	 * A copy with a different status.
	 *
	 * @since 1.0.0
	 *
	 * @param ApiKeyStatus $status The new status.
	 * @return self The modified copy.
	 */
	public function withStatus( ApiKeyStatus $status ): self {
		return new self(
			$this->id,
			$this->userId,
			$this->label,
			$this->publicKey,
			$this->secretHash,
			$this->permission,
			$status,
			$this->lastUsedAt,
			$this->createdAt
		);
	}

	/**
	 * A copy with a fresh last-used stamp.
	 *
	 * @since 1.0.0
	 *
	 * @param DateTimeImmutable $lastUsedAt The authenticating instant.
	 * @return self The modified copy.
	 */
	public function withLastUsedAt( DateTimeImmutable $lastUsedAt ): self {
		return new self(
			$this->id,
			$this->userId,
			$this->label,
			$this->publicKey,
			$this->secretHash,
			$this->permission,
			$this->status,
			$lastUsedAt,
			$this->createdAt
		);
	}

	/**
	 * Return a copy with its assigned row ID.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The assigned row id.
	 * @return self The modified copy.
	 */
	public function withId( int $id ): self {
		return new self(
			$id,
			$this->userId,
			$this->label,
			$this->publicKey,
			$this->secretHash,
			$this->permission,
			$this->status,
			$this->lastUsedAt,
			$this->createdAt
		);
	}
}
