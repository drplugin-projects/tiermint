<?php
/**
 * Release status transition guard.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Updates;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Checks whether a release status change is allowed.
 *
 * @since 1.0.0
 */
final class ReleaseStateMachine {

	/**
	 * Allowed target statuses by current status.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const TRANSITIONS = array(
		'draft'     => array( 'published' ),
		'published' => array( 'yanked' ),
		'yanked'    => array( 'published' ),
	);

	/**
	 * Whether a release may move from one status to another under the transition rules.
	 *
	 * @since 1.0.0
	 *
	 * @param ReleaseStatus $from The release's current status.
	 * @param ReleaseStatus $to   The status the release wants to reach.
	 * @return bool True when the move is allowed.
	 */
	public function allows( ReleaseStatus $from, ReleaseStatus $to ): bool {
		return in_array( $to->value, self::TRANSITIONS[ $from->value ] ?? array(), true );
	}

	/**
	 * Lists source statuses for a compare-and-swap status update.
	 *
	 * @since 1.0.0
	 *
	 * @param ReleaseStatus $to The target status.
	 * @return array<int, ReleaseStatus> The statuses that may transition into $to.
	 */
	public function sourcesFor( ReleaseStatus $to ): array {
		$sources = array();

		foreach ( self::TRANSITIONS as $from => $targets ) {
			if ( in_array( $to->value, $targets, true ) ) {
				$sources[] = ReleaseStatus::from( $from );
			}
		}

		return $sources;
	}

	/**
	 * Assert that a transition is allowed, throwing when it is not.
	 *
	 * @since 1.0.0
	 *
	 * @param ReleaseStatus $from The release's current status.
	 * @param ReleaseStatus $to   The status the release wants to reach.
	 * @return void
	 *
	 * @throws ReleaseTransitionException When the move is not allowed.
	 */
	public function assertAllowed( ReleaseStatus $from, ReleaseStatus $to ): void {
		if ( ! $this->allows( $from, $to ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw ReleaseTransitionException::between( $from, $to );
		}
	}
}
