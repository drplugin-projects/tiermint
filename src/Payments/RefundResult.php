<?php
/**
 * Immutable DTO for a gateway-side refund outcome.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Payments;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Gateway refund data for the local refund ledger.
 *
 * @since 1.0.0
 */
final class RefundResult {

	/**
	 * Wire up every field of the result.
	 *
	 * @since 1.0.0
	 *
	 * @param string $transactionId The gateway's refund transaction id (Stripe: "re_...").
	 * @param int    $amount        The refunded amount in minor currency units.
	 * @param string $status        The gateway's refund status string, e.g. 'succeeded' or 'pending'.
	 */
	public function __construct(
		private readonly string $transactionId,
		private readonly int $amount,
		private readonly string $status
	) {
	}

	/**
	 * The gateway's refund transaction id.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function transactionId(): string {
		return $this->transactionId;
	}

	/**
	 * The refunded amount in minor currency units.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function amount(): int {
		return $this->amount;
	}

	/**
	 * The gateway's refund status string.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function status(): string {
		return $this->status;
	}
}
