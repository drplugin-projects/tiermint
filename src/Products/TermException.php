<?php
/**
 * Base exception for the product taxonomy (terms) domain.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Products;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use InvalidArgumentException;

/**
 * Product term invariant failure.
 *
 * @since 1.0.0
 */
class TermException extends InvalidArgumentException {
}
