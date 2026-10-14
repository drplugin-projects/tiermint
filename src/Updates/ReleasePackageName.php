<?php
/**
 * Synthesizes the download filename for a release package.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Updates;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Builds a safe release-package filename.
 *
 * @since 1.0.0
 */
final class ReleasePackageName {

	/**
	 * Fallback filename slug.
	 *
	 * @var string
	 */
	private const FALLBACK_SLUG = 'package';

	/**
	 * Builds a safe archive filename from the preferred product slug.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $updateSlug  The product's update slug, or null.
	 * @param string|null $productSlug The product's slug, or null.
	 * @param string      $version     The release version.
	 * @return string The sanitized download filename.
	 */
	public static function synthesize( ?string $updateSlug, ?string $productSlug, string $version ): string {
		$slug = self::firstNonEmpty( $updateSlug, $productSlug );

		if ( '' === $slug ) {
			$slug = self::FALLBACK_SLUG;
		}

		return sanitize_file_name( $slug . '-' . $version . '.zip' );
	}

	/**
	 * Gets the first non-empty trimmed slug.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null ...$candidates The candidate slugs, in preference order.
	 * @return string The first non-empty trimmed candidate, or ''.
	 */
	private static function firstNonEmpty( ?string ...$candidates ): string {
		foreach ( $candidates as $candidate ) {
			if ( null !== $candidate && '' !== trim( $candidate ) ) {
				return trim( $candidate );
			}
		}

		return '';
	}
}
