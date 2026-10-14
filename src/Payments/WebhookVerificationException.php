<?php
/**
 * Exception for an inbound webhook that fails verification.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Payments;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Raised when a gateway cannot authenticate a webhook. The receiver returns a
 * generic response.
 *
 * @since 1.0.0
 */
final class WebhookVerificationException extends PaymentException {
}
