<?php
/**
 * Update-check payload service.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Updates;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DateTimeImmutable;
use DateTimeZone;
use DrPlugin\WP\TierMint\Licensing\LicensePersistenceException;
use DrPlugin\WP\TierMint\Licensing\LicenseRepository;
use DrPlugin\WP\TierMint\Licensing\LicenseValidationService;
use DrPlugin\WP\TierMint\Licensing\SiteUrlNormalizer;
use DrPlugin\WP\TierMint\Licensing\ValidationResult;
use DrPlugin\WP\TierMint\Products\Product;
use DrPlugin\WP\TierMint\Products\ProductNotFoundException;
use DrPlugin\WP\TierMint\Products\ProductRepository;

/**
 * Builds update-check responses.
 *
 * Invalid licenses receive no package URL.
 *
 * @since 1.0.0
 */
class UpdateService {

	/**
	 * Wire the assembler with its collaborators.
	 *
	 * @since 1.0.0
	 *
	 * @param ProductRepository        $products   Product reads (slug resolution).
	 * @param ReleaseRepository        $releases   Release reads (latest published).
	 * @param LicenseValidationService $validator  The license validation service.
	 * @param LicenseRepository        $licenses   License reads (row id for the signed URL).
	 * @param SiteUrlNormalizer        $normalizer Site URL normalization and hashing.
	 * @param ChangelogRenderer        $renderer   Markdown changelog to sanitized HTML.
	 * @param PackageSigner            $signer     Signed download URL minting.
	 */
	public function __construct(
		private readonly ProductRepository $products,
		private readonly ReleaseRepository $releases,
		private readonly LicenseValidationService $validator,
		private readonly LicenseRepository $licenses,
		private readonly SiteUrlNormalizer $normalizer,
		private readonly ChangelogRenderer $renderer,
		private readonly PackageSigner $signer
	) {
	}

	// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber -- The persistence exception propagates from the validation service; documenting it here is API-critical.
	/**
	 * Assemble the update-check payload for a product, license, and site.
	 *
	 * @since 1.0.0
	 *
	 * @param string                 $productSlug      The product's catalog slug (the licensing identifier).
	 * @param string                 $licenseKey       The presented license key.
	 * @param string|null            $siteUrl          The presenting site's URL; without it no package URL can be minted.
	 * @param string|null            $installedVersion The client's installed version, for the update_available verdict.
	 * @param DateTimeImmutable|null $now              The check instant (tests inject).
	 * @param array<string, mixed>   $params           The request's arguments as received, passed to the
	 *                                                 `tiermint_update_check_release` filter.
	 * @return array<string, mixed> The assembled payload, after the `tiermint_update_check_response` filter.
	 *
	 * @throws ProductNotFoundException    When no product carries the slug (controller: generic 404).
	 * @throws LicensePersistenceException When license validation faults with nothing
	 *                                     in the grace store (controller: generic 500,
	 *                                     the client's own 72-hour grace takes over).
	 */
	public function check( string $productSlug, string $licenseKey, ?string $siteUrl, ?string $installedVersion, ?DateTimeImmutable $now = null, array $params = array() ): array {
		$now     = $now ?? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$product = $this->products->findBySlug( $productSlug );

		if ( null === $product ) {
			throw new ProductNotFoundException( 'No product for update-check slug.' );
		}

		$result   = $this->validator->validate( $licenseKey, $siteUrl, null, $productSlug, $now );
		$siteHash = $this->siteHash( $siteUrl );

		// Core serves its latest published release unless an extension changes it.
		$latest = $this->releases->latestPublished( (int) $product->id() );

		/**
		 * Filters the release served by update-check.
		 *
		 * Return any release of the product that is not yanked, or null for the no-update
		 * response. `params` is raw request input: it holds the license key and any argument
		 * the client sent. Sanitize what you read and never log the context.
		 *
		 * @since 1.0.0
		 *
		 * @param Release|null         $release The release core would serve (its latest published).
		 * @param array<string, mixed> $context Context: `product` (Product), `params` (array, the
		 *                                      request's arguments as received), `site_hash`
		 *                                      (string|null, a stable hash of the site URL),
		 *                                      `installed_version` (string|null), `result`
		 *                                      (ValidationResult).
		 */
		$release = apply_filters(
			'tiermint_update_check_release',
			$latest,
			array(
				'product'           => $product,
				'params'            => $params,
				'site_hash'         => $siteHash,
				'installed_version' => $installedVersion,
				'result'            => $result,
			)
		);

		$payload = $this->assemble( $product, $release, $result, $licenseKey, $siteUrl, $installedVersion );

		/**
		 * Filters the completed update-check response.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $payload The assembled response payload.
		 * @param array<string, mixed> $context Context: `product` (Product), `release` (Release|null, the
		 *                                      served release), `result` (ValidationResult), `site_hash`
		 *                                      (string|null), `installed_version` (string|null).
		 */
		return apply_filters(
			'tiermint_update_check_response',
			$payload,
			array(
				'product'           => $product,
				'release'           => $release,
				'result'            => $result,
				'site_hash'         => $siteHash,
				'installed_version' => $installedVersion,
			)
		);
	}
	// phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber

