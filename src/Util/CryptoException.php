<?php
/**
 * Exception for secrets-at-rest encryption and decryption failures.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Util;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use RuntimeException;

/**
 * Raised when {@see Crypto} cannot process a stored secret.
 *
 * Keep its messages internal. Callers show a safe translated message instead.
 *
 * @since 1.0.0
 */
class CryptoException extends RuntimeException {
}
