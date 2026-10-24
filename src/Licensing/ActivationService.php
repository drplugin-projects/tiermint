<?php
/**
 * Activation domain service: activate, deactivate, limits, transfer.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Licensing;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DateTimeImmutable;
use DateTimeZone;
use DrPlugin\WP\TierMint\Database\Logger;

/**
 * Apply activation limits and site transfer rules.
 *
 * @since 1.0.0
 */
class ActivationService {

	/**
	 * Wire the service with its collaborators.
	 *
	 * @since 1.0.0
	 *
	 * @param LicenseRepository    $licenses    The license repository (slot claims).
	 * @param ActivationRepository $activations The activation repository.
	 * @param LicenseService       $licenseSvc  The license service (lazy expiry check).
	 * @param SiteUrlNormalizer    $normalizer  The pure URL normalizer/hasher.
	 * @param Logger               $logger      The `_logs` audit writer.
	 */
	public function __construct(
		private readonly LicenseRepository $licenses,
		private readonly ActivationRepository $activations,
		private readonly LicenseService $licenseSvc,
		private readonly SiteUrlNormalizer $normalizer,
		private readonly Logger $logger
	) {
	}

	// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber -- The validation and limit exceptions propagate from the guard helpers; documenting them here is API-critical.
	/**
	 * Activate a site for a license.
	 *
	 * Existing active sites are idempotent. Local sites do not use a slot.
	 *
	 * @since 1.0.0
	 *
	 * @param License                   $license     The license to activate against.
	 * @param string                    $siteUrl     The site URL as presented by the client.
	 * @param string|null               $fingerprint Optional device fingerprint (participates in identity).
	 * @param array<string, mixed>|null $environment Optional client environment report.
	 * @param DateTimeImmutable|null    $now         The activation instant (tests inject).
	 * @return Activation The active activation for the site.
	 *
	 * @throws LicenseValidationException  When the license is expired or disabled, or the URL is invalid.
	 * @throws ActivationLimitException    When every activation slot is taken.
	 * @throws LicensePersistenceException When a write faults at the database.
	 */
	public function activate( License $license, string $siteUrl, ?string $fingerprint = null, ?array $environment = null, ?DateTimeImmutable $now = null ): Activation {
		$now     = $now ?? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$license = $this->licenseSvc->expireLazily( $license, $now );

		$this->assertActivatable( $license );

		$normalized = $this->normalizer->normalize( $siteUrl );
		$hash       = $this->normalizer->hash( $normalized, $fingerprint );
		$isLocal    = $this->normalizer->isLocal( $normalized );
		$licenseId  = (int) $license->id();

		$existing = $this->activations->findByLicenseAndHash( $licenseId, $hash );

		if ( null !== $existing && $existing->isActive() ) {
			// The site is already active. Do not claim another slot.
			return $existing;
		}

		if ( null !== $existing ) {
			return $this->reactivate( $license, $existing, $environment, $now );
		}

		// Claim before writing, so concurrent requests cannot exceed the limit.
		$claimed = $this->claimSlotUnlessLocal( $license, $isLocal );

		$activation = new Activation(
			null,
			$licenseId,
			$normalized,
			$hash,
			$fingerprint,
			ActivationStatus::Active,
			$isLocal,
			$environment,
			$now,
			null,
			null
		);

		try {
			$id = $this->activations->create( $activation );
		} catch ( DuplicateActivationException $race ) {
			// Another request created it. Release this claim and use that row.
			if ( $claimed ) {
				$this->licenses->releaseActivationSlot( $licenseId );
			}

			$rival = $this->activations->findByLicenseAndHash( $licenseId, $hash );

			if ( null !== $rival && $rival->isActive() ) {
				return $rival;
			}

			if ( null !== $rival ) {
				return $this->reactivate( $license, $rival, $environment, $now );
			}

			throw $race;
		} catch ( \Throwable $failure ) {
			// Release a claimed slot if the activation write fails.
			if ( $claimed ) {
				$this->licenses->releaseActivationSlot( $licenseId );
			}

			throw $failure;
		}

		$fresh = $this->reloadActivation( $id, $activation );

		$this->fireActivated( $license, $fresh );

		return $fresh;
	}
	// phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber

	// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber -- The persistence and validation exceptions propagate from the collaborators; documenting them here is API-critical.
	/**
	 * Deactivate a site and free its non-local slot.
	 *
	 * @since 1.0.0
	 *
	 * @param License                $license     The license the site is activated for.
	 * @param string                 $siteUrl     The site URL as presented by the client.
	 * @param string|null            $fingerprint Optional device fingerprint (part of the identity).
	 * @param DateTimeImmutable|null $now         The deactivation instant (tests inject).
	 * @return Activation The deactivated activation.
	 *
	 * @throws ActivationNotFoundException When the license has no activation for the site.
	 * @throws LicenseValidationException  When the URL is invalid.
	 * @throws LicensePersistenceException When a write faults at the database.
	 */
	public function deactivate( License $license, string $siteUrl, ?string $fingerprint = null, ?DateTimeImmutable $now = null ): Activation {
		$now        = $now ?? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$normalized = $this->normalizer->normalize( $siteUrl );
		$hash       = $this->normalizer->hash( $normalized, $fingerprint );
		$licenseId  = (int) $license->id();

		$existing = $this->activations->findByLicenseAndHash( $licenseId, $hash );

		if ( null === $existing ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw ActivationNotFoundException::forSite( $normalized );
		}

		if ( ! $existing->isActive() ) {
			// It is already deactivated. Do not release a slot twice.
			return $existing;
		}

		// Only the winning request releases the slot and fires the hook.
		$won = $this->activations->markDeactivated( $existing, $now->format( 'Y-m-d H:i:s' ) );

		if ( ! $won ) {
			// Another request completed it. Do not release the slot twice.
			return $this->activations->findByLicenseAndHash( $licenseId, $hash )
				?? $existing->withStatus( ActivationStatus::Deactivated )->withDeactivatedAt( $now );
		}

		// Only counted activations return a slot; local installs never took one.
		if ( ! $existing->isLocal() ) {
			$this->licenses->releaseActivationSlot( $licenseId );
		}

		$fresh = $this->reloadActivation( (int) $existing->id(), $existing->withStatus( ActivationStatus::Deactivated )->withDeactivatedAt( $now ) );

		/**
		 * Fires after an activation was released (its limit slot freed).
		 *
		 * @since 1.0.0
		 *
		 * @param License    $license The license the site was activated for.
		 * @param Activation $fresh   The deactivated activation.
		 */
		do_action( 'tiermint_license_deactivated', $license, $fresh );

		$this->logger->log(
			'license',
			sprintf( 'Site %s deactivated for license %d.', $fresh->siteUrl(), $licenseId ),
			array( 'activation_id' => (int) $fresh->id() ),
			'license',
			$licenseId
		);

		return $fresh;
	}
	// phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber

	/**
	 * List a license's activations, newest first.
	 *
	 * @since 1.0.0
	 *
	 * @param License               $license The license.
	 * @param ActivationStatus|null $status  Only activations in this status, or null for all.
	 * @return array<int, Activation> The activations.
	 */
	public function listForLicense( License $license, ?ActivationStatus $status = null ): array {
		return $this->activations->listForLicense( (int) $license->id(), $status );
	}

	// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber -- The limit exception propagates from the claim helper; documenting it here is API-critical.

