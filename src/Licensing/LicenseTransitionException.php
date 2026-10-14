<?php
/**
 * Exception for a forbidden license status transition.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Licensing;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Thrown when {@see LicenseStateMachine} rejects a status change.
 *
 * @since 1.0.0
 */
final class LicenseTransitionException extends LicenseException {

	/**
	 * Build the exception describing the rejected transition.
	 *
	 * @since 1.0.0
	 *
	 * @param LicenseStatus $from The license's current status.
	 * @param LicenseStatus $to   The status the transition tried to reach.
	 * @return self
	 */
	public static function between( LicenseStatus $from, LicenseStatus $to ): self {
		/* translators: 1: the license's current status, 2: the requested license status. */
		return new self( sprintf( __( 'Cannot change license status from "%1$s" to "%2$s".', 'tiermint' ), $from->value, $to->value ) );
	}
}
