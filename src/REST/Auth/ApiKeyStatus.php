<?php
/**
 * The lifecycle status of a management API key.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\REST\Auth;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * API key lifecycle states.
 *
 * @since 1.0.0
 */
enum ApiKeyStatus: string {

	/**
	 * The key authenticates management requests.
	 */
	case Active = 'active';

	/**
	 * The key was revoked and only remains as an audit row.
	 */
	case Revoked = 'revoked';
}
