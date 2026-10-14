<?php
/**
 * Exception for report persistence (database read) failures.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Reports;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Report database failure.
 *
 * Controllers return a generic server error for this exception.
 *
 * @since 1.0.0
 */
final class ReportPersistenceException extends ReportException {
}
