<?php
/**
 * Last-known-good validation cache with the 72-hour grace window.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Licensing;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Caches successful license validations for a 72-hour grace window.
 *
 * State changes clear the cache. Transient keys contain only a license-key digest.
 *
 * @since 1.0.0
 */
class LastKnownGood {

	/**
	 * Grace window for remembered validations.
	 *
	 * @var int
	 */
	public const GRACE_HOURS = 72;

	/**
	 * Remember a successful validation payload for the grace window.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $licenseKey The validated license key.
	 * @param array<string, mixed> $payload    The canonical validation payload to keep.
	 * @return void
	 */
	public function remember( string $licenseKey, array $payload ): void {
		set_transient( $this->transientKey( $licenseKey ), $payload, self::GRACE_HOURS * HOUR_IN_SECONDS );
	}

	/**
	 * The remembered payload for a key, or null when none is fresh.
	 *
	 * @since 1.0.0
	 *
	 * @param string $licenseKey The license key.
	 * @return array<string, mixed>|null The remembered payload, or null.
	 */
	public function recall( string $licenseKey ): ?array {
		$payload = get_transient( $this->transientKey( $licenseKey ) );

		return is_array( $payload ) ? $payload : null;
	}

	/**
	 * Remove a remembered payload.
	 *
	 * @since 1.0.0
	 *
	 * @param string $licenseKey The license key.
	 * @return void
	 */
	public function forget( string $licenseKey ): void {
		delete_transient( $this->transientKey( $licenseKey ) );
	}

	/**
	 * Make a transient name without the license key.
	 *
	 * @since 1.0.0
	 *
	 * @param string $licenseKey The license key.
	 * @return string The transient name.
	 */
	private function transientKey( string $licenseKey ): string {
		return 'tiermint_lkg_' . md5( $licenseKey );
	}
}
