<?php
/**
 * Exception for customer persistence (database write) failures.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Customers;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Raised when {@see CustomerRepository} cannot write customer data.
 * REST returns a generic server error and logs the internal detail.
 *
 * @since 1.0.0
 */
final class CustomerPersistenceException extends CustomerException {
}
