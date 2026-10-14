<?php
/**
 * Exception for order persistence (database write) failures.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Orders;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Database write failure.
 * REST controllers keep its details from clients.
 *
 * @since 1.0.0
 */
final class OrderPersistenceException extends OrderException {
}
