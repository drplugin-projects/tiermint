<?php
/**
 * License validation service: the hot path's decision logic.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Licensing;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DateTimeImmutable;
use DateTimeZone;
use DrPlugin\WP\TierMint\Products\ProductService;

/**
 * Validates license, product and site claims.
 *
 * Cache only positive results for database-fault grace.
 *
 * @since 1.0.0
 */
class LicenseValidationService {

	/**
	 * Minimum hours between last-checked stamps per activation.
	 *
	 * @var int
	 */
	private const TOUCH_THROTTLE_HOURS = 6;

	/**
	 * Wire the service with its collaborators.
	 *
	 * @since 1.0.0
	 *
	 * @param LicenseRepository    $licenses    The license repository (hot-path lookup).
	 * @param ActivationRepository $activations The activation repository (site check).
	 * @param LicenseService       $licenseSvc  The license service (lazy expiry flip).
	 * @param ProductService       $products    The catalog (slug resolution/matching).
	 * @param SiteUrlNormalizer    $normalizer  The pure URL normalizer/hasher.
	 * @param LastKnownGood        $lkg         The last-known-good grace store.
	 */
	public function __construct(
		private readonly LicenseRepository $licenses,
		private readonly ActivationRepository $activations,
		private readonly LicenseService $licenseSvc,
		private readonly ProductService $products,
		private readonly SiteUrlNormalizer $normalizer,
		private readonly LastKnownGood $lkg
	) {
	}

	/**
	 * Validate a license key, optionally against a site and a claimed product.
	 *
	 * @since 1.0.0
	 *
	 * @param string                 $licenseKey  The presented license key.
	 * @param string|null            $siteUrl     The presenting site's URL, when the client sent one.
	 * @param string|null            $fingerprint The device fingerprint, when the client sent one.
	 * @param int|string|null        $product     The claimed product: an id (int) or a slug (string),
	 *                                            or null to skip the product match.
	 * @param DateTimeImmutable|null $now         The validation instant (tests inject).
	 * @return ValidationResult The canonical structured outcome.
	 *
	 * @throws LicensePersistenceException When the live check faults and no fresh
	 *                                     last-known-good answer exists (the client
	 *                                     then applies its own 72-hour grace).
	 */
	public function validate( string $licenseKey, ?string $siteUrl = null, ?string $fingerprint = null, $product = null, ?DateTimeImmutable $now = null ): ValidationResult {
		$now = $now ?? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );

