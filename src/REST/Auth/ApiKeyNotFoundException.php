<?php
/**
 * Missing management API key.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\REST\Auth;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Raised for an unknown API key id.
 *
 * @since 1.0.0
 */
final class ApiKeyNotFoundException extends ApiKeyException {

	/**
	 * Build the refusal for a missing id.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The API key id that resolved to nothing.
	 * @return self The exception.
	 */
	public static function forId( int $id ): self {
		return new self( sprintf( 'API key %d does not exist.', $id ) );
	}
}
