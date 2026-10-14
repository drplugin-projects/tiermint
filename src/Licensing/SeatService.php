<?php
/**
 * Team/organization seat lifecycle and the org-to-child cascades.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Licensing;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Core\Hookable;
use DrPlugin\WP\TierMint\Database\AdvisoryLock;
use DrPlugin\WP\TierMint\Database\Logger;
use DrPlugin\WP\TierMint\Orders\Order;

/**
 * Manages organization seats as child licenses.
 *
 * Cascades update child rows because validation does not read the parent.
 *
 * @since 1.0.0
 */
class SeatService implements Hookable {

	/**
	 * License meta flag marking an organization (seat-issuing) license.
	 *
	 * @var string
	 */
	public const META_IS_ORG = '_tiermint_is_org';

	/**
	 * License meta holding an organization's seat limit.
	 *
	 * @var string
	 */
	public const META_SEAT_LIMIT = '_tiermint_seat_limit';

	/**
	 * Meta flag for a revoked seat.
	 *
	 * @var string
	 */
	public const META_REVOKED = '_tiermint_seat_revoked';

	/**
	 * Wire the service with its collaborators.
	 *
	 * @since 1.0.0
	 *
	 * @param LicenseRepository $licenses   The license repository (child reads).
	 * @param LicenseService    $licenseSvc The license lifecycle service (mint/disable/enable/renew/expire).
	 * @param AdvisoryLock      $seatLock   The per-org lock serializing count-and-mint.
	 * @param Logger            $logger     The `_logs` audit writer.
	 */
	public function __construct(
		private readonly LicenseRepository $licenses,
		private readonly LicenseService $licenseSvc,
		private readonly AdvisoryLock $seatLock,
		private readonly Logger $logger
	) {
	}

	/**
	 * Register the org-to-child cascade listeners.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'tiermint_license_renewed', array( $this, 'onLicenseRenewed' ), 10, 2 );
		add_action( 'tiermint_license_expired', array( $this, 'onLicenseExpired' ), 10, 1 );
		add_action( 'tiermint_license_disabled', array( $this, 'onLicenseDisabled' ), 10, 1 );
		add_action( 'tiermint_license_enabled', array( $this, 'onLicenseEnabled' ), 10, 1 );
	}

	/**
	 * Mark a license as an organization license with a seat limit. Idempotent.
	 *
	 * @since 1.0.0
	 *
	 * @param License $org       The license to designate.
	 * @param int     $seatLimit The number of active seats it may issue.
	 * @return void
	 */
	public function markAsOrg( License $org, int $seatLimit ): void {
		update_metadata( 'tiermint_license', (int) $org->id(), self::META_IS_ORG, 1 );
		update_metadata( 'tiermint_license', (int) $org->id(), self::META_SEAT_LIMIT, max( 0, $seatLimit ) );
	}

	/**
	 * Whether a license is an organization (seat-issuing) license.
	 *
	 * @since 1.0.0
	 *
	 * @param License $license The license.
	 * @return bool True when it carries the org flag.
	 */
	public function isOrg( License $license ): bool {
		return (bool) get_metadata( 'tiermint_license', (int) $license->id(), self::META_IS_ORG, true );
	}

	/**
	 * Organization seat limit, or null when unset.
	 *
	 * @since 1.0.0
	 *
	 * @param License $org The organization license.
	 * @return int|null The seat limit, or null.
	 */
	public function seatLimit( License $org ): ?int {
		$stored = get_metadata( 'tiermint_license', (int) $org->id(), self::META_SEAT_LIMIT, true );

		return ( '' === $stored || null === $stored || false === $stored ) ? null : (int) $stored;
	}

	/**
	 * The organization's child seats, oldest first (the dashboard listing).
	 *
	 * @since 1.0.0
	 *
	 * @param License $org The organization license.
	 * @return array<int, License> The child licenses.
	 */
	public function seats( License $org ): array {
		return $this->licenses->findByParentId( (int) $org->id() );
	}

	/**
	 * Count assigned child licenses that are not revoked.
	 *
	 * @since 1.0.0
	 *
	 * @param License $org The organization license.
	 * @return int The used-seat count.
	 */
	public function usedSeats( License $org ): int {
		$used = 0;

		foreach ( $this->seats( $org ) as $child ) {
			if ( ! $this->isRevoked( $child ) ) {
				++$used;
			}
		}

		return $used;
	}

	// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber -- The seat-limit and persistence exceptions propagate from the locked closure and mintChild; documenting them here is API-critical.
	/**
	 * Mint an organization seat when a slot is available.
	 *
	 * An advisory lock protects concurrent invitations.
	 *
	 * @since 1.0.0
	 *
	 * @param License $org The organization license.
	 * @return License The freshly minted child seat.
	 *
	 * @throws SeatException               When the license is not an organization license.
	 * @throws SeatLimitException          When the organization is at its seat limit.
	 * @throws LicensePersistenceException When the child write faults.
	 */
	public function invite( License $org ): License {
		if ( ! $this->isOrg( $org ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured domain message, surfaced as an API error, never echoed as HTML.
			throw new SeatException( __( 'Only an organization license can issue seats.', 'tiermint' ) );
		}

		return $this->seatLock->withLock(
			(int) $org->id(),
			function () use ( $org ): License {
				$limit = $this->seatLimit( $org );

				// A null limit on an org license is a misconfiguration (designation
				// always sets both flags together); refuse rather than grant an
				// unlimited number of seats a merchant never sold.
				if ( null === $limit || $this->usedSeats( $org ) >= $limit ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured domain message, surfaced as an API error, never echoed as HTML.
					throw new SeatLimitException( null === $limit ? 0 : $limit );
				}

				$child = $this->licenseSvc->mintChild( $org );

				$this->logger->log(
					'license',
					sprintf( 'Seat %d invited for org license %d.', (int) $child->id(), (int) $org->id() ),
					array( 'parent_id' => (int) $org->id() ),
					'license',
					(int) $child->id()
				);

				return $child;
			}
		);
	}
	// phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber

	/**
	 * Revoke a seat and disable its child license.
	 *
	 * Keep the child linked so an organization re-enable does not restore it.
	 *
	 * @since 1.0.0
	 *
	 * @param License $child The seat (child) license to revoke.
	 * @return License The disabled child.
	 *
	 * @throws LicenseTransitionException When the child could not be disabled (a conflicting state).
	 */
	public function revoke( License $child ): License {
		$disabled = $this->licenseSvc->disable( $child, 'revoke' );

		update_metadata( 'tiermint_license', (int) $child->id(), self::META_REVOKED, 1 );

		$this->logger->log(
			'license',
			sprintf( 'Seat %d revoked.', (int) $child->id() ),
			array( 'parent_id' => $child->parentId() ),
			'license',
			(int) $child->id()
		);

		return $disabled;
	}

	/**
	 * Copy an organization renewal to non-revoked child licenses.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $license The renewed license (only acted on when it is an org).
	 * @param mixed $order   The driving renewal order, or null.
	 * @return void
	 */
	public function onLicenseRenewed( $license, $order = null ): void {
		if ( ! $license instanceof License || ! $this->isOrg( $license ) ) {
			return;
		}

		$driver = $order instanceof Order ? $order : null;

		foreach ( $this->seats( $license ) as $child ) {
			if ( LicenseStatus::Disabled === $child->status() ) {
				continue;
			}

			try {
				if ( null === $license->expiresAt() ) {
					$this->licenseSvc->renew( $child, null, $driver );
				} else {
					$this->licenseSvc->renewUntil( $child, $license->expiresAt(), $driver );
				}
			} catch ( LicenseTransitionException $raced ) {
				// A concurrent disable/revoke won the child's CAS; leave it be.
				continue;
			}
		}
	}

	/**
	 * Expire active child licenses after organization expiry.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $license The expired license (only acted on when it is an org).
	 * @return void
	 */
	public function onLicenseExpired( $license ): void {
		if ( ! $license instanceof License || ! $this->isOrg( $license ) ) {
			return;
		}

		foreach ( $this->seats( $license ) as $child ) {
			if ( LicenseStatus::Active === $child->status() ) {
				$this->licenseSvc->expireLazily( $child );
			}
		}
	}

	/**
	 * Disable child licenses when their organization is disabled.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $license The disabled license (only acted on when it is an org).
	 * @return void
	 */
	public function onLicenseDisabled( $license ): void {
		if ( ! $license instanceof License || ! $this->isOrg( $license ) ) {
			return;
		}

		foreach ( $this->seats( $license ) as $child ) {
			if ( LicenseStatus::Disabled !== $child->status() ) {
				$this->licenseSvc->disable( $child, 'org' );
			}
		}
	}

	/**
	 * Re-enable child licenses except revoked seats.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $license The re-enabled license (only acted on when it is an org).
	 * @return void
	 */
	public function onLicenseEnabled( $license ): void {
		if ( ! $license instanceof License || ! $this->isOrg( $license ) ) {
			return;
		}

		foreach ( $this->seats( $license ) as $child ) {
			if ( LicenseStatus::Disabled === $child->status() && ! $this->isRevoked( $child ) ) {
				$this->licenseSvc->enable( $child );
			}
		}
	}

	/**
	 * Whether a child seat is revoked.
	 *
	 * @since 1.0.0
	 *
	 * @param License $child The child license.
	 * @return bool True when the revoked flag is set.
	 */
	public function isRevoked( License $child ): bool {
		return (bool) get_metadata( 'tiermint_license', (int) $child->id(), self::META_REVOKED, true );
	}
}
