<?php
/**
 * Base exception for the management API key domain.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\REST\Auth;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Base exception for API key failures.
 *
 * @since 1.0.0
 */
class ApiKeyException extends \InvalidArgumentException {
}