	/**
	 * Reactivate a saved site activation.
	 *
	 * @since 1.0.0
	 *
	 * @param License                   $license     The license.
	 * @param Activation                $existing    The deactivated row to reuse.
	 * @param array<string, mixed>|null $environment The fresh environment report, when sent.
	 * @param DateTimeImmutable         $now         The activation instant.
	 * @return Activation The reactivated activation.
	 *
	 * @throws ActivationLimitException When every activation slot is taken.
	 * @throws \Throwable               A write fault, rethrown after the claimed slot
	 *                                  is compensated back.
	 */
	private function reactivate( License $license, Activation $existing, ?array $environment, DateTimeImmutable $now ): Activation {
		$claimed = $this->claimSlotUnlessLocal( $license, $existing->isLocal() );

		try {
			// Only one request may reactivate the saved row.
			$won = $this->activations->markReactivated( $existing, $now->format( 'Y-m-d H:i:s' ), $environment );
		} catch ( \Throwable $failure ) {
			// Release a claimed slot if the reactivation write fails.
			if ( $claimed ) {
				$this->licenses->releaseActivationSlot( (int) $license->id() );
			}

			throw $failure;
		}

		if ( ! $won ) {
			// Another request reactivated it. Release this claim and reuse its row.
			if ( $claimed ) {
				$this->licenses->releaseActivationSlot( (int) $license->id() );
			}

			return $this->activations->findByLicenseAndHash( $existing->licenseId(), $existing->siteHash() ) ?? $existing;
		}

		$fresh = $this->reloadActivation( (int) $existing->id(), $existing->withStatus( ActivationStatus::Active )->withDeactivatedAt( null ) );

		$this->fireActivated( $license, $fresh );

		return $fresh;
	}

	// phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber

	/**
	 * Claim a limit slot unless the activation is local.
	 *
	 * @since 1.0.0
	 *
	 * @param License $license The license to claim against.
	 * @param bool    $isLocal Whether the activating site is local (exempt).
	 * @return bool True when a slot was actually claimed (and so must be
	 *              released if the follow-up write fails).
	 *
	 * @throws ActivationLimitException When the license is at its limit.
	 */
	private function claimSlotUnlessLocal( License $license, bool $isLocal ): bool {
		if ( $isLocal ) {
			return false;
		}

		if ( ! $this->licenses->claimActivationSlot( (int) $license->id() ) ) {
			throw new ActivationLimitException( (int) $license->activationLimit() );
		}

		return true;
	}

	/**
	 * Reject expired and disabled licenses.
	 *
	 * @since 1.0.0
	 *
	 * @param License $license The (lazily expiry-checked) license.
	 * @return void
	 *
	 * @throws LicenseValidationException When the license is expired or disabled.
	 */
	private function assertActivatable( License $license ): void {
		if ( LicenseStatus::Expired === $license->status() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new LicenseValidationException( __( 'This license has expired. To activate new sites, buy a new license and use its key.', 'tiermint' ) );
		}

		if ( LicenseStatus::Disabled === $license->status() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new LicenseValidationException( __( 'This license has been disabled and cannot be activated.', 'tiermint' ) );
		}
	}

	/**
	 * Fire the activation hook and write its audit entry.
	 *
	 * @since 1.0.0
	 *
	 * @param License    $license    The license.
	 * @param Activation $activation The now-active activation.
	 * @return void
	 */
	private function fireActivated( License $license, Activation $activation ): void {
		/**
		 * Fires after a site activation is recorded.
		 *
		 * @since 1.0.0
		 *
		 * @param License    $license    The license the site activated against.
		 * @param Activation $activation The active activation.
		 */
		do_action( 'tiermint_license_activated', $license, $activation );

		$this->logger->log(
			'license',
			sprintf( 'Site %s activated for license %d.', $activation->siteUrl(), (int) $license->id() ),
			array(
				'activation_id' => (int) $activation->id(),
				'is_local'      => $activation->isLocal(),
			),
			'license',
			(int) $license->id()
		);
	}

	/**
	 * Reload an activation, or use the just-written entity.
	 *
	 * @since 1.0.0
	 *
	 * @param int        $id       The activation id.
	 * @param Activation $fallback The entity as this request wrote it.
	 * @return Activation The fresh entity, or the fallback.
	 */
	private function reloadActivation( int $id, Activation $fallback ): Activation {
		return $this->activations->find( $id ) ?? $fallback;
	}
}
