<?php
/**
 * Typed accessor for the Stripe gateway's stored configuration.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Payments\Stripe;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Payments\PaymentException;
use DrPlugin\WP\TierMint\Util\Crypto;
use DrPlugin\WP\TierMint\Util\CryptoException;

/**
 * Stores public settings and encrypted per-mode secrets.
 *
 * @since 1.0.0
 */
class StripeSettings {

	/**
	 * Option name of the shared, autoloaded settings array.
	 *
	 * @var string
	 */
	private const SETTINGS_OPTION = 'tiermint_settings';

	/**
	 * Option name prefix for the per-mode encrypted secret API keys; the mode
	 * ('test'/'live') is appended.
	 *
	 * @var string
	 */
	private const SECRET_KEY_OPTION_PREFIX = 'tiermint_secret_stripe_sk_';

	/**
	 * Option name prefix for the per-mode encrypted webhook signing secrets;
	 * the mode ('test'/'live') is appended.
	 *
	 * @var string
	 */
	private const WEBHOOK_SECRET_OPTION_PREFIX = 'tiermint_secret_stripe_whsec_';

	/**
	 * Store the crypto collaborator used for secrets at rest.
	 *
	 * @since 1.0.0
	 *
	 * @param Crypto $crypto Encrypts/decrypts the secret options.
	 */
	public function __construct( private readonly Crypto $crypto ) {}

	/**
	 * Get the active Stripe mode. Invalid values use test mode.
	 *
	 * @since 1.0.0
	 *
	 * @return string Either 'test' or 'live'.
	 */
	public function mode(): string {
		$mode = $this->stripeSettings()['mode'] ?? 'test';

		return 'live' === $mode ? 'live' : 'test';
	}

	/**
	 * Whether the gateway is in test mode.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function isTestMode(): bool {
		return 'test' === $this->mode();
	}

	/**
	 * Get the trimmed publishable key for the active mode.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public function publishableKey(): ?string {
		$key = $this->stripeSettings()[ 'publishable_key_' . $this->mode() ] ?? null;

		if ( ! is_string( $key ) ) {
			return null;
		}

		$key = trim( $key );

		return '' === $key ? null : $key;
	}

	/**
	 * Get the decrypted secret API key for the active mode.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null The plaintext key, or null when absent, blank, or undecryptable.
	 */
	public function secretKey(): ?string {
		return $this->readSecret( self::SECRET_KEY_OPTION_PREFIX . $this->mode() );
	}

	/**
	 * Get the decrypted webhook signing secret for the active mode.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public function webhookSecret(): ?string {
		return $this->readSecret( self::WEBHOOK_SECRET_OPTION_PREFIX . $this->mode() );
	}

	/**
	 * Get the decrypted secret API key for a mode.
	 *
	 * @since 1.0.0
	 *
	 * @param string $mode Either 'test' or 'live'.
	 * @return string|null The plaintext key, or null when absent, blank, or undecryptable.
	 * @throws PaymentException When $mode is anything else.
	 */
	public function secretKeyFor( string $mode ): ?string {
		$this->assertValidMode( $mode );

		return $this->readSecret( self::SECRET_KEY_OPTION_PREFIX . $mode );
	}

	/**
	 * Get the decrypted webhook signing secret for a mode.
	 *
	 * @since 1.0.0
	 *
	 * @param string $mode Either 'test' or 'live'.
	 * @return string|null The plaintext secret, or null when absent, blank, or undecryptable.
	 * @throws PaymentException When $mode is anything else.
	 */
	public function webhookSecretFor( string $mode ): ?string {
		$this->assertValidMode( $mode );

		return $this->readSecret( self::WEBHOOK_SECRET_OPTION_PREFIX . $mode );
	}

	/**
	 * Check whether the active mode has payment keys.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function isConfigured(): bool {
		return null !== $this->secretKey() && null !== $this->publishableKey();
	}

	/**
	 * Persist the active mode.
	 *
	 * @since 1.0.0
	 *
	 * @param string $mode Either 'test' or 'live'.
	 * @return void
	 * @throws PaymentException When $mode is anything else; the translated message surfaces through the settings UI.
	 */
	public function setMode( string $mode ): void {
		$this->assertValidMode( $mode );
		$this->writeStripeSetting( 'mode', $mode );
	}

