<?php
/**
 * Exception for term persistence (database write) failures.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Products;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Raised when a term database write fails. Controllers must not expose its detail.
 *
 * @since 1.0.0
 */
final class TermPersistenceException extends TermException {
}
