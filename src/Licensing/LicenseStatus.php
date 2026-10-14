<?php
/**
 * License lifecycle status enum.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Licensing;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * License states stored in `tiermint_licenses.status`.
 * {@see LicenseStateMachine} controls state changes.
 *
 * @since 1.0.0
 */
enum LicenseStatus: string {

	/**
	 * Active licenses validate, activate, and receive updates.
	 */
	case Active = 'active';

	/**
	 * The license expiry time has passed.
	 */
	case Expired = 'expired';

	/**
	 * An administrator or refund disabled the license.
	 */
	case Disabled = 'disabled';
}
