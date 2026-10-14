<?php
/**
 * Release lifecycle status enum.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Updates;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Stored release states. {@see ReleaseStateMachine} controls changes.
 *
 * @since 1.0.0
 */
enum ReleaseStatus: string {

	/**
	 * Editable and not served to clients.
	 */
	case Draft = 'draft';

	/**
	 * Served to update clients.
	 */
	case Published = 'published';

	/**
	 * Hidden from update clients.
	 */
	case Yanked = 'yanked';
}
