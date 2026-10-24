<?php
/**
 * The Stripe implementation of the payment gateway port.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Payments\Stripe;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Orders\Order;
use DrPlugin\WP\TierMint\Payments\GatewayConfigurationException;
use DrPlugin\WP\TierMint\Payments\GatewayEvent;
use DrPlugin\WP\TierMint\Payments\GatewayInterface;
use DrPlugin\WP\TierMint\Payments\GatewayRequestException;
use DrPlugin\WP\TierMint\Payments\PaymentSession;
use DrPlugin\WP\TierMint\Payments\RefundResult;
use DrPlugin\WP\TierMint\Payments\RequestData;
use DrPlugin\WP\TierMint\Payments\WebhookVerificationException;

/**
 * Stripe gateway for payment intents, webhooks and refunds.
 *
 * @since 1.0.0
 */
final class StripeGateway implements GatewayInterface {

	/**
	 * Wire up the gateway's collaborators.
	 *
	 * @since 1.0.0
	 *
	 * @param StripeApiClient       $client   Transport to the Stripe REST API.
	 * @param StripeSettings        $settings Stored keys and mode for the gateway.
	 * @param StripeWebhookVerifier $verifier Signature verifier for inbound webhooks.
	 */
	public function __construct(
		private readonly StripeApiClient $client,
		private readonly StripeSettings $settings,
		private readonly StripeWebhookVerifier $verifier
	) {
	}

	/**
	 * The stable machine id this gateway registers under.
	 *
	 * @since 1.0.0
	 *
	 * @return string Always 'stripe'.
	 */
	public function id(): string {
		return 'stripe';
	}

	/**
	 * The translated gateway name for admin and checkout UI.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Stripe', 'tiermint' );
	}

	/**
	 * Report supported optional gateway features.
	 *
	 * @since 1.0.0
	 *
	 * @param string $feature The feature id to probe.
	 * @return bool
	 */
	public function supports( string $feature ): bool {
		return 'refunds' === $feature;
	}

	/**
	 * Check whether the active mode can take payments.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function isConfigured(): bool {
		return $this->settings->isConfigured();
	}

	/**
	 * Create an idempotent Stripe payment intent for a pending order.
	 *
	 * @since 1.0.0
	 *
	 * @param Order $order The pending order to collect payment for.
	 * @return PaymentSession
	 * @throws GatewayConfigurationException When no secret key is configured for the active mode.
	 * @throws GatewayRequestException       When the API call fails or the response lacks an intent id or client secret.
	 */
	public function createPayment( Order $order ): PaymentSession {
		$secretKey = $this->settings->secretKey();

		if ( null === $secretKey ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new GatewayConfigurationException( __( 'Stripe is not configured for the current mode. Enter your Stripe keys in the payment settings.', 'tiermint' ) );
		}

		$response = $this->client->post(
			$secretKey,
			'/v1/payment_intents',
			array(
				'amount'                    => $order->total(),
				'currency'                  => strtolower( $order->currency() ),
				'automatic_payment_methods' => array( 'enabled' => 'true' ),
				'metadata'                  => array(
					'tiermint_order_id'   => (string) $order->id(),
					'tiermint_order_uuid' => $order->uuid(),
				),
			),
			'tiermint-intent-' . $order->uuid()
		);

		$id           = $response['id'] ?? null;
		$clientSecret = $response['client_secret'] ?? null;

		if ( ! is_string( $id ) || '' === $id || ! is_string( $clientSecret ) || '' === $clientSecret ) {
			throw new GatewayRequestException( 'Stripe PaymentIntent response is missing its id or client secret.' );
		}

		return new PaymentSession( 'stripe', $id, $clientSecret, $this->settings->publishableKey() );
	}

