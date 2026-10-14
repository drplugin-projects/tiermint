<?php
/**
 * Base exception for the Discounts domain.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Discounts;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use InvalidArgumentException;

/**
 * Base exception for invalid discount data and state.
 *
 * @since 1.0.0
 */
class DiscountException extends InvalidArgumentException {
}
