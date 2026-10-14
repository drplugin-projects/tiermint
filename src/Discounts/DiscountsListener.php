<?php
/**
 * Records discount use after order completion.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Discounts;

// Block direct access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Core\Hookable;
use DrPlugin\WP\TierMint\Database\Logger;
use DrPlugin\WP\TierMint\Orders\Order;
use Throwable;

/**
 * Credits discounts when an order completes.
 *
 * Log and contain failures. A paid order must not fail over its use counter.
 *
 * @since 1.0.0
 */
final class DiscountsListener implements Hookable {

	/**
	 * Records discount use.
	 *
	 * @var DiscountService
	 */
	private DiscountService $discounts;

	/**
	 * Records use-counter failures.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Creates the listener.
	 *
	 * @since 1.0.0
	 *
	 * @param DiscountService $discounts Records discount use.
	 * @param Logger          $logger    Records failures.
	 */
	public function __construct( DiscountService $discounts, Logger $logger ) {
		$this->discounts = $discounts;
		$this->logger    = $logger;
	}

	/**
	 * Register the order-completed listener.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'tiermint_order_completed', array( $this, 'onOrderCompleted' ), 10, 1 );
	}

	/**
	 * Records discounts used by a completed order.
	 *
	 * Failures are logged and do not affect payment completion.
	 *
	 * @since 1.0.0
	 *
	 * @param Order $order Completed order.
	 * @return void
	 */
	public function onOrderCompleted( Order $order ): void {
		$orderId    = (int) $order->id();
		$discountId = $order->discountId();

		// Credit the order's primary discount.
		if ( null !== $discountId ) {
			$this->credit( (int) $discountId, $orderId );
		}

		// Also credit a distinct automatic discount stored for stack mode.
		$autoId = (int) get_metadata( 'tiermint_order', $orderId, '_tiermint_auto_discount_id', true );

		if ( $autoId > 0 && $autoId !== (int) $discountId ) {
			$this->credit( $autoId, $orderId );
		}
	}

	/**
	 * Records one use without affecting a paid order on failure.
	 *
	 * @since 1.0.0
	 *
	 * @param int $discountId Discount to credit.
	 * @param int $orderId    Related order.
	 * @return void
	 */
	private function credit( int $discountId, int $orderId ): void {
		try {
			$this->discounts->recordUse( $discountId );
		} catch ( Throwable $failure ) {
			$this->logger->log(
				'error',
				sprintf( 'Failed to record discount %d use for order %d: %s', $discountId, $orderId, $failure->getMessage() ),
				array(
					'discount_id' => $discountId,
					'order_id'    => $orderId,
				),
				'order',
				$orderId
			);
		}
	}
}
