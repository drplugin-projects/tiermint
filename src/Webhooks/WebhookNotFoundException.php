<?php
/**
 * Exception for operations on an unknown webhook endpoint.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Webhooks;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Thrown when an endpoint update or delete has no target.
 *
 * Management screens map this exception to a 404 response.
 *
 * @since 1.0.0
 */
final class WebhookNotFoundException extends WebhookException {
}
