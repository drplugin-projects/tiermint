<?php
/**
 * Exception for an advisory lock that could not be acquired.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Database;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Advisory-lock failure.
 *
 * @since 1.0.0
 */
final class AdvisoryLockException extends \RuntimeException {
}
