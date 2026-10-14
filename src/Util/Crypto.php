<?php
/**
 * Secrets-at-rest encryption utility.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Util;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Encrypts stored secrets with libsodium secretbox.
 *
 * Values include a fresh nonce. Failures throw {@see CryptoException}.
 *
 * @since 1.0.0
 */
final class Crypto {

	/**
	 * Separates this derived key from other salt-derived keys.
	 *
	 * @var string
	 */
	private const KEY_CONTEXT = 'tiermint-secrets';

	/**
	 * Encrypts a plaintext secret for storage at rest.
	 *
	 * @param string $plaintext Secret to encrypt.
	 * @return string Encoded nonce and ciphertext.
	 * @throws CryptoException If SECURE_AUTH_KEY is missing or empty.
	 */
	public function encrypt( string $plaintext ): string {
		$key = $this->derive_key();

		// A secretbox nonce must not be reused with this key.
		$nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, $key );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Store binary ciphertext in an option.
		return base64_encode( $nonce . $ciphertext );
	}

	/**
	 * Decrypt a value from {@see encrypt()}.
	 *
	 * @param string $encoded Encoded nonce and ciphertext.
	 * @return string Plaintext.
	 * @throws CryptoException If the key or encoded value is invalid.
	 */
	public function decrypt( string $encoded ): string {
		$key = $this->derive_key();

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decode stored ciphertext.
		$payload = base64_decode( $encoded, true );
		if ( false === $payload ) {
			throw new CryptoException( 'Stored secret is not valid base64.' );
		}

		if ( strlen( $payload ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			throw new CryptoException( 'Stored secret payload is too short to contain a nonce and ciphertext.' );
		}

		$nonce      = substr( $payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = substr( $payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		$plaintext = sodium_crypto_secretbox_open( $ciphertext, $nonce, $key );
		if ( false === $plaintext ) {
			throw new CryptoException( 'Secret decryption failed: MAC verification error (was SECURE_AUTH_KEY rotated?).' );
		}

		return $plaintext;
	}

	/**
	 * Derive the secretbox key from SECURE_AUTH_KEY.
	 *
	 * Salt rotation invalidates stored secrets.
	 *
	 * @return string 32-byte binary key.
	 * @throws CryptoException If SECURE_AUTH_KEY is absent or empty.
	 */
	private function derive_key(): string {
		if ( ! defined( 'SECURE_AUTH_KEY' ) || '' === SECURE_AUTH_KEY ) {
			throw new CryptoException( 'SECURE_AUTH_KEY is not defined or is empty; cannot derive an encryption key.' );
		}

		// Raw SHA-256 output has the required key length.
		return hash( 'sha256', SECURE_AUTH_KEY . self::KEY_CONTEXT, true );
	}
}
