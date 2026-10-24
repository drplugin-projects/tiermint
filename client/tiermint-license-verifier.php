<?php
/**
 * Verify signed TierMint license verdicts offline.
 *
 * @package DrPlugin\WP\TierMint
 */

// Block direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Allow another bundled copy to load first.
if ( ! class_exists( 'TierMint_License_Verifier' ) ) {

	/**
	 * Verifies the signature, key, site, and age of a license verdict.
	 *
	 * @since 1.0.0
	 */
	class TierMint_License_Verifier {

		/**
		 * The default freshness window: a signed verdict older than this is stale.
		 *
		 * @var int
		 */
		const DEFAULT_MAX_AGE = 604800;

		/**
		 * Default public keys, keyed by key ID.
		 *
		 * @var array<string, string>
		 */
		const PINNED_PUBLIC_KEYS = array();

		/**
		 * The trusted public keys, keyed by key id.
		 *
		 * @var array<string, string>
		 */
		private $public_keys;

		/**
		 * The freshness window in seconds; 0 disables the age check.
		 *
		 * @var int
		 */
		private $max_age;

		/**
		 * Build the verifier.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string>|null $public_keys     The pinned public keys (key_id => base64);
		 *                                                     null uses {@see PINNED_PUBLIC_KEYS}.
		 * @param int                        $max_age_seconds The freshness window; 0 disables it.
		 */
		public function __construct( $public_keys = null, $max_age_seconds = self::DEFAULT_MAX_AGE ) {
			$this->public_keys = is_array( $public_keys ) ? $public_keys : self::PINNED_PUBLIC_KEYS;
			$this->max_age     = (int) $max_age_seconds;
		}

		/**
		 * Verify the signature block carried by a decoded validate/activate response.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $response    The decoded response body (must carry a `signature`).
		 * @param string               $license_key The license key this install holds.
		 * @param string|null          $site_url    The site being checked, or null to skip the site check.
		 * @param string|null          $fingerprint An optional device fingerprint.
		 * @param int|null             $now         The current unix time, or null for time().
		 * @return array<string, mixed> The verification result (see {@see verify()}).
		 */
		public function verify_response( $response, $license_key, $site_url = null, $fingerprint = null, $now = null ) {
			$signature = ( is_array( $response ) && isset( $response['signature'] ) ) ? $response['signature'] : null;

			return $this->verify( $signature, $license_key, $site_url, $fingerprint, $now );
		}

		/**
		 * Verify a signature block.
		 *
		 * @since 1.0.0
		 *
		 * @param mixed       $signature   The `{ alg, key_id, payload, value }` block.
		 * @param string      $license_key The license key this install holds.
		 * @param string|null $site_url    The site being checked, or null to skip the site check.
		 * @param string|null $fingerprint An optional device fingerprint.
		 * @param int|null    $now         The current unix time, or null for time().
		 * @return array<string, mixed> The result: `verified` (bool), `reason` (string), plus the
		 *                               decoded `status`, `product_slug`, `expires_at`, `issued_at`,
		 *                               `site_bound` (bool), and `fields` (the decoded payload or null).
		 */
		public function verify( $signature, $license_key, $site_url = null, $fingerprint = null, $now = null ) {
			if ( ! $this->sodium_available() ) {
				return $this->result( false, 'sodium_unavailable', null );
			}

			if ( ! is_array( $signature ) ) {
				return $this->result( false, 'malformed_signature', null );
			}

			$alg     = isset( $signature['alg'] ) ? (string) $signature['alg'] : '';
			$key_id  = isset( $signature['key_id'] ) ? (string) $signature['key_id'] : '';
			$payload = isset( $signature['payload'] ) ? (string) $signature['payload'] : '';
			$value   = isset( $signature['value'] ) ? (string) $signature['value'] : '';

			if ( 'ed25519' !== $alg || '' === $key_id || '' === $payload || '' === $value ) {
				return $this->result( false, 'malformed_signature', null );
			}

			if ( ! isset( $this->public_keys[ $key_id ] ) ) {
				return $this->result( false, 'unknown_key', null );
			}

			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding a pinned public key, not obfuscated code.
			$public_key = base64_decode( (string) $this->public_keys[ $key_id ], true );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding the signature bytes, not obfuscated code.
			$signature_bytes = base64_decode( $value, true );

			if ( false === $public_key || false === $signature_bytes
				|| SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $public_key )
				|| SODIUM_CRYPTO_SIGN_BYTES !== strlen( $signature_bytes ) ) {
				return $this->result( false, 'malformed_signature', null );
			}

			// Verify the exact base64 string that the server signed.
			if ( ! sodium_crypto_sign_verify_detached( $signature_bytes, $payload, $public_key ) ) {
				return $this->result( false, 'bad_signature', null );
			}

			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding the canonical JSON we just verified, not obfuscated code.
			$json   = base64_decode( $payload, true );
			$fields = ( false === $json ) ? null : json_decode( $json, true );

			if ( ! is_array( $fields ) ) {
				return $this->result( false, 'malformed_payload', null );
			}

			$signed_key_hash = isset( $fields['license_key_hash'] ) ? (string) $fields['license_key_hash'] : '';

			if ( ! hash_equals( hash( 'sha256', (string) $license_key ), $signed_key_hash ) ) {
				return $this->result( false, 'key_mismatch', $fields );
			}

			$signed_site = isset( $fields['site_hash'] ) ? (string) $fields['site_hash'] : '';

			if ( null !== $site_url && '' !== (string) $site_url && '' !== $signed_site ) {
				// An empty hash does not bind the verdict to a site.
				$local = $this->site_hash( (string) $site_url, $fingerprint );

				if ( '' === $local || ! hash_equals( $local, $signed_site ) ) {
					return $this->result( false, 'site_mismatch', $fields );
				}
			}

			$issued_at = isset( $fields['issued_at'] ) ? (string) $fields['issued_at'] : '';
			$issued_ts = ( '' === $issued_at ) ? false : strtotime( $issued_at );

			if ( false === $issued_ts ) {
				return $this->result( false, 'malformed_payload', $fields );
			}

			$current = ( null === $now ) ? time() : (int) $now;

			if ( $this->max_age > 0 && ( $current - $issued_ts ) > $this->max_age ) {
				return $this->result( false, 'stale', $fields );
			}

			return $this->result( true, 'ok', $fields );
		}

		/**
		 * Build the store-compatible site hash.
		 *
		 * @since 1.0.0
		 *
		 * @param string      $site_url    The raw site URL.
		 * @param string|null $fingerprint The optional device fingerprint.
		 * @return string The 64-hex hash, or an empty string when the URL yields no host.
		 */
		public function site_hash( $site_url, $fingerprint = null ) {
			$normalized = $this->normalize_site( (string) $site_url );

			if ( '' === $normalized ) {
				return '';
			}

			return hash( 'sha256', $normalized . '|' . ( null !== $fingerprint ? (string) $fingerprint : '' ) );
		}

		/**
		 * Normalize a site URL for the store comparison.
		 *
		 * @since 1.0.0
		 *
		 * @param string $url The raw URL.
		 * @return string The canonical form, or an empty string when there is no host.
		 */
		private function normalize_site( $url ) {
			$url = trim( (string) $url );

			if ( '' === $url ) {
				return '';
			}

			// Add an authority prefix to scheme-less host names.
			$has_scheme = 1 === preg_match( '#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $url );

			if ( ! $has_scheme && '/' !== substr( $url, 0, 1 ) ) {
				$url = '//' . $url;
			}

			// Parse "//host" with a placeholder scheme, as wp_parse_url() does, so a
			// trailing ":digits" in the path is not read as a port.
			$relative = 0 === strpos( $url, '//' );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- dependency-free drop-in; reads only host, port, and path, consistent across PHP versions.
			$parts = parse_url( $relative ? 'placeholder:' . $url : $url );

			if ( false === $parts || empty( $parts['host'] ) ) {
				return '';
			}

			$host = strtolower( $parts['host'] );

			if ( 0 === strpos( $host, 'www.' ) ) {
				$host = substr( $host, 4 );
			}

			if ( function_exists( 'idn_to_ascii' ) ) {
				$ascii = idn_to_ascii( $host );

				if ( is_string( $ascii ) && '' !== $ascii ) {
					$host = $ascii;
				}
			}

			$result = $host;
			$port   = isset( $parts['port'] ) ? $parts['port'] : null;

			if ( null !== $port && 80 !== $port && 443 !== $port ) {
				$result .= ':' . $port;
			}

			$path    = isset( $parts['path'] ) ? $parts['path'] : '';
			$result .= rtrim( $path, '/' );

			return $result;
		}

		/**
		 * Check whether detached signature verification is available.
		 *
		 * @since 1.0.0
		 *
		 * @return bool True when the drop-in can verify a signature.
		 */
		protected function sodium_available() {
			return function_exists( 'sodium_crypto_sign_verify_detached' );
		}

		/**
		 * Build a verification result.
		 *
		 * @since 1.0.0
		 *
		 * @param bool                      $verified Whether the verdict is trustworthy.
		 * @param string                    $reason   The stable reason code.
		 * @param array<string, mixed>|null $fields   The decoded canonical fields, or null.
		 * @return array<string, mixed> The result.
		 */
		private function result( $verified, $reason, $fields ) {
			$fields = is_array( $fields ) ? $fields : array();

			$expires = isset( $fields['expires_at'] ) ? (string) $fields['expires_at'] : '';

			return array(
				'verified'     => (bool) $verified,
				'reason'       => $reason,
				'status'       => isset( $fields['status'] ) ? (string) $fields['status'] : null,
				'product_slug' => isset( $fields['product_slug'] ) ? (string) $fields['product_slug'] : null,
				'expires_at'   => '' === $expires ? null : $expires,
				'issued_at'    => isset( $fields['issued_at'] ) ? (string) $fields['issued_at'] : null,
				'site_bound'   => isset( $fields['site_hash'] ) && '' !== (string) $fields['site_hash'],
				'fields'       => array() === $fields ? null : $fields,
			);
		}
	}
}
