<?php
/**
 * Base exception for the Orders domain.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Orders;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use InvalidArgumentException;

/**
 * Base exception for invalid order data.
 *
 * @since 1.0.0
 */
class OrderException extends InvalidArgumentException {
}
