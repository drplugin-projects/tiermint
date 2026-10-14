<?php
/**
 * The permission tier of a management API key.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\REST\Auth;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Read and write API key permissions.
 *
 * @since 1.0.0
 */
enum ApiKeyPermission: string {

	/**
	 * Safe methods only (GET/HEAD/OPTIONS).
	 */
	case Read = 'read';

	/**
	 * All methods the owner's capabilities allow.
	 */
	case Write = 'write';
}
