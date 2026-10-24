<?php
/**
 * Activation value object.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Licensing;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DateTimeImmutable;

/**
 * Immutable record of a licensed site activation.
 *
 * The service decides locality and URL hashing.
 *
 * @since 1.0.0
 */
final class Activation {

	/**
	 * The widest a site URL may be, matching the `site_url` VARCHAR(191) column.
	 *
	 * @var int
	 */
	private const MAX_SITE_URL_LENGTH = 191;

	/**
	 * The widest a fingerprint may be, matching the `fingerprint` VARCHAR(64) column.
	 *
	 * @var int
	 */
	private const MAX_FINGERPRINT_LENGTH = 64;

	/**
	 * Primary key, or null for an activation that has not been persisted yet.
	 *
	 * @var int|null
	 */
	private ?int $id;

	/**
	 * The license this activation belongs to.
	 *
	 * @var int
	 */
	private int $licenseId;

	/**
	 * The normalized site URL, kept for display.
	 *
	 * @var string
	 */
	private string $siteUrl;

	/**
	 * SHA-256 lookup key for the normalized site URL.
	 *
	 * @var string
	 */
	private string $siteHash;

	/**
	 * An optional client-supplied device fingerprint, or null (WordPress clients send none).
	 *
	 * @var string|null
	 */
	private ?string $fingerprint;

	/**
	 * Activation status.
	 *
	 * @var ActivationStatus
	 */
	private ActivationStatus $status;

	/**
	 * Whether this is a local/staging install, which does not count against the limit.
	 *
	 * @var bool
	 */
	private bool $isLocal;

	/**
	 * Decoded client environment, or null.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $environment;

	/**
	 * The instant the site was activated, or null before the first persist.
	 *
	 * @var DateTimeImmutable|null
	 */
	private ?DateTimeImmutable $activatedAt;

	/**
	 * The instant the site was deactivated, or null while it is still active.
	 *
	 * @var DateTimeImmutable|null
	 */
	private ?DateTimeImmutable $deactivatedAt;

	/**
	 * The instant of the last validation/update check from this site, or null.
	 *
	 * @var DateTimeImmutable|null
	 */
	private ?DateTimeImmutable $lastCheckedAt;

