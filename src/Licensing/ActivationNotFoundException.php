<?php
/**
 * Exception for deactivating a site that was never activated.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Licensing;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Thrown when a license has no activation for a site.
 *
 * The REST layer maps it to `404 activation_not_found`.
 *
 * @since 1.0.0
 */
final class ActivationNotFoundException extends LicenseException {

	/**
	 * Build a missing-activation error.
	 *
	 * @since 1.0.0
	 *
	 * @param string $siteUrl The normalized site URL that was named.
	 * @return self The exception to throw.
	 */
	public static function forSite( string $siteUrl ): self {
		return new self(
			sprintf(
				/* translators: %s: the site URL that has no activation. */
				__( 'The site %s is not activated for this license.', 'tiermint' ),
				$siteUrl
			)
		);
	}
}
