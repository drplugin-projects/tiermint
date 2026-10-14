<?php
/**
 * Gateway webhook processor.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Payments;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Verifies, claims, dispatches and records webhooks.
 * Handlers must be idempotent.
 *
 * @since 1.0.0
 */
class WebhookProcessor {

	/**
	 * Wire the processor with its collaborators.
	 *
	 * @since 1.0.0
	 *
	 * @param GatewayRegistry                                            $gateways The registry resolving gateway ids.
	 * @param GatewayEventRepository                                     $events   The idempotency ledger.
	 * @param array<string, array<string, callable(GatewayEvent): bool>> $handlers Handler map keyed by gateway id then
	 *                                                                             event type. A handler returns true when
	 *                                                                             it acted on the event (row marked
	 *                                                                             processed) and false when the event was
	 *                                                                             not addressed to this install (row
	 *                                                                             marked ignored).
	 */
	public function __construct(
		private readonly GatewayRegistry $gateways,
		private readonly GatewayEventRepository $events,
		private readonly array $handlers = array()
	) {}

	/**
	 * Handler map resolved after extensions attach their filters.
	 *
	 * @var array<string, array<string, callable(GatewayEvent): bool>>|null
	 */
	private ?array $resolvedHandlers = null;

	/**
	 * Filter extension handlers while preserving all core handlers.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, array<string, callable(GatewayEvent): bool>> $coreHandlers Core's own handler map.
	 * @return array<string, array<string, callable(GatewayEvent): bool>> The merged, sanitized map.
	 */
	public static function filteredHandlerMap( array $coreHandlers ): array {
		/**
		 * Filters the per-gateway inbound webhook handler map.
		 *
		 * Core handlers cannot be replaced. Custom handlers must be idempotent.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, array<string, callable>> $handlers The handler map seeded with core's handlers.
		 */
		$filtered = apply_filters( 'tiermint_webhook_handlers', $coreHandlers );

		if ( ! is_array( $filtered ) ) {
			return $coreHandlers;
		}

		$map = array();

		foreach ( $filtered as $gatewayId => $handlers ) {
			if ( ! is_string( $gatewayId ) || ! is_array( $handlers ) ) {
				continue;
			}

			foreach ( $handlers as $eventType => $handler ) {
				if ( ! is_string( $eventType ) || ! is_callable( $handler ) ) {
					continue;
				}

				$map[ $gatewayId ][ $eventType ] = $handler;
			}
		}

		// Core handlers override replacement attempts.
		foreach ( $coreHandlers as $gatewayId => $handlers ) {
			foreach ( $handlers as $eventType => $handler ) {
				$map[ $gatewayId ][ $eventType ] = $handler;
			}
		}

		return $map;
	}

	/**
	 * Process one webhook delivery and return its response status.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $gatewayId The gateway id from the route, e.g. 'stripe'.
	 * @param RequestData $request   The raw inbound request body and headers.
	 * @return int The HTTP status code to respond with.
	 */
	public function process( string $gatewayId, RequestData $request ): int {
		try {
			$gateway = $this->gateways->get( $gatewayId );
		} catch ( GatewayNotFoundException $e ) {
			return 404;
		}

		try {
			$event = $gateway->parseWebhook( $request );
		} catch ( WebhookVerificationException $e ) {
			return 400;
		}

		try {
			$claimId = $this->events->claim( $event->gateway(), $event->id(), $event->type(), $event->rawBody() );
		} catch ( WebhookRetryException $e ) {
			// Return 500 so the gateway retries a deferred claim.
			return 500;
		}

		if ( null === $claimId ) {
			// The event has a terminal ledger entry.
			return 200;
		}

		$this->resolvedHandlers = $this->resolvedHandlers ?? self::filteredHandlerMap( $this->handlers );

		$handler = $this->resolvedHandlers[ $gatewayId ][ $event->type() ] ?? null;

		if ( null === $handler ) {
			// Ignore unsupported event types to stop retries.
			$this->events->markIgnored( $claimId );

			return 200;
		}

		try {
			$handled = (bool) $handler( $event );
		} catch ( \Throwable $e ) {
			$this->events->markFailed( $claimId, get_class( $e ) . ': ' . $e->getMessage() );

			// 500 makes the gateway retry; the retry reclaims the failed row.
			return 500;
		}

		if ( $handled ) {
			$this->events->markProcessed( $claimId );
		} else {
			$this->events->markIgnored( $claimId );
		}

		return 200;
	}
}
