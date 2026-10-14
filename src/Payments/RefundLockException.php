<?php
/**
 * Exception for a per-order refund lock that could not be acquired.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Payments;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Raised when another refund holds the order lock. Controllers return a generic
 * client response, and webhooks retry.
 *
 * @since 1.0.0
 */
final class RefundLockException extends PaymentException {
}
