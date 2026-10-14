<?php
/**
 * Per-user, per-site record of which upsell notices have been dismissed.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Upsell;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Stores dismissed upsell topics in a per-site user option.
 *
 * The set of topics is closed, so a write can never fill the option with
 * arbitrary strings. Uninstall removes the option; see `uninstall.php`.
 *
 * @since 1.0.0
 */
class Dismissals {

	/**
	 * The user-option key. `update_user_option` prefixes it per site.
	 *
	 * @var string
	 */
	public const OPTION_KEY = 'tiermint_upsell_dismissed';

	/**
	 * Every topic that may be dismissed.
	 *
	 * @var array<int, string>
	 */
	public const TOPICS = array( 'intro' );

	/**
	 * The topics the current user has dismissed on this site.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, string> The dismissed topic keys.
	 */
	public function topics(): array {
		$userId = get_current_user_id();
		if ( 0 === $userId ) {
			return array();
		}

		$stored = get_user_option( self::OPTION_KEY, $userId );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		// Keep only topics the code still knows about.
		return array_values( array_intersect( self::TOPICS, array_map( 'strval', $stored ) ) );
	}

	/**
	 * Whether a topic is dismissed for the current user.
	 *
	 * @since 1.0.0
	 *
	 * @param string $topic The topic key.
	 * @return bool True when dismissed.
	 */
	public function isDismissed( string $topic ): bool {
		return in_array( $topic, $this->topics(), true );
	}

	/**
	 * Record a topic as dismissed for the current user. Idempotent.
	 *
	 * @since 1.0.0
	 *
	 * @param string $topic The topic key, one of TOPICS.
	 * @return bool True when the topic is dismissed after the call.
	 */
	public function dismiss( string $topic ): bool {
		if ( ! in_array( $topic, self::TOPICS, true ) ) {
			return false;
		}

		$userId = get_current_user_id();
		if ( 0 === $userId ) {
			return false;
		}

		$topics = $this->topics();
		if ( in_array( $topic, $topics, true ) ) {
			return true;
		}

		$topics[] = $topic;
		update_user_option( $userId, self::OPTION_KEY, array_values( $topics ), false );

		return true;
	}
}
