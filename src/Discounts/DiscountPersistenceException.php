<?php
/**
 * Exception for discount persistence (database write/read) failures.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Discounts;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Thrown when a {@see DiscountRepository} database operation fails.
 *
 * REST controllers return HTTP 500 without its server-only details.
 *
 * @since 1.0.0
 */
final class DiscountPersistenceException extends DiscountException {
}
