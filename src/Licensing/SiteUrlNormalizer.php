<?php
/**
 * Site URL normalization, hashing, and local detection.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Licensing;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Normalizes reported site URLs and finds local hosts.
 *
 * It remains extendable for licensing service tests.
 *
 * @since 1.0.0
 */
class SiteUrlNormalizer {

	/**
	 * Normalize a reported site URL to its canonical comparison form.
	 *
	 * Keep the lower-case host, optional port and path.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url The raw URL a client reported.
	 * @return string The canonical normalized form.
	 *
	 * @throws LicenseValidationException When the input is empty or yields no host.
	 */
	public function normalize( string $url ): string {
		$url = trim( $url );

		if ( '' === $url ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured validation message, surfaced as an API error, never echoed as HTML.
			throw new LicenseValidationException( __( 'A site URL is required.', 'tiermint' ) );
		}

		// parse_url() needs an authority to find a host.
		$has_scheme = 1 === preg_match( '#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $url );
		if ( ! $has_scheme && '/' !== substr( $url, 0, 1 ) ) {
			$url = '//' . $url;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- This is a pure, WordPress-free primitive (unit tested without WordPress loaded); it reads only host, port, and path, which are consistent across PHP versions.
		$parts = parse_url( $url );

		if ( false === $parts || empty( $parts['host'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured validation message, surfaced as an API error, never echoed as HTML.
			throw new LicenseValidationException( __( 'The site URL could not be understood.', 'tiermint' ) );
		}

		$host = strtolower( $parts['host'] );

		// Remove one leading www.
		if ( str_starts_with( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}

		// Use punycode so Unicode and ASCII host names match.
		if ( function_exists( 'idn_to_ascii' ) ) {
			$ascii = idn_to_ascii( $host );
			if ( is_string( $ascii ) && '' !== $ascii ) {
				$host = $ascii;
			}
		}

		$result = $host;

		// Keep an explicit non-default port: it distinguishes a distinct install.
		$port = $parts['port'] ?? null;
		if ( null !== $port && 80 !== $port && 443 !== $port ) {
			$result .= ':' . $port;
		}

		// Keep multisite paths without trailing slashes.
		$path    = $parts['path'] ?? '';
		$result .= rtrim( $path, '/' );

		return $result;
	}

	/**
	 * Compute the activation lookup/uniqueness hash for a normalized URL.
	 *
	 * A fingerprint separates devices on the same URL.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $normalizedUrl A URL already run through {@see normalize()}.
	 * @param string|null $fingerprint   An optional client device fingerprint, or null.
	 * @return string The 64-character hexadecimal SHA-256 hash.
	 */
	public function hash( string $normalizedUrl, ?string $fingerprint = null ): string {
		return hash( 'sha256', $normalizedUrl . '|' . ( null !== $fingerprint ? $fingerprint : '' ) );
	}

	/**
	 * Whether a normalized host is exempt from the activation limit.
	 *
	 * Local, staging, private and reserved hosts are exempt.
	 *
	 * @since 1.0.0
	 *
	 * @param string $normalizedUrl A URL already run through {@see normalize()}.
	 * @return bool True when the host should be treated as local.
	 */
	public function isLocal( string $normalizedUrl ): bool {
		$identity = $this->hostIdentity( $normalizedUrl );

		/**
		 * Filters host patterns that are exempt from activation limits.
		 *
		 * @since 1.0.0
		 *
		 * @param array{exact: array<int, string>, suffix: array<int, string>, prefix: array<int, string>} $patterns The default local-host patterns.
		 */
		$patterns = apply_filters(
			'tiermint_local_url_patterns',
			array(
				'exact'  => array( 'localhost' ),
				'suffix' => array( '.test', '.local', '.dev' ),
				'prefix' => array( 'staging.', 'dev.' ),
			)
		);

		foreach ( (array) ( $patterns['exact'] ?? array() ) as $exact ) {
			if ( $identity === $exact ) {
				return true;
			}
		}

		foreach ( (array) ( $patterns['suffix'] ?? array() ) as $suffix ) {
			if ( '' !== $suffix && str_ends_with( $identity, $suffix ) ) {
				return true;
			}
		}

		foreach ( (array) ( $patterns['prefix'] ?? array() ) as $prefix ) {
			if ( '' !== $prefix && str_starts_with( $identity, $prefix ) ) {
				return true;
			}
		}

		// A valid non-public IP is local.
		if ( false !== filter_var( $identity, FILTER_VALIDATE_IP ) ) {
			$is_public = filter_var( $identity, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
			if ( false === $is_public ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Extract the host without path, port or IPv6 brackets.
	 *
	 * @since 1.0.0
	 *
	 * @param string $normalizedUrl A URL already run through {@see normalize()}.
	 * @return string The host identity: a hostname, or a bare IP for IP hosts.
	 */
	private function hostIdentity( string $normalizedUrl ): string {
		$host = $normalizedUrl;

		// Remove the path.
		$slash = strpos( $host, '/' );
		if ( false !== $slash ) {
			$host = substr( $host, 0, $slash );
		}

		// Strip brackets from an IPv6 literal.
		if ( str_starts_with( $host, '[' ) ) {
			$end = strpos( $host, ']' );

			return false !== $end ? substr( $host, 1, $end - 1 ) : $host;
		}

		// Remove a trailing port.
		$colon = strrpos( $host, ':' );

		return false !== $colon ? substr( $host, 0, $colon ) : $host;
	}
}
