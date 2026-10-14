<?php
/**
 * Hourly job that sweeps stale pending orders to abandoned.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Jobs;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Core\Hookable;
use DrPlugin\WP\TierMint\Database\Logger;
use DrPlugin\WP\TierMint\Orders\OrderService;
use Throwable;

/**
 * Hourly job that marks unpaid pending orders as abandoned.
 *
 * Skip payment-in-flight orders. Log failures and continue.
 *
 * @since 1.0.0
 */
final class AbandonedOrdersSweep implements Hookable {

	/**
	 * The hourly sweep job hook.
	 *
	 * @var string
	 */
	public const SWEEP_HOOK = 'tiermint_job_abandoned_orders';

	/**
	 * Seconds in an hour, available without WordPress constants.
	 *
	 * @var int
	 */
	private const HOUR = 3600;

	/**
	 * Default pending-order age before abandonment.
	 *
	 * @var int
	 */
	private const DEFAULT_AGE = 48 * self::HOUR;

	/**
	 * Maximum orders per run to bound cron work.
	 *
	 * @var int
	 */
	private const BATCH_SIZE = 100;

	/**
	 * Wire the sweep with its collaborators.
	 *
	 * @since 1.0.0
	 *
	 * @param OrderService $orders The order service (query + abandon transition, which fires the hook).
	 * @param Logger       $logger The audit logger (per-order failures are logged, never thrown).
	 */
	public function __construct(
		private readonly OrderService $orders,
		private readonly Logger $logger
	) {
	}

	/**
	 * Register the job handler and the init-time self-scheduler.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( self::SWEEP_HOOK, array( $this, 'onSweepJob' ) );
		add_action( 'init', array( $this, 'scheduleJobs' ) );
	}

	/**
	 * Schedule the hourly sweep once through Action Scheduler.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function scheduleJobs(): void {
		if ( false === as_next_scheduled_action( self::SWEEP_HOOK, array(), 'tiermint' ) ) {
			// The trailing `true` asks Action Scheduler to enforce uniqueness at claim time,
			// so two concurrent first requests cannot both schedule the recurring sweep.
			as_schedule_recurring_action( time(), self::HOUR, self::SWEEP_HOOK, array(), 'tiermint', true );
		}
	}

	/**
	 * Abandon stale pending orders, one at a time.
	 *
	 * Log failures and continue the batch.
	 *
	 * @since 1.0.0
	 *
	 * @param int|null $now A Unix timestamp to age against; null uses the current time (the
	 *                      Action Scheduler job passes no argument, so this is null in production).
	 * @return void
	 */
	public function onSweepJob( ?int $now = null ): void {
		$now = null === $now ? time() : $now;

		/**
 * Filter the pending-order age before abandonment.
		 *
		 * @since 1.0.0
		 *
		 * @param int $age The age threshold in seconds (default 48 hours).
		 */
		$age    = max( self::HOUR, (int) apply_filters( 'tiermint_abandoned_order_age', self::DEFAULT_AGE ) );
		$before = gmdate( 'Y-m-d H:i:s', $now - $age );

		$orders = $this->orders->pendingForAbandonment( $before, self::BATCH_SIZE );
		$count  = 0;

		foreach ( $orders as $order ) {
			$id = (int) $order->id();

			if ( $id <= 0 ) {
				continue;
			}

			try {
				$this->orders->markAbandoned( $id );
				++$count;
			} catch ( Throwable $failure ) {
				$this->logger->log(
					'error',
					sprintf( 'Abandoned-orders sweep failed for order %d: %s', $id, $failure->getMessage() ),
					array(),
					'order',
					$id
				);
			}
		}

		if ( count( $orders ) >= self::BATCH_SIZE ) {
			// The batch was full: more stale orders likely remain for the next hourly run.
			// Logged rather than silently capped so the backlog stays visible.
			$this->logger->log(
				'order',
				sprintf( 'Abandoned-orders sweep hit its batch cap of %d; more may remain for the next run.', self::BATCH_SIZE )
			);
		}

		if ( $count > 0 ) {
			$this->logger->log( 'order', sprintf( 'Abandoned-orders sweep closed out %d stale pending order(s).', $count ) );
		}
	}
}
