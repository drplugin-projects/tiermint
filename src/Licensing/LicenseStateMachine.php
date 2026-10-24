<?php
/**
 * License status transition guard.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Licensing;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Checks valid license status transitions.
 *
 * It has no state and is safe to share.
 *
 * @since 1.0.0
 */
final class LicenseStateMachine {

	/**
	 * Allowed status changes.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const TRANSITIONS = array(
		'active'   => array( 'expired', 'disabled' ),
		'expired'  => array( 'active', 'disabled' ),
		'disabled' => array( 'active', 'expired' ),
	);

	/**
	 * Whether a license may move from one status to another under the transition rules.
	 *
	 * @since 1.0.0
	 *
	 * @param LicenseStatus $from The license's current status.
	 * @param LicenseStatus $to   The status the license wants to reach.
	 * @return bool True when the move is allowed.
	 */
	public function allows( LicenseStatus $from, LicenseStatus $to ): bool {
		return in_array( $to->value, self::TRANSITIONS[ $from->value ] ?? array(), true );
	}

	/**
	 * List statuses that can move to a target.
	 *
	 * Compare-and-swap writes use this list.
	 *
	 * @since 1.0.0
	 *
	 * @param LicenseStatus $to The target status.
	 * @return array<int, LicenseStatus> The statuses that may transition into $to.
	 */
	public function sourcesFor( LicenseStatus $to ): array {
		$sources = array();

		foreach ( self::TRANSITIONS as $from => $targets ) {
			if ( in_array( $to->value, $targets, true ) ) {
				$sources[] = LicenseStatus::from( $from );
			}
		}

		return $sources;
	}

	/**
	 * Assert that a transition is allowed, throwing when it is not.
	 *
	 * @since 1.0.0
	 *
	 * @param LicenseStatus $from The license's current status.
	 * @param LicenseStatus $to   The status the license wants to reach.
	 * @return void
	 *
	 * @throws LicenseTransitionException When the move is not allowed.
	 */
	public function assertAllowed( LicenseStatus $from, LicenseStatus $to ): void {
		if ( ! $this->allows( $from, $to ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw LicenseTransitionException::between( $from, $to );
		}
	}
}
