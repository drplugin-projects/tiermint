<?php
/**
 * Outbound webhook delivery job handler.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Webhooks;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Core\Hookable;
use DrPlugin\WP\TierMint\Database\Logger;

/**
 * Deliver queued webhooks with a retry ladder and HMAC signature.
 *
 * Sign each attempt with a new timestamp. Disable endpoints after all retries fail.
 */
class WebhookDeliverer implements Hookable {

	/**
	 * Retry delays in seconds after each failed attempt.
	 *
	 * @var array<int, int>
	 */
	private const RETRY_DELAYS = array( 60, 900, 3600, 21600, 86400 );

	/**
	 * HTTP timeout in seconds. Slow receivers get retries.
	 *
	 * @var int
	 */
	private const TIMEOUT = 5;

	/**
	 * The registered endpoint store.
	 *
	 * @var WebhookEndpoints
	 */
	private WebhookEndpoints $endpoints;

	/**
	 * Signs each attempt's body.
	 *
	 * @var WebhookSigner
	 */
	private WebhookSigner $signer;

	/**
	 * Writes the delivery log (type webhook_out).
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Wire the collaborators.
	 *
	 * @param WebhookEndpoints $endpoints Registered endpoint configs and secrets.
	 * @param WebhookSigner    $signer    Signature builder.
	 * @param Logger           $logger    Audit log writer.
	 */
	public function __construct( WebhookEndpoints $endpoints, WebhookSigner $signer, Logger $logger ) {
		$this->endpoints = $endpoints;
		$this->signer    = $signer;
		$this->logger    = $logger;
	}

	/**
	 * Bind the queue job hook.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( WebhookDispatcher::JOB_HOOK, array( $this, 'deliver' ), 10, 5 );
	}

	/**
	 * Attempt one delivery.
	 *
	 * @param string $endpointId The target endpoint id.
	 * @param string $event      The catalog event name.
	 * @param string $deliveryId The stable per-delivery uuid (X-TierMint-Delivery).
	 * @param string $body       The frozen JSON body snapshotted at dispatch.
	 * @param int    $attempt    Zero-based attempt counter.
	 * @return void
	 */
	public function deliver( string $endpointId, string $event, string $deliveryId, string $body, int $attempt ): void {
		$endpoint = $this->endpoints->get( $endpointId );

		// Do not retry an endpoint removed or disabled after enqueue.
		if ( null === $endpoint || ! $endpoint['active'] ) {
			return;
		}

		$secret = $this->endpoints->secretFor( $endpointId );

		if ( null === $secret ) {
			// Never send without a usable signing secret.
			$this->logger->log(
				'error',
				'Outbound webhook delivery aborted: no usable signing secret for endpoint ' . $endpointId . '.',
				array(
					'endpoint' => $endpointId,
					'event'    => $event,
					'delivery' => $deliveryId,
				)
			);

			return;
		}

		// WordPress rejects unsafe URLs at send time.
		$response = wp_safe_remote_post(
			$endpoint['url'],
			array(
				'timeout' => self::TIMEOUT,
				'body'    => $body,
				'headers' => array(
					'Content-Type'         => 'application/json',
					'X-TierMint-Event'     => $event,
					'X-TierMint-Delivery'  => $deliveryId,
					'X-TierMint-Signature' => $this->signer->sign( $body, $secret, time() ),
					'User-Agent'           => 'TierMint/' . ( defined( 'TIERMINT_VERSION' ) ? TIERMINT_VERSION : 'dev' ),
				),
			)
		);

		$isError = is_wp_error( $response );
		$status  = $isError ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$success = ! $isError && $status >= 200 && $status < 300;

		$this->logger->log(
			'webhook_out',
			sprintf( 'Webhook %1$s delivery %2$s attempt %3$d: %4$s.', $event, $deliveryId, $attempt + 1, $success ? 'delivered' : 'failed' ),
			array(
				'endpoint' => $endpointId,
				'url'      => $endpoint['url'],
				'event'    => $event,
				'delivery' => $deliveryId,
				'attempt'  => $attempt,
				'status'   => $status,
				'success'  => $success,
				'error'    => $isError ? $response->get_error_message() : null,
			)
		);

		if ( $success ) {
			return;
		}

		if ( $attempt < count( self::RETRY_DELAYS ) ) {
			as_schedule_single_action(
				time() + self::RETRY_DELAYS[ $attempt ],
				WebhookDispatcher::JOB_HOOK,
				array( $endpointId, $event, $deliveryId, $body, $attempt + 1 ),
				'tiermint'
			);

			return;
		}

		// Stop retries and show the failure until the endpoint is re-enabled.
		$this->endpoints->markFailing( $endpointId );
		$this->logger->log(
			'error',
			'Outbound webhook endpoint disabled after repeated delivery failures: ' . $endpoint['url'],
			array(
				'endpoint' => $endpointId,
				'event'    => $event,
				'delivery' => $deliveryId,
			)
		);
	}
}
