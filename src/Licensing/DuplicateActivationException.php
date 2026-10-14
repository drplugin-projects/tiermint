<?php
/**
 * Duplicate activation persistence failure.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Licensing;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Raised when another request activates the same site first.
 *
 * The service releases its claimed slot and uses the existing activation.
 *
 * @since 1.0.0
 */
final class DuplicateActivationException extends LicensePersistenceException {

	/**
	 * Build a duplicate-activation error.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $licenseId The license the site was being activated for.
	 * @param string $siteHash  The colliding site hash.
	 * @return self The exception to throw.
	 */
	public static function forSite( int $licenseId, string $siteHash ): self {
		return new self( sprintf( 'License %d already has an activation for site hash %s.', $licenseId, $siteHash ) );
	}
}