	/**
	 * Verify a Stripe webhook before decoding it.
	 *
	 * @since 1.0.0
	 *
	 * @param RequestData $request The raw inbound request.
	 * @return GatewayEvent
	 * @throws WebhookVerificationException When no signing secret is configured, the signature
	 *                                      fails verification, or the verified body is not a
	 *                                      Stripe event shape.
	 */
	public function parseWebhook( RequestData $request ): GatewayEvent {
		$secret = $this->settings->webhookSecret();

		if ( null === $secret ) {
			throw new WebhookVerificationException( 'Stripe webhook signing secret is not configured.' );
		}

		$this->verifier->verify( $request->body(), $request->header( 'Stripe-Signature' ), $secret );

		$decoded = json_decode( $request->body(), true );

		$id   = is_array( $decoded ) ? ( $decoded['id'] ?? null ) : null;
		$type = is_array( $decoded ) ? ( $decoded['type'] ?? null ) : null;

		if ( ! is_string( $id ) || '' === $id || ! is_string( $type ) || '' === $type ) {
			throw new WebhookVerificationException( 'Stripe webhook carried an unparseable event payload.' );
		}

		$data = ( isset( $decoded['data'] ) && is_array( $decoded['data'] ) ) ? $decoded['data'] : array();

		return new GatewayEvent( 'stripe', $id, $type, $data, $request->body() );
	}

	/**
	 * Push a refund to Stripe against the order's PaymentIntent.
	 *
	 * @since 1.0.0
	 *
	 * @param Order       $order          The Stripe-paid order to refund against.
	 * @param int         $amount         The amount to refund in minor currency units.
	 * @param string|null $idempotencyKey Caller-derived idempotency key, passed straight through
	 *                                    to Stripe so a duplicate submission replays the original
	 *                                    refund instead of refunding twice.
	 * @return RefundResult
	 * @throws GatewayConfigurationException When no secret key is configured for the active mode.
	 * @throws GatewayRequestException       When the order carries no gateway transaction id, the
	 *                                       API call fails, or the response lacks a refund id.
	 */
	public function refund( Order $order, int $amount, ?string $idempotencyKey = null ): RefundResult {
		$secretKey = $this->settings->secretKey();

		if ( null === $secretKey ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new GatewayConfigurationException( __( 'Stripe is not configured for the current mode. Enter your Stripe keys in the payment settings.', 'tiermint' ) );
		}

		$paymentIntent = $order->gatewayTxnId();

		if ( ! is_string( $paymentIntent ) || '' === $paymentIntent ) {
			throw new GatewayRequestException( 'Stripe refund failed: order has no gateway transaction id to refund against.' );
		}

		$response = $this->client->post(
			$secretKey,
			'/v1/refunds',
			array(
				'payment_intent' => $paymentIntent,
				'amount'         => $amount,
			),
			$idempotencyKey
		);

		$id = $response['id'] ?? null;

		if ( ! is_string( $id ) || '' === $id ) {
			throw new GatewayRequestException( 'Stripe refund response is missing its refund id.' );
		}

		return new RefundResult(
			(string) $response['id'],
			(int) ( $response['amount'] ?? $amount ),
			(string) ( $response['status'] ?? '' )
		);
	}

	/**
	 * Return the Stripe settings fields for the admin form.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array<string, mixed>> The field definitions.
	 */
	public function settingsFields(): array {
		return array(
			array(
				'key'   => 'mode',
				'label' => __( 'Mode', 'tiermint' ),
				'type'  => 'select',
				'mode'  => null,
			),
			array(
				'key'   => 'publishable_key_test',
				'label' => __( 'Test publishable key', 'tiermint' ),
				'type'  => 'text',
				'mode'  => 'test',
			),
			array(
				'key'   => 'secret_key_test',
				'label' => __( 'Test secret key', 'tiermint' ),
				'type'  => 'password',
				'mode'  => 'test',
			),
			array(
				'key'   => 'webhook_secret_test',
				'label' => __( 'Test webhook signing secret', 'tiermint' ),
				'type'  => 'password',
				'mode'  => 'test',
			),
			array(
				'key'   => 'publishable_key_live',
				'label' => __( 'Live publishable key', 'tiermint' ),
				'type'  => 'text',
				'mode'  => 'live',
			),
			array(
				'key'   => 'secret_key_live',
				'label' => __( 'Live secret key', 'tiermint' ),
				'type'  => 'password',
				'mode'  => 'live',
			),
			array(
				'key'   => 'webhook_secret_live',
				'label' => __( 'Live webhook signing secret', 'tiermint' ),
				'type'  => 'password',
				'mode'  => 'live',
			),
		);
	}
}