	/**
	 * Build the raw payload for a resolved product, release, and verdict.
	 *
	 * @param Product          $product          The catalog product.
	 * @param Release|null     $release          The release to serve, if any.
	 * @param ValidationResult $result           The license verdict.
	 * @param string           $licenseKey       The presented license key.
	 * @param string|null      $siteUrl          The presenting site's URL.
	 * @param string|null      $installedVersion The client's installed version.
	 * @return array<string, mixed> The unfiltered payload.
	 */
	private function assemble( Product $product, ?Release $release, ValidationResult $result, string $licenseKey, ?string $siteUrl, ?string $installedVersion ): array {
		$payload = array(
			'name' => $product->name(),
			'slug' => $product->updateSlug() ?? $product->slug(),
		);

		if ( null === $release ) {
			// Keep the license verdict when no release exists.
			$payload['update_available'] = false;
			$payload['license']          = $this->licenseBlock( $result );

			return $payload;
		}

		$payload['new_version']  = $release->version();
		$payload['requires']     = $release->requiresWp();
		$payload['tested']       = $release->testedUpTo();
		$payload['requires_php'] = $release->requiresPhp();
		$payload['last_updated'] = null === $release->releasedAt()
			? null
			: $release->releasedAt()->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );

		// Offer the update when the installed version is unknown.
		$payload['update_available'] = null === $installedVersion || '' === $installedVersion
			? true
			: version_compare( $release->version(), $installedVersion, '>' );

		$payload['sections'] = array(
			'changelog' => $this->renderer->renderCached( (int) $release->id(), $release->changelog() ?? '' ),
		);

		$payload['license'] = $this->licenseBlock( $result );

		if ( ! $result->isValid() ) {
			$payload['upgrade_notice'] = 'expired' === $result->status()
				? __( 'Your license has expired. To receive this update, buy a new license and use its key.', 'tiermint' )
				: __( 'A valid license is required to receive this update.', 'tiermint' );

			return $payload;
		}

		$package = $this->packageUrl( $product, $release, $licenseKey, $siteUrl );

		if ( null !== $package ) {
			$payload['package'] = $package;
		}

		return $payload;
	}

	/**
	 * The two-field license block every response carries.
	 *
	 * @param ValidationResult $result The license verdict.
	 * @return array{valid: bool, status: string} The block.
	 */
	private function licenseBlock( ValidationResult $result ): array {
		return array(
			'valid'  => $result->isValid(),
			'status' => $result->status(),
		);
	}

	/**
	 * Mint a signed package URL for a valid license.
	 *
	 * @param Product     $product    The catalog product.
	 * @param Release     $release    The release being granted.
	 * @param string      $licenseKey The presented license key.
	 * @param string|null $siteUrl    The presenting site's URL.
	 * @return string|null The signed URL, or null when no grant can be minted.
	 */
	private function packageUrl( Product $product, Release $release, string $licenseKey, ?string $siteUrl ): ?string {
		$siteHash = $this->siteHash( $siteUrl );

		if ( null === $siteHash ) {
			return null;
		}

		try {
			$license = $this->licenses->findByKey( $licenseKey );
		} catch ( LicensePersistenceException $e ) {
			return null;
		}

		if ( null === $license || null === $license->id() ) {
			return null;
		}

		return $this->signer->packageDownloadUrl(
			(int) $product->id(),
			(int) $release->id(),
			(int) $license->id(),
			$siteHash
		);
	}

	/**
	 * Get a normalized site hash.
	 *
	 * @param string|null $siteUrl The presenting site's URL.
	 * @return string|null The 64-hex site hash, or null.
	 */
	private function siteHash( ?string $siteUrl ): ?string {
		if ( null === $siteUrl || '' === trim( $siteUrl ) ) {
			return null;
		}

		return $this->normalizer->hash( $this->normalizer->normalize( $siteUrl ) );
	}
}
