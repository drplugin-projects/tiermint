<?php
/**
 * Base exception for the Customers domain.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Customers;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use InvalidArgumentException;

/**
 * Base exception for customer-domain errors.
 *
 * @since 1.0.0
 */
class CustomerException extends InvalidArgumentException {
}