	/**
	 * Store the publishable key for a mode.
	 *
	 * @since 1.0.0
	 *
	 * @param string $mode Either 'test' or 'live'.
	 * @param string $key  The publishable key to store, as entered.
	 * @return void
	 * @throws PaymentException When $mode is invalid.
	 */
	public function setPublishableKey( string $mode, string $key ): void {
		$this->assertValidMode( $mode );
		$this->writeStripeSetting( 'publishable_key_' . $mode, $key );
	}

	/**
	 * Store an encrypted secret API key, or delete an empty value.
	 *
	 * @since 1.0.0
	 *
	 * @param string $mode Either 'test' or 'live'.
	 * @param string $key  The plaintext secret key; empty/whitespace clears it.
	 * @return void
	 * @throws PaymentException When $mode is invalid.
	 * @throws CryptoException  When encryption is impossible (SECURE_AUTH_KEY missing).
	 */
	public function setSecretKey( string $mode, string $key ): void {
		$this->assertValidMode( $mode );
		$this->writeSecret( self::SECRET_KEY_OPTION_PREFIX . $mode, $key );
	}

	/**
	 * Store an encrypted webhook secret, or delete an empty value.
	 *
	 * @since 1.0.0
	 *
	 * @param string $mode Either 'test' or 'live'.
	 * @param string $key  The plaintext webhook secret; empty/whitespace clears it.
	 * @return void
	 * @throws PaymentException When $mode is invalid.
	 * @throws CryptoException  When encryption is impossible (SECURE_AUTH_KEY missing).
	 */
	public function setWebhookSecret( string $mode, string $key ): void {
		$this->assertValidMode( $mode );
		$this->writeSecret( self::WEBHOOK_SECRET_OPTION_PREFIX . $mode, $key );
	}

	/**
	 * Read the Stripe subarray out of the shared settings option.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed> The stored Stripe settings, or an empty array.
	 */
	private function stripeSettings(): array {
		$settings = get_option( self::SETTINGS_OPTION, array() );

		if ( ! is_array( $settings ) ) {
			return array();
		}

		$stripe = $settings['payments']['stripe'] ?? array();

		return is_array( $stripe ) ? $stripe : array();
	}

	/**
	 * Update one Stripe setting without replacing other settings.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key   The key inside ['payments']['stripe'].
	 * @param mixed  $value The value to store.
	 * @return void
	 */
	private function writeStripeSetting( string $key, mixed $value ): void {
		$settings = get_option( self::SETTINGS_OPTION, array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$settings['payments']['stripe'][ $key ] = $value;

		update_option( self::SETTINGS_OPTION, $settings );
	}

	/**
	 * Read a secret option. Invalid values read as null.
	 *
	 * @since 1.0.0
	 *
	 * @param string $option The full option name to read.
	 * @return string|null The trimmed plaintext, or null.
	 */
	private function readSecret( string $option ): ?string {
		$stored = get_option( $option, '' );

		if ( ! is_string( $stored ) || '' === $stored ) {
			return null;
		}

		try {
			$plaintext = $this->crypto->decrypt( $stored );
		} catch ( CryptoException $e ) {
			// An unreadable secret leaves the gateway unconfigured.
			return null;
		}

		$plaintext = trim( $plaintext );

		return '' === $plaintext ? null : $plaintext;
	}

	/**
	 * Store a non-autoloaded secret, or delete an empty value.
	 *
	 * @since 1.0.0
	 *
	 * @param string $option The full option name to write.
	 * @param string $key    The plaintext secret; empty/whitespace deletes.
	 * @return void
	 * @throws CryptoException When encryption is impossible (SECURE_AUTH_KEY missing).
	 */
	private function writeSecret( string $option, string $key ): void {
		if ( '' === trim( $key ) ) {
			delete_option( $option );

			return;
		}

		// Secrets must not enter the alloptions cache.
		update_option( $option, $this->crypto->encrypt( $key ), false );
	}

	/**
	 * Reject any mode value other than 'test' or 'live'.
	 *
	 * @since 1.0.0
	 *
	 * @param string $mode The candidate mode value.
	 * @return void
	 * @throws PaymentException When the mode is invalid; the message is translated because it surfaces through the settings UI.
	 */
	private function assertValidMode( string $mode ): void {
		if ( 'test' !== $mode && 'live' !== $mode ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Static validation message, surfaced as a structured API error by the settings controller, never echoed as HTML.
			throw new PaymentException( __( 'Stripe mode must be "test" or "live".', 'tiermint' ) );
		}
	}
}
