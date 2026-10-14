<?php
/**
 * Base exception for the Reports domain.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Reports;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use InvalidArgumentException;

/**
 * Base report exception.
 *
 * Controllers map validation and persistence failures differently.
 *
 * @since 1.0.0
 */
class ReportException extends InvalidArgumentException {
}
