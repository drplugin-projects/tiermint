<?php
/**
 * Service provider for the Reports domain (basic sales reporting).
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Reports;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Core\Container;
use DrPlugin\WP\TierMint\Core\ServiceProvider;

/**
 * Register the report service.
 *
 * It resolves `$wpdb` only when the container creates the service.
 *
 * @since 1.0.0
 */
final class ReportsServiceProvider implements ServiceProvider {

	/**
	 * Bind the sales report service.
	 *
	 * @since 1.0.0
	 *
	 * @param Container $container The shared plugin container.
	 * @return void
	 */
	public function register( Container $container ): void {
		$container->singleton(
			ReportService::class,
			static function (): ReportService {
				global $wpdb;

				return new ReportService( $wpdb );
			}
		);
	}

	/**
	 * No hooks are required.
	 *
	 * @since 1.0.0
	 *
	 * @param Container $container The shared plugin container.
	 * @return void
	 */
	public function boot( Container $container ): void {
		// No Hookables to resolve or wire.
	}
}
