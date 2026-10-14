<?php
/**
 * Plugin deactivation handler.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Core;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Clears runtime scheduling on deactivation.
 *
 * Data deletion is only available through the opt-in uninstall path.
 *
 * @since 1.0.0
 */
final class Deactivator {

	/**
	 * Clear recurring maintenance jobs.
	 *
	 * Keep mint retries queued. They complete paid orders after reactivation.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function deactivate(): void {
		// These jobs reschedule on the next activation.
		as_unschedule_all_actions( \DrPlugin\WP\TierMint\Licensing\LicensingListener::EXPIRY_HOOK, array(), 'tiermint' );
		as_unschedule_all_actions( \DrPlugin\WP\TierMint\Jobs\ActionSchedulerPruner::PRUNE_HOOK, array(), 'tiermint' );

		// Rebuild product rewrites after the next activation.
		\DrPlugin\WP\TierMint\Frontend\ProductRewrites::clearOnDeactivate();
	}
}