	/**
	 * Construct and validate an activation.
	 *
	 * @since 1.0.0
	 *
	 * @param int|null                  $id            Primary key, or null when unsaved.
	 * @param int                       $licenseId     Owning license id (must be positive).
	 * @param string                    $siteUrl       Normalized site URL (present, at most 191 chars).
	 * @param string                    $siteHash      SHA-256 site hash (exactly 64 hex characters).
	 * @param string|null               $fingerprint   Device fingerprint, or null (at most 64 chars when set).
	 * @param ActivationStatus          $status        Activation status.
	 * @param bool                      $isLocal       Whether this is a local/staging install.
	 * @param array<string, mixed>|null $environment   Decoded client environment, or null.
	 * @param DateTimeImmutable|null    $activatedAt   Activation instant, or null.
	 * @param DateTimeImmutable|null    $deactivatedAt Deactivation instant, or null.
	 * @param DateTimeImmutable|null    $lastCheckedAt Last-checked instant, or null.
	 *
	 * @throws LicenseValidationException When the license id, site URL, hash, or fingerprint is invalid.
	 */
	public function __construct(
		?int $id,
		int $licenseId,
		string $siteUrl,
		string $siteHash,
		?string $fingerprint,
		ActivationStatus $status,
		bool $isLocal,
		?array $environment,
		?DateTimeImmutable $activatedAt,
		?DateTimeImmutable $deactivatedAt,
		?DateTimeImmutable $lastCheckedAt
	) {
		if ( $licenseId <= 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new LicenseValidationException( __( 'An activation must belong to a license.', 'tiermint' ) );
		}

		if ( '' === $siteUrl ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new LicenseValidationException( __( 'An activation must have a site URL.', 'tiermint' ) );
		}

		if ( strlen( $siteUrl ) > self::MAX_SITE_URL_LENGTH ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new LicenseValidationException( __( 'An activation site URL cannot be longer than 191 characters.', 'tiermint' ) );
		}

		// The hash is the fixed-width lookup key; anything but 64 hex chars could never be
		// a real SHA-256 digest and would break the composite unique index.
		if ( 1 !== preg_match( '/^[0-9a-fA-F]{64}$/', $siteHash ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new LicenseValidationException( __( 'An activation site hash must be a 64-character hexadecimal string.', 'tiermint' ) );
		}

		if ( null !== $fingerprint && strlen( $fingerprint ) > self::MAX_FINGERPRINT_LENGTH ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new LicenseValidationException( __( 'An activation fingerprint cannot be longer than 64 characters.', 'tiermint' ) );
		}

		$this->id            = $id;
		$this->licenseId     = $licenseId;
		$this->siteUrl       = $siteUrl;
		$this->siteHash      = $siteHash;
		$this->fingerprint   = $fingerprint;
		$this->status        = $status;
		$this->isLocal       = $isLocal;
		$this->environment   = $environment;
		$this->activatedAt   = $activatedAt;
		$this->deactivatedAt = $deactivatedAt;
		$this->lastCheckedAt = $lastCheckedAt;
	}

	/**
	 * Whether this activation is currently active (as opposed to deactivated).
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when the site is active.
	 */
	public function isActive(): bool {
		return ActivationStatus::Active === $this->status;
	}

	/**
	 * Return a copy with a new status.
	 *
	 * Deactivation keeps the row for history.
	 *
	 * @since 1.0.0
	 *
	 * @param ActivationStatus $status The status the copy should carry.
	 * @return static The new activation.
	 */
	public function withStatus( ActivationStatus $status ): self {
		return new self(
			$this->id,
			$this->licenseId,
			$this->siteUrl,
			$this->siteHash,
			$this->fingerprint,
			$status,
			$this->isLocal,
			$this->environment,
			$this->activatedAt,
			$this->deactivatedAt,
			$this->lastCheckedAt
		);
	}

	/**
	 * Return a copy with a new deactivation time.
	 *
	 * @since 1.0.0
	 *
	 * @param DateTimeImmutable|null $deactivatedAt The deactivation instant the copy should carry, or null.
	 * @return static The new activation.
	 */
	public function withDeactivatedAt( ?DateTimeImmutable $deactivatedAt ): self {
		return new self(
			$this->id,
			$this->licenseId,
			$this->siteUrl,
			$this->siteHash,
			$this->fingerprint,
			$this->status,
			$this->isLocal,
			$this->environment,
			$this->activatedAt,
			$deactivatedAt,
			$this->lastCheckedAt
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
	 * The owning license id.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function licenseId(): int {
		return $this->licenseId;
	}

	/**
	 * The normalized site URL (display form).
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function siteUrl(): string {
		return $this->siteUrl;
	}

	/**
	 * The SHA-256 site hash (lookup key).
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function siteHash(): string {
		return $this->siteHash;
	}

	/**
	 * The device fingerprint, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public function fingerprint(): ?string {
		return $this->fingerprint;
	}

	/**
	 * The activation status.
	 *
	 * @since 1.0.0
	 *
	 * @return ActivationStatus
	 */
	public function status(): ActivationStatus {
		return $this->status;
	}

	/**
	 * Whether this is a local/staging install (does not count against the limit).
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function isLocal(): bool {
		return $this->isLocal;
	}

	/**
	 * The decoded client environment, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>|null
	 */
	public function environment(): ?array {
		return $this->environment;
	}

	/**
	 * The activation instant, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return DateTimeImmutable|null
	 */
	public function activatedAt(): ?DateTimeImmutable {
		return $this->activatedAt;
	}

	/**
	 * The deactivation instant, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return DateTimeImmutable|null
	 */
	public function deactivatedAt(): ?DateTimeImmutable {
		return $this->deactivatedAt;
	}

	/**
	 * The last-checked instant, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return DateTimeImmutable|null
	 */
	public function lastCheckedAt(): ?DateTimeImmutable {
		return $this->lastCheckedAt;
	}
}
