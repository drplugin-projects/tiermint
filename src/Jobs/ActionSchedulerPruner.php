<?php
/**
 * Daily job that keeps the bundled Action Scheduler tables from growing without bound.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Jobs;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use ActionScheduler;
use ActionScheduler_QueueCleaner;
use DrPlugin\WP\TierMint\Core\Hookable;
use DrPlugin\WP\TierMint\Database\Logger;
use Throwable;

/**
 * Daily job that prunes the bundled Action Scheduler queue.
 *
 * The queue cleaner uses the configured retention period.
 *
 * @since 1.0.0
 */
final class ActionSchedulerPruner implements Hookable {

	/**
	 * The daily prune job hook.
	 *
	 * @var string
	 */
	public const PRUNE_HOOK = 'tiermint_job_prune_action_scheduler';

	/**
	 * Action rows deleted per status in one batch.
	 *
	 * @var int
	 */
	private const BATCH_SIZE = 100;

	/**
	 * Maximum delete batches per run.
	 *
	 * @var int
	 */
	private const MAX_BATCHES = 20;

	/**
	 * Wire the pruner with the audit logger.
	 *
	 * @since 1.0.0
	 *
	 * @param Logger $logger The `_logs` audit writer (failures are logged, never thrown).
	 */
	public function __construct( private readonly Logger $logger ) {
	}

	/**
	 * Register the job handler and the init-time self-scheduler.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( self::PRUNE_HOOK, array( $this, 'onPruneJob' ) );
		add_action( 'init', array( $this, 'scheduleJobs' ) );
	}

	/**
	 * Schedule the daily prune once through Action Scheduler.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function scheduleJobs(): void {
		if ( false === as_next_scheduled_action( self::PRUNE_HOOK, array(), 'tiermint' ) ) {
			// The trailing `true` asks Action Scheduler to enforce uniqueness at
			// claim time, so two concurrent first requests cannot both schedule
			// the recurring prune.
			as_schedule_recurring_action( time(), 86400, self::PRUNE_HOOK, array(), 'tiermint', true );
		}
	}

	/**
	 * Prune old completed and canceled actions.
	 *
	 * Stop at the batch cap. Log failures instead of stopping cron.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function onPruneJob(): void {
		try {
			// ActionScheduler::store() resolves the active data store (the DB
			// store in normal operation); the cleaner's delete path talks only to
			// its sanctioned query_actions()/delete_action() API.
			$cleaner = new ActionScheduler_QueueCleaner( ActionScheduler::store(), self::BATCH_SIZE );

			for ( $batch = 0; $batch < self::MAX_BATCHES; $batch++ ) {
				$deleted = $cleaner->delete_old_actions();

				// An empty pass means nothing older than the retention remains;
				// the backlog is drained and further batches would be no-ops.
				if ( array() === $deleted ) {
					break;
				}
			}
		} catch ( Throwable $failure ) {
			$this->logger->log(
				'error',
				sprintf( 'Action Scheduler prune failed: %s', $failure->getMessage() )
			);
		}
	}
}
