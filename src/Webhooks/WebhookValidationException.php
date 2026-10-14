<?php
/**
 * Exception for outbound webhook endpoint validation failures.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Webhooks;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Thrown when endpoint data fails validation.
 *
 * Its message is translated because an administrator sees it.
 *
 * @since 1.0.0
 */
final class WebhookValidationException extends WebhookException {
}
