<?php
/**
 * Exception for a forbidden release status transition.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Updates;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Raised when a release status change is not allowed.
 *
 * @since 1.0.0
 */
final class ReleaseTransitionException extends ReleaseException {

	/**
	 * Builds a rejected-transition failure.
	 *
	 * @since 1.0.0
	 *
	 * @param ReleaseStatus $from The release's current status.
	 * @param ReleaseStatus $to   The status the transition tried to reach.
	 * @return self
	 */
	public static function between( ReleaseStatus $from, ReleaseStatus $to ): self {
		/* translators: 1: the release's current status, 2: the requested release status. */
		return new self( sprintf( __( 'Cannot change release status from "%1$s" to "%2$s".', 'tiermint' ), $from->value, $to->value ) );
	}
}
