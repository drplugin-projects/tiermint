<?php
/**
 * Verifies Stripe webhook signatures without the Stripe SDK.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Payments\Stripe;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Payments\WebhookVerificationException;

/**
 * Verifies Stripe webhook signatures.
 *
 * Hash the raw request body. Re-encoding it invalidates a genuine signature.
 *
 * @since 1.0.0
 */
final class StripeWebhookVerifier {

	/**
	 * Default replay window in seconds.
	 *
	 * @var int
	 */
	public const DEFAULT_TOLERANCE = 300;

	/**
	 * Set the replay window.
	 *
	 * @since 1.0.0
	 *
	 * @param int $tolerance Maximum allowed clock skew in seconds, in either direction.
	 */
	public function __construct( private readonly int $tolerance = self::DEFAULT_TOLERANCE ) {
	}

	/**
	 * Verify a raw payload against a Stripe-Signature header.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $payload         Raw request body bytes.
	 * @param string|null $signatureHeader Header value.
	 * @param string      $secret          Endpoint signing secret.
	 * @param int|null    $now             Current Unix timestamp, or null for time().
	 * @return void
	 * @throws WebhookVerificationException When verification fails.
	 */
	public function verify( string $payload, ?string $signatureHeader, string $secret, ?int $now = null ): void {
		if ( '' === $secret ) {
			throw new WebhookVerificationException( 'Stripe webhook signing secret is empty; cannot verify the signature.' );
		}

		if ( null === $signatureHeader || '' === trim( $signatureHeader ) ) {
			throw new WebhookVerificationException( 'Stripe-Signature header is missing or blank.' );
		}

		$timestamp  = null;
		$candidates = array();

		// Split once to keep an equals sign in a value.
		foreach ( explode( ',', $signatureHeader ) as $pair ) {
			$pair = trim( $pair );

			$eq = strpos( $pair, '=' );
			if ( false === $eq ) {
				continue;
			}

			$key   = substr( $pair, 0, $eq );
			$value = substr( $pair, $eq + 1 );

			if ( 't' === $key ) {
				$timestamp = $value;
			} elseif ( 'v1' === $key ) {
				// Stripe can send several signatures during secret rotation.
				$candidates[] = $value;
			}
		}

		if ( null === $timestamp || ! ctype_digit( $timestamp ) ) {
			throw new WebhookVerificationException( 'Stripe-Signature header has no usable timestamp.' );
		}

		if ( array() === $candidates ) {
			throw new WebhookVerificationException( 'Stripe-Signature header carries no v1 signature.' );
		}

		$now = $now ?? time();

		if ( abs( $now - (int) $timestamp ) > $this->tolerance ) {
			throw new WebhookVerificationException( 'Stripe webhook timestamp is outside the allowed tolerance.' );
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );

		foreach ( $candidates as $candidate ) {
			// This comparison must not reveal partial matches.
			if ( hash_equals( $expected, $candidate ) ) {
				return;
			}
		}

		throw new WebhookVerificationException( 'Stripe webhook signature does not match any v1 candidate.' );
	}
}
