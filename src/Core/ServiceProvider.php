<?php
/**
 * Contract for a domain module's registration unit.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Core;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Contract for a domain service provider.
 *
 * All providers register before any provider boots.
 *
 * @since 1.0.0
 */
interface ServiceProvider {

	/**
	 * Bind services without side effects.
	 *
	 * Do not call WordPress here. This method must be safe to run twice.
	 *
	 * @since 1.0.0
	 *
	 * @param Container $container The shared plugin container.
	 * @return void
	 */
	public function register( Container $container ): void;

	/**
	 * Wire domain hooks after all providers register.
	 *
	 * @since 1.0.0
	 *
	 * @param Container $container The shared plugin container.
	 * @return void
	 */
	public function boot( Container $container ): void;
}
