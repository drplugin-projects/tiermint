<?php
/**
 * Base exception for the Payments layer.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Payments;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use RuntimeException;

/**
 * Base exception for payment runtime failures.
 *
 * @since 1.0.0
 */
class PaymentException extends RuntimeException {
}
