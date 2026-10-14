<?php
/**
 * Base exception for the team-seat surface.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Licensing;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use RuntimeException;

/**
 * Thrown when a team-seat operation cannot proceed.
 *
 * @since 1.0.0
 */
class SeatException extends RuntimeException {
}
