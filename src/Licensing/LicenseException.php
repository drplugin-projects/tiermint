<?php
/**
 * Base exception for the Licensing domain.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Licensing;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use InvalidArgumentException;

/**
 * Base exception for licensing rules.
 *
 * @since 1.0.0
 */
class LicenseException extends InvalidArgumentException {
}
