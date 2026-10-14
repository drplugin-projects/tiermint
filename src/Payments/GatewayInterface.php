<?php
/**
 * The payment gateway port every gateway implements.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Payments;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Orders\Order;

/**
 * Contract for payment gateways.
 *
 * Gateways register through the tiermint_payment_gateways filter.
 *
 * @since 1.0.0
 */
interface GatewayInterface {

	/**
	 * The stable machine id this gateway registers under, e.g. 'stripe'.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * The human-readable, translated gateway name for admin and checkout UI.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Check a gateway capability.
	 *
	 * @since 1.0.0
	 *
	 * @param string $feature The feature id to probe.
	 * @return bool
	 */
	public function supports( string $feature ): bool;

	/**
	 * Check whether the gateway is ready to take payment.
	 *
	 * Call this before creating an order, so a missing configuration keeps the cart.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function isConfigured(): bool;

	/**
	 * Create a payment session for a pending order.
	 *
	 * @since 1.0.0
	 *
	 * @param Order $order The pending order to collect payment for.
	 * @return PaymentSession
	 * @throws GatewayConfigurationException When the gateway is not configured for the active mode.
	 * @throws GatewayRequestException       When the gateway API call fails.
	 */
	public function createPayment( Order $order ): PaymentSession;

	/**
	 * Verify and parse an inbound webhook.
	 *
	 * A returned event has a verified signature.
	 *
	 * @since 1.0.0
	 *
	 * @param RequestData $request The raw inbound request.
	 * @return GatewayEvent
	 * @throws WebhookVerificationException When the request cannot be authenticated or parsed.
	 */
	public function parseWebhook( RequestData $request ): GatewayEvent;

	/**
	 * Refund part or all of a paid order.
	 *
	 * @since 1.0.0
	 *
	 * @param Order       $order          The gateway-paid order to refund against.
	 * @param int         $amount         The amount to refund in minor currency units.
	 * @param string|null $idempotencyKey A caller-derived idempotency key: gateways that
	 *                                    support one (Stripe does) replay the original
	 *                                    result for a duplicate submission instead of
	 *                                    refunding twice.
	 * @return RefundResult
	 * @throws GatewayConfigurationException When the gateway is not configured for the active mode.
	 * @throws GatewayRequestException       When the gateway rejects or fails the refund.
	 */
	public function refund( Order $order, int $amount, ?string $idempotencyKey = null ): RefundResult;

	/**
	 * Return the gateway settings schema.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function settingsFields(): array;
}
