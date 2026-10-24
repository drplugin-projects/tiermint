<?php
/**
 * Release value object.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Updates;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DateTimeImmutable;

/**
 * Immutable record of a product package release.
 *
 * This validates local fields. The state machine validates status changes.
 *
 * @since 1.0.0
 */
final class Release {

	/**
	 * The widest a version may be, matching the `version` VARCHAR(32) column.
	 *
	 * @var int
	 */
	private const MAX_VERSION_LENGTH = 32;

	/**
	 * The widest a storage key may be, matching the `storage_key` VARCHAR(500) column.
	 *
	 * @var int
	 */
	private const MAX_STORAGE_KEY_LENGTH = 500;

	/**
	 * Primary key, or null for a release that has not been persisted yet.
	 *
	 * @var int|null
	 */
	private ?int $id;

	/**
	 * The product this release belongs to (a positive id).
	 *
	 * @var int
	 */
	private int $productId;

	/**
	 * Version string, unique per product.
	 *
	 * @var string
	 */
	private string $version;

	/**
	 * The storage adapter id the package lives under, e.g. 'local'.
	 *
	 * @var string
	 */
	private string $storageProvider;

	/**
	 * Provider-scoped package key.
	 *
	 * @var string
	 */
	private string $storageKey;

	/**
	 * Package size in bytes, or null.
	 *
	 * @var int|null
	 */
	private ?int $filesize;

	/**
	 * Package SHA-256 checksum, or null.
	 *
	 * @var string|null
	 */
	private ?string $checksumSha256;

	/**
	 * The minimum WordPress version the release requires, or null.
	 *
	 * @var string|null
	 */
	private ?string $requiresWp;

	/**
	 * The highest WordPress version the release is tested against, or null.
	 *
	 * @var string|null
	 */
	private ?string $testedUpTo;

	/**
	 * The minimum PHP version the release requires, or null.
	 *
	 * @var string|null
	 */
	private ?string $requiresPhp;

	/**
	 * Markdown changelog source, or null.
	 *
	 * @var string|null
	 */
	private ?string $changelog;

	/**
	 * Lifecycle status.
	 *
	 * @var ReleaseStatus
	 */
	private ReleaseStatus $status;

	/**
	 * Publish time, or null for a draft.
	 *
	 * @var DateTimeImmutable|null
	 */
	private ?DateTimeImmutable $releasedAt;

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
	 * Construct and validate a release.
	 *
	 * @since 1.0.0
	 *
	 * @param int|null               $id              Primary key, or null when unsaved.
	 * @param int                    $productId       Owning product id (must be positive).
	 * @param string                 $version         Version string (present, at most 32 chars).
	 * @param string                 $storageProvider Storage adapter id, e.g. 'local'.
	 * @param string                 $storageKey      Provider-scoped package key (present, at most 500 chars).
	 * @param int|null               $filesize        Package size in bytes, or null.
	 * @param string|null            $checksumSha256  SHA-256 checksum, or null.
	 * @param string|null            $requiresWp      Minimum WordPress version, or null.
	 * @param string|null            $testedUpTo      Highest tested WordPress version, or null.
	 * @param string|null            $requiresPhp     Minimum PHP version, or null.
	 * @param string|null            $changelog       Markdown changelog source, or null.
	 * @param ReleaseStatus          $status          Lifecycle status.
	 * @param DateTimeImmutable|null $releasedAt      Publish instant, or null.
	 * @param DateTimeImmutable|null $createdAt       Creation instant, or null.
	 * @param DateTimeImmutable|null $updatedAt       Last-update instant, or null.
	 *
	 * @throws ReleaseValidationException When the product id, version or storage key is invalid.
	 */
	public function __construct(
		?int $id,
		int $productId,
		string $version,
		string $storageProvider,
		string $storageKey,
		?int $filesize,
		?string $checksumSha256,
		?string $requiresWp,
		?string $testedUpTo,
		?string $requiresPhp,
		?string $changelog,
		ReleaseStatus $status,
		?DateTimeImmutable $releasedAt,
		?DateTimeImmutable $createdAt,
		?DateTimeImmutable $updatedAt
	) {
		if ( $productId <= 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new ReleaseValidationException( __( 'A release must belong to a product.', 'tiermint' ) );
		}

		if ( '' === $version ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new ReleaseValidationException( __( 'A release must have a version.', 'tiermint' ) );
		}

		// The version is stored in a fixed VARCHAR(32) column, so anything longer would be
		// truncated silently on write and never match on lookup. Reject it up front.
		if ( strlen( $version ) > self::MAX_VERSION_LENGTH ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new ReleaseValidationException( __( 'A release version cannot be longer than 32 characters.', 'tiermint' ) );
		}

		if ( '' === $storageKey ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new ReleaseValidationException( __( 'A release must have a storage key for its package.', 'tiermint' ) );
		}

		if ( strlen( $storageKey ) > self::MAX_STORAGE_KEY_LENGTH ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new ReleaseValidationException( __( 'A release storage key cannot be longer than 500 characters.', 'tiermint' ) );
		}

		$this->id              = $id;
		$this->productId       = $productId;
		$this->version         = $version;
		$this->storageProvider = $storageProvider;
		$this->storageKey      = $storageKey;
		$this->filesize        = $filesize;
		$this->checksumSha256  = $checksumSha256;
		$this->requiresWp      = $requiresWp;
		$this->testedUpTo      = $testedUpTo;
		$this->requiresPhp     = $requiresPhp;
		$this->changelog       = $changelog;
		$this->status          = $status;
		$this->releasedAt      = $releasedAt;
		$this->createdAt       = $createdAt;
		$this->updatedAt       = $updatedAt;
	}

