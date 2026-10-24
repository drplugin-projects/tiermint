<?php
/**
 * Current-request URL builder for storefront redirects and sign-in links.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the absolute URL of the current request on top of home_url().
 *
 * On a subdirectory install REQUEST_URI already carries the home path, so the
 * naive home_url( REQUEST_URI ) doubles it (/shop/shop/account/). This strips
 * the home path once before home_url() adds it back.
 *
 * @since 1.0.0
 */
final class RequestUrl {

	/**
	 * The absolute URL of the current request, path and query, built on home_url().
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function current(): string {
		$uri = '/';
		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$uri = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );
			if ( '' === $uri ) {
				$uri = '/';
			}
		}

		$home_path = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );

		if ( '' !== $home_path ) {
			if ( $uri === $home_path ) {
				$uri = '';
			} elseif (
				str_starts_with( $uri, $home_path . '/' )
				|| str_starts_with( $uri, $home_path . '?' )
			) {
				$uri = substr( $uri, strlen( $home_path ) );
			}
		}

		return home_url( $uri );
	}
}
