<?php
/**
 * Public REST receiver for inbound payment gateway webhooks.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\REST;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Payments\RequestData;
use DrPlugin\WP\TierMint\Payments\WebhookProcessor;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Receive payment-gateway webhooks.
 *
 * Signature verification authenticates the request. Verification failures stay generic.
 *
 * @since 1.0.0
 */
final class WebhooksController extends AbstractRestController {

	/**
	 * The gateway-agnostic webhook pipeline.
	 *
	 * @var WebhookProcessor
	 */
	private WebhookProcessor $processor;

	/**
	 * Set the webhook processor.
	 *
	 * @since 1.0.0
	 *
	 * @param WebhookProcessor $processor The webhook pipeline.
	 */
	public function __construct( WebhookProcessor $processor ) {
		$this->processor = $processor;
	}

	/**
	 * Register the webhook receiver route.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			'/webhooks/(?P<gateway>[a-z0-9_\-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'receive' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'gateway' => array(
							'description'       => __( 'The gateway id this delivery comes from.', 'tiermint' ),
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
			)
		);
	}

	/**
	 * Verify, deduplicate and dispatch one gateway event.
	 *
	 * Keep raw body bytes for signature verification.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error The acknowledgment, or an error envelope.
	 */
	public function receive( WP_REST_Request $request ) {
		$status = $this->processor->process(
			(string) $request->get_param( 'gateway' ),
			new RequestData( (string) $request->get_body(), $request->get_headers() )
		);

		if ( 404 === $status ) {
			return $this->notFound( __( 'Unknown payment gateway.', 'tiermint' ) );
		}

		if ( 400 === $status ) {
			return $this->error(
				'tiermint_invalid_signature',
				__( 'Webhook signature verification failed.', 'tiermint' ),
				400
			);
		}

		if ( $status >= 500 ) {
			// Answering 5xx makes the gateway retry the delivery; the claim protocol
			// guarantees the retry is reprocessed.
			return $this->serverError( __( 'Webhook processing failed. The gateway will retry.', 'tiermint' ) );
		}

		return new WP_REST_Response( array( 'received' => true ), 200 );
	}
}
