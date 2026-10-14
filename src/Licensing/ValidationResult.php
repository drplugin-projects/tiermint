<?php
/**
 * Canonical structured result of a license validation.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Licensing;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Validation data for license and update checks.
 * {@see toArray()} creates the REST response payload.
 *
 * @since 1.0.0
 */
final class ValidationResult {

	/**
	 * Build a result. Use the named constructors.
	 *
	 * @since 1.0.0
	 *
	 * @param bool                   $valid           Whether the license is presently valid.
	 * @param string                 $status          The status word.
	 * @param bool|null              $siteActive      Whether the presented site holds an active
	 *                                                activation, or null when no site was presented.
	 * @param string|null            $productSlug     The licensed product's slug, when resolvable.
	 * @param DateTimeImmutable|null $expiresAt       The license expiry, or null (lifetime/unknown key).
	 * @param int|null               $activationLimit The activation cap, or null (unlimited/unknown key).
	 * @param int|null               $activationCount The current activation count, or null for an unknown key.
	 * @param DateTimeImmutable      $checkedAt       When this validation ran.
	 * @param bool                   $fromCache       Whether this is a last-known-good answer served
	 *                                                because the live check could not complete.
	 */
	private function __construct(
		private readonly bool $valid,
		private readonly string $status,
		private readonly ?bool $siteActive,
		private readonly ?string $productSlug,
		private readonly ?DateTimeImmutable $expiresAt,
		private readonly ?int $activationLimit,
		private readonly ?int $activationCount,
		private readonly DateTimeImmutable $checkedAt,
		private readonly bool $fromCache = false
	) {
	}

	/**
	 * The result for an unknown license key.
	 *
	 * @since 1.0.0
	 *
	 * @param DateTimeImmutable $checkedAt When the validation ran.
	 * @return self The invalid result.
	 */
	public static function invalid( DateTimeImmutable $checkedAt ): self {
		return new self( false, 'invalid', null, null, null, null, null, $checkedAt );
	}

	/**
	 * The result for a key that belongs to another product.
	 *
	 * @since 1.0.0
	 *
	 * @param DateTimeImmutable $checkedAt When the validation ran.
	 * @return self The mismatch result.
	 */
	public static function keyMismatch( DateTimeImmutable $checkedAt ): self {
		return new self( false, 'key_mismatch', null, null, null, null, null, $checkedAt );
	}

	/**
	 * The result for an existing license, whatever its lifecycle status.
	 *
	 * @since 1.0.0
	 *
	 * @param License           $license     The (lazily expiry-checked) license.
	 * @param bool|null         $siteActive  The site check outcome, or null when no site was presented.
	 * @param string|null       $productSlug The product's slug, when resolvable.
	 * @param DateTimeImmutable $checkedAt   When the validation ran.
	 * @return self The result; valid only when the license is active.
	 */
	public static function forLicense( License $license, ?bool $siteActive, ?string $productSlug, DateTimeImmutable $checkedAt ): self {
		return new self(
			LicenseStatus::Active === $license->status(),
			$license->status()->value,
			$siteActive,
			$productSlug,
			$license->expiresAt(),
			$license->activationLimit(),
			$license->activationCount(),
			$checkedAt
		);
	}

	/**
	 * Build a cached result from a last-known-good payload.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $payload   The payload {@see toArray()} produced earlier.
	 * @param DateTimeImmutable    $checkedAt When this (failed) validation ran.
	 * @return self The cached result, flagged from_cache.
	 */
	public static function fromCachedPayload( array $payload, DateTimeImmutable $checkedAt ): self {
		$expiresAt = null;

		if ( isset( $payload['expires_at'] ) && is_string( $payload['expires_at'] ) ) {
			$expiresAt = new DateTimeImmutable( $payload['expires_at'], new DateTimeZone( 'UTC' ) );
		}

		return new self(
			(bool) ( $payload['valid'] ?? false ),
			(string) ( $payload['status'] ?? 'invalid' ),
			isset( $payload['site_active'] ) ? (bool) $payload['site_active'] : null,
			isset( $payload['product_slug'] ) ? (string) $payload['product_slug'] : null,
			$expiresAt,
			isset( $payload['activation_limit'] ) ? (int) $payload['activation_limit'] : null,
			isset( $payload['activation_count'] ) ? (int) $payload['activation_count'] : null,
			$checkedAt,
			true
		);
	}

	/**
	 * Whether the license is presently valid (status 'active').
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when valid.
	 */
	public function isValid(): bool {
		return $this->valid;
	}

	/**
	 * The status word.
	 *
	 * @since 1.0.0
	 *
	 * @return string One of active, expired, disabled, invalid, key_mismatch.
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Whether the result came from the last-known-good cache.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True for a grace-window answer.
	 */
	public function isFromCache(): bool {
		return $this->fromCache;
	}

	/**
	 * The REST response payload.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed> The canonical payload.
	 */
	public function toArray(): array {
		$payload = array(
			'valid'            => $this->valid,
			'status'           => $this->status,
			'site_active'      => $this->siteActive,
			'product_slug'     => $this->productSlug,
			'expires_at'       => null === $this->expiresAt
				? null
				: $this->expiresAt->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' ),
			'activation_limit' => $this->activationLimit,
			'activation_count' => $this->activationCount,
			'checked_at'       => $this->checkedAt->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' ),
		);

		if ( $this->fromCache ) {
			$payload['from_cache'] = true;
		}

		return $payload;
	}
}
