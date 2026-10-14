<?php
/**
 * Contract for classes that attach to WordPress hooks.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Core;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Anything that attaches to WordPress hooks.
 *
 * @since 1.0.0
 */
interface Hookable {

	/**
	 * Register this class's WordPress hooks.
	 *
	 * Keep hook registration out of constructors, so tests do not require WordPress.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function hooks(): void;
}
