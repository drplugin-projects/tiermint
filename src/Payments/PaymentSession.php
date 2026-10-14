<?php
/**
 * Immutable DTO for a created gateway payment session.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Payments;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Gateway payment session for the browser.
 *
 * Redirect gateways use {@see redirectUrl()}. In-page gateways use the client secret.
 *
 * @since 1.0.0
 */
final class PaymentSession {

	/**
	 * Wire up every field of the session.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $gateway        The gateway id that created the session, e.g. 'stripe'.
	 * @param string      $transactionId  The gateway-side transaction id (Stripe: the PaymentIntent id, "pi_..."; PayPal: the order id).
	 * @param string      $clientSecret   The secret the browser uses to confirm the payment client-side; empty for redirect-based gateways.
	 * @param string|null $publishableKey The gateway's publishable (browser-safe) key, when the gateway has one.
	 * @param string|null $redirectUrl    The gateway approval URL the browser navigates to, for redirect-based gateways; null otherwise.
	 */
	public function __construct(
		private readonly string $gateway,
		private readonly string $transactionId,
		private readonly string $clientSecret,
		private readonly ?string $publishableKey = null,
		private readonly ?string $redirectUrl = null
	) {
	}

	/**
	 * The gateway id that created the session.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function gateway(): string {
		return $this->gateway;
	}

	/**
	 * The gateway-side transaction id (Stripe: the PaymentIntent id).
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function transactionId(): string {
		return $this->transactionId;
	}

	/**
	 * The secret the browser uses to confirm the payment client-side.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function clientSecret(): string {
		return $this->clientSecret;
	}

	/**
	 * The gateway's publishable (browser-safe) key, when the gateway has one.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public function publishableKey(): ?string {
		return $this->publishableKey;
	}

	/**
	 * Return the redirect approval URL, or null for in-page gateways.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public function redirectUrl(): ?string {
		return $this->redirectUrl;
	}
}
