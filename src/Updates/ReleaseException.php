<?php
/**
 * Base exception for the Updates (Releases) domain.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Updates;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use InvalidArgumentException;

/**
 * Base exception for release rules.
 *
 * @since 1.0.0
 */
class ReleaseException extends InvalidArgumentException {
}
