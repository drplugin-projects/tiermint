<?php
/**
 * Exception for product persistence (database write) failures.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Products;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Product repository write failure.
 *
 * REST returns a generic server error for this exception.
 *
 * @since 1.0.0
 */
final class ProductPersistenceException extends ProductException {
}