		// The live check can fault on a licensing or a products table read (the
		// slug resolution makes the products table part of the sequence). A
		// fresh positive from the grace window is better than locking out a
		// paying customer over our own outage; with nothing cached, surface
		// the fault (HTTP 5xx) so the calling client's own grace takes over. Never
		// synthesize a negative here. The two catch arms exist to keep the
		// documented exception contract: callers catch the licensing
		// persistence type, whatever table actually faulted.
		try {
			return $this->liveValidate( $licenseKey, $siteUrl, $fingerprint, $product, $now );
		} catch ( LicensePersistenceException $fault ) {
			return $this->recallGrace( $licenseKey, $now ) ?? throw $fault;
		} catch ( \DrPlugin\WP\TierMint\Products\ProductPersistenceException $fault ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only literal; controllers map persistence failures to a generic message.
			return $this->recallGrace( $licenseKey, $now ) ?? throw new LicensePersistenceException( 'Product read faulted during validation: ' . $fault->getMessage(), 0, $fault );
		}
	}

	/**
	 * A last-known-good answer for the key, when the grace store holds one.
	 *
	 * @since 1.0.0
	 *
	 * @param string            $licenseKey The presented license key.
	 * @param DateTimeImmutable $now        When this (failed) validation ran.
	 * @return ValidationResult|null The from-cache result, or null with nothing remembered.
	 */
	private function recallGrace( string $licenseKey, DateTimeImmutable $now ): ?ValidationResult {
		$cached = $this->lkg->recall( $licenseKey );

		return null === $cached ? null : ValidationResult::fromCachedPayload( $cached, $now );
	}

	/**
	 * Run live validation so read faults use one grace fallback.
	 *
	 * @since 1.0.0
	 *
	 * @param string            $licenseKey  The presented license key.
	 * @param string|null       $siteUrl     The presenting site's URL, or null.
	 * @param string|null       $fingerprint The device fingerprint, or null.
	 * @param int|string|null   $product     The claimed product id/slug, or null.
	 * @param DateTimeImmutable $now         The validation instant.
	 * @return ValidationResult The canonical structured outcome.
	 *
	 * @throws LicensePersistenceException When a licensing read or write faults.
	 */
	private function liveValidate( string $licenseKey, ?string $siteUrl, ?string $fingerprint, $product, DateTimeImmutable $now ): ValidationResult {
		$license = $this->licenses->findByKey( $licenseKey );

		if ( null === $license ) {
			// Unknown keys are never cached in any layer; the rate limiter is
			// the defense against enumeration.
			return ValidationResult::invalid( $now );
		}

		$catalogProduct = $this->products->get( $license->productId() );
		$productSlug    = null !== $catalogProduct ? $catalogProduct->slug() : null;

		if ( ! $this->productMatches( $license, $productSlug, $product ) ) {
			return ValidationResult::keyMismatch( $now );
		}

		// Lazy expiry: a validate on a stale-active license flips it
		// inline; the daily cron is a sweeper, not a dependency.
		$license = $this->licenseSvc->expireLazily( $license, $now );

		$siteActive = $this->checkSite( $license, $siteUrl, $fingerprint, $now );

		$result = ValidationResult::forLicense( $license, $siteActive, $productSlug, $now );

		/**
		 * Fires after a license validation resolves.
		 *
		 * @since 1.0.0
		 *
		 * @param License              $license The validated license (post lazy-expiry).
		 * @param array<string, mixed> $request The request context: license_key, site_url, product.
		 */
		do_action(
			'tiermint_license_validated',
			$license,
			array(
				'license_key' => $licenseKey,
				'site_url'    => $siteUrl,
				'product'     => $product,
			)
		);

		if ( $result->isValid() ) {
			// Only positives enter the grace window: grace covers
			// unreachability, never revocation. The payload is stored
			// without the site check outcome: the entry is keyed per license,
			// so remembering one site's site_active would misreport every
			// other site of the same license during an outage.
			$payload = $result->toArray();
			unset( $payload['site_active'] );

			$this->lkg->remember( $licenseKey, $payload );
		}

		return $result;
	}

	/**
	 * Match an optional product id or slug to the license product.
	 *
	 * @since 1.0.0
	 *
	 * @param License         $license     The license.
	 * @param string|null     $productSlug The license's product slug, when resolvable.
	 * @param int|string|null $claimed     The request's claimed product, or null to skip.
	 * @return bool True when the claim matches (or none was made).
	 */
	private function productMatches( License $license, ?string $productSlug, $claimed ): bool {
		if ( null === $claimed || '' === $claimed ) {
			return true;
		}

		if ( is_int( $claimed ) ) {
			return $license->productId() === $claimed;
		}

		return null !== $productSlug && $productSlug === (string) $claimed;
	}

	/**
	 * Resolve the site's activation and update its throttled last-checked time.
	 *
	 * Invalid URLs report inactive.
	 *
	 * @since 1.0.0
	 *
	 * @param License           $license     The license.
	 * @param string|null       $siteUrl     The presented site URL, or null.
	 * @param string|null       $fingerprint The device fingerprint, or null.
	 * @param DateTimeImmutable $now         The validation instant.
	 * @return bool|null The site check outcome, or null when no site was presented.
	 */
	private function checkSite( License $license, ?string $siteUrl, ?string $fingerprint, DateTimeImmutable $now ): ?bool {
		if ( null === $siteUrl || '' === trim( $siteUrl ) ) {
			return null;
		}

		try {
			$normalized = $this->normalizer->normalize( $siteUrl );
		} catch ( LicenseValidationException $e ) {
			return false;
		}

		$activation = $this->activations->findByLicenseAndHash(
			(int) $license->id(),
			$this->normalizer->hash( $normalized, $fingerprint )
		);

		if ( null === $activation || ! $activation->isActive() ) {
			return false;
		}

		// The one write on the hot path, throttled inside its own UPDATE guard
		// so validation stays effectively read-only. Contained: this
		// is a telemetry stamp, and a fault in it must not discard the fully
		// computed validation result (nor trip the grace fallback).
		try {
			$this->activations->touchLastChecked( (int) $activation->id(), $now->format( 'Y-m-d H:i:s' ), self::TOUCH_THROTTLE_HOURS );
		} catch ( LicensePersistenceException $ignored ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Deliberate containment of a telemetry-only write fault.
			// Swallowed by design; the stamp retries on the next validation.
		}

		return true;
	}
}
