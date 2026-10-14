<?php
/**
 * Hook listener wiring the licensing engine to order events and cron.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Licensing;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Core\Hookable;
use DrPlugin\WP\TierMint\Database\Logger;
use DrPlugin\WP\TierMint\Orders\Order;
use DrPlugin\WP\TierMint\Orders\OrderService;
use Throwable;

/**
 * Runs licensing tasks for order events and scheduled jobs.
 * Logs failures so payment and refund requests can complete.
 *
 * @since 1.0.0
 */
final class LicensingListener implements Hookable {

	/**
	 * The retry hook re-driving a failed mint.
	 *
	 * @var string
	 */
	public const RETRY_HOOK = 'tiermint_job_retry_license_mint';

	/**
	 * The daily expiry sweep hook.
	 *
	 * @var string
	 */
	public const EXPIRY_HOOK = 'tiermint_job_expire_licenses';

	/**
	 * Base retry delay in seconds; attempt N waits BASE * 2^N.
	 *
	 * @var int
	 */
	private const RETRY_BASE_SECONDS = 300;

	/**
	 * Maximum mint retry attempts.
	 *
	 * @var int
	 */
	private const MAX_ATTEMPTS = 5;

	/**
	 * Wire the listener with its collaborators.
	 *
	 * @since 1.0.0
	 *
	 * @param LicenseService $licenses The license domain service.
	 * @param OrderService   $orders   The order service (retries reload the order).
	 * @param Logger         $logger   The `_logs` audit writer.
	 */
	public function __construct(
		private readonly LicenseService $licenses,
		private readonly OrderService $orders,
		private readonly Logger $logger
	) {
	}

	/**
	 * Register all hook wiring for the licensing engine.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'tiermint_order_completed', array( $this, 'onOrderCompleted' ), 10, 1 );
		add_action( 'tiermint_order_refunded', array( $this, 'onOrderRefunded' ), 10, 2 );
		add_action( self::EXPIRY_HOOK, array( $this, 'onExpiryJob' ) );
		add_action( self::RETRY_HOOK, array( $this, 'onMintRetry' ), 10, 2 );
		add_action( 'init', array( $this, 'scheduleJobs' ) );
	}

	/**
	 * Mint licenses for a completed order.
	 *
	 * @since 1.0.0
	 *
	 * @param Order $order The completed order.
	 * @return void
	 */
	public function onOrderCompleted( Order $order ): void {
		try {
			$this->licenses->mintForOrder( $order );
		} catch ( Throwable $failure ) {
			$this->scheduleRetry( $order, 1, $failure );
		}
	}

	/**
	 * Retry a failed mint for a completed order.
	 *
	 * @since 1.0.0
	 *
	 * @param int $orderId The order whose mint failed.
	 * @param int $attempt Which attempt this is (1-based).
	 * @return void
	 */
	public function onMintRetry( int $orderId, int $attempt ): void {
		$order = $this->orders->get( $orderId );

		if ( null === $order || ! $order->isCompleted() ) {
			return;
		}

		try {
			$this->licenses->mintForOrder( $order );
		} catch ( Throwable $failure ) {
			if ( $attempt >= self::MAX_ATTEMPTS ) {
				$this->logger->log(
					'error',
					sprintf( 'License minting for order %d gave up after %d attempts: %s', $orderId, $attempt, $failure->getMessage() ),
					array( 'order_id' => $orderId ),
					'order',
					$orderId
				);

				return;
			}

			$this->scheduleRetry( $order, $attempt + 1, $failure );
		}
	}

	/**
	 * Apply the refund license policy without stopping the refund request.
	 *
	 * @since 1.0.0
	 *
	 * @param Order $order          The refunded order (already advanced in status).
	 * @param int   $amountRefunded The amount refunded, minor units.
	 * @return void
	 */
	public function onOrderRefunded( Order $order, int $amountRefunded ): void {
		try {
			$this->licenses->onOrderRefunded( $order, $amountRefunded );
		} catch ( Throwable $failure ) {
			$this->logger->log(
				'error',
				sprintf( 'Refund license policy failed for order %d: %s', (int) $order->id(), $failure->getMessage() ),
				array( 'order_id' => (int) $order->id() ),
				'order',
				(int) $order->id()
			);
		}
	}

	/**
	 * Run the daily expiry sweep without stopping the job queue.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function onExpiryJob(): void {
		try {
			$this->licenses->expireDueLicenses();
		} catch ( Throwable $failure ) {
			$this->logger->log(
				'error',
				sprintf( 'License expiry sweep failed: %s', $failure->getMessage() )
			);
		}
	}

	/**
	 * Schedule the daily expiry sweep once.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function scheduleJobs(): void {
		// Remove the legacy WP-Cron event to prevent duplicate sweeps.
		if ( false !== wp_next_scheduled( self::EXPIRY_HOOK ) ) {
			wp_clear_scheduled_hook( self::EXPIRY_HOOK );
		}

		if ( false === as_next_scheduled_action( self::EXPIRY_HOOK, array(), 'tiermint' ) ) {
			// Enforce a single recurring action during concurrent requests.
			as_schedule_recurring_action( time(), 86400, self::EXPIRY_HOOK, array(), 'tiermint', true );
		}
	}

	/**
	 * Log a mint failure and schedule its backed-off retry.
	 *
	 * @since 1.0.0
	 *
	 * @param Order     $order   The order whose mint failed.
	 * @param int       $attempt The attempt number the retry will carry.
	 * @param Throwable $failure What went wrong.
	 * @return void
	 */
	private function scheduleRetry( Order $order, int $attempt, Throwable $failure ): void {
		$orderId = (int) $order->id();

		$this->logger->log(
			'error',
			sprintf( 'License minting failed for order %d (retry %d scheduled): %s', $orderId, $attempt, $failure->getMessage() ),
			array(
				'order_id' => $orderId,
				'attempt'  => $attempt,
			),
			'order',
			$orderId
		);

		// Back off retries to avoid repeated database failures.
		as_schedule_single_action( time() + ( self::RETRY_BASE_SECONDS * ( 2 ** $attempt ) ), self::RETRY_HOOK, array( $orderId, $attempt ), 'tiermint' );
	}
}
