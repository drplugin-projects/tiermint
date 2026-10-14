<?php
/**
 * Exception for release persistence (database write) failures.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Updates;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Raised when release data cannot be stored.
 *
 * @since 1.0.0
 */
class ReleasePersistenceException extends ReleaseException {
}
