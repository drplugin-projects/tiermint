<?php
/**
 * Base exception for the Products domain.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Products;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use InvalidArgumentException;

/**
 * Product domain invariant failure.
 *
 * @since 1.0.0
 */
class ProductException extends InvalidArgumentException {
}