	/**
	 * Whether the release is currently published.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when the release is published.
	 */
	public function isPublished(): bool {
		return ReleaseStatus::Published === $this->status;
	}

	/**
	 * Return a copy with a new status.
	 *
	 * @since 1.0.0
	 *
	 * @param ReleaseStatus $status The status the copy should carry.
	 * @return self The new release.
	 */
	public function withStatus( ReleaseStatus $status ): self {
		return new self(
			$this->id,
			$this->productId,
			$this->version,
			$this->storageProvider,
			$this->storageKey,
			$this->filesize,
			$this->checksumSha256,
			$this->requiresWp,
			$this->testedUpTo,
			$this->requiresPhp,
			$this->changelog,
			$status,
			$this->releasedAt,
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
	 * The version string.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function version(): string {
		return $this->version;
	}

	/**
	 * The storage adapter id the package lives under.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function storageProvider(): string {
		return $this->storageProvider;
	}

	/**
	 * The provider-scoped package key.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function storageKey(): string {
		return $this->storageKey;
	}

	/**
	 * The package size in bytes, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return int|null
	 */
	public function filesize(): ?int {
		return $this->filesize;
	}

	/**
	 * The package SHA-256 checksum, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public function checksumSha256(): ?string {
		return $this->checksumSha256;
	}

	/**
	 * The minimum required WordPress version, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public function requiresWp(): ?string {
		return $this->requiresWp;
	}

	/**
	 * The highest tested WordPress version, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public function testedUpTo(): ?string {
		return $this->testedUpTo;
	}

	/**
	 * The minimum required PHP version, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public function requiresPhp(): ?string {
		return $this->requiresPhp;
	}

	/**
	 * The Markdown changelog source, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public function changelog(): ?string {
		return $this->changelog;
	}

	/**
	 * The lifecycle status.
	 *
	 * @since 1.0.0
	 *
	 * @return ReleaseStatus
	 */
	public function status(): ReleaseStatus {
		return $this->status;
	}

	/**
	 * The publish instant, or null while still a draft.
	 *
	 * @since 1.0.0
	 *
	 * @return DateTimeImmutable|null
	 */
	public function releasedAt(): ?DateTimeImmutable {
		return $this->releasedAt;
	}

	/**
	 * The creation instant, or null before the first persist.
	 *
	 * @since 1.0.0
	 *
	 * @return DateTimeImmutable|null
	 */
	public function createdAt(): ?DateTimeImmutable {
		return $this->createdAt;
	}

	/**
	 * The last-update instant, or null before the first persist.
	 *
	 * @since 1.0.0
	 *
	 * @return DateTimeImmutable|null
	 */
	public function updatedAt(): ?DateTimeImmutable {
		return $this->updatedAt;
	}
}
