<?php
/**
 * The purchase receipt email definition.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Emails;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Sends the receipt for a completed order.
 *
 * @since 1.0.0
 */
final class PurchaseReceiptEmail extends Email {

	/**
	 * The registry id for the purchase receipt.
	 *
	 * @var string
	 */
	public const ID = 'purchase_receipt';

	/**
	 * The email id.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * The template key for the receipt body.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function template(): string {
		return 'purchase-receipt';
	}

	/**
	 * Build the receipt subject.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $ctx The receipt context.
	 * @return string The subject line.
	 */
	public function subject( array $ctx ): string {
		$store     = (string) ( $ctx['store_name'] ?? '' );
		$reference = (string) ( ( $ctx['order']['reference'] ?? '' ) );

		return sprintf(
			/* translators: 1: the store name, 2: the order reference number. */
			__( '%1$s: your receipt for order #%2$s', 'tiermint' ),
			$store,
			$reference
		);
	}
}
