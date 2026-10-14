<?php
/**
 * Immutable DTO for a verified inbound gateway webhook event.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Payments;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Verified gateway webhook data.
 *
 * A GatewayEvent means signature verification passed.
 *
 * @since 1.0.0
 */
final class GatewayEvent {

	/**
	 * Wire up every field of the event.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $gateway The gateway id the event arrived on, e.g. 'stripe'.
	 * @param string               $id      The gateway's globally unique event id (Stripe: "evt_...").
	 * @param string               $type    The gateway's event type, e.g. 'payment_intent.succeeded'.
	 * @param array<string, mixed> $data    The decoded event `data` member (Stripe: contains `object`).
	 * @param string               $rawBody The exact raw request body, for the ledger's payload column.
	 */
	public function __construct(
		private readonly string $gateway,
		private readonly string $id,
		private readonly string $type,
		private readonly array $data,
		private readonly string $rawBody
	) {
	}

	/**
	 * The gateway id the event arrived on.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function gateway(): string {
		return $this->gateway;
	}

	/**
	 * The gateway's globally unique event id.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * The gateway's event type.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * The decoded event `data` member.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>
	 */
	public function data(): array {
		return $this->data;
	}

	/**
	 * Return the primary object data or an empty array.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>
	 */
	public function object(): array {
		$object = $this->data['object'] ?? array();

		return is_array( $object ) ? $object : array();
	}

	/**
	 * The exact raw request body the event arrived as.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function rawBody(): string {
		return $this->rawBody;
	}
}
