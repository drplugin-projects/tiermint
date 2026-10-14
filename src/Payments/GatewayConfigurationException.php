<?php
/**
 * Exception for an unconfigured or misconfigured payment gateway.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Payments;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Raised when required gateway settings are unavailable.
 *
 * Controllers map it to a generic availability error.
 *
 * @since 1.0.0
 */
final class GatewayConfigurationException extends PaymentException {
}
