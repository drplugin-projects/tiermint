<?php
/**
 * TierMint uninstall handler.
 *
 * Delete opted-in data. Check each multisite site.
 *
 * This file has no plugin autoloader.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

// Only ever run in WordPress' uninstall context.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/*
 * On one site, stop before the sweep without explicit consent.
 */
if ( ! is_multisite() ) {
	$tiermint_settings = get_option( 'tiermint_settings' );

	if ( ! is_array( $tiermint_settings ) || empty( $tiermint_settings['delete_data_on_uninstall'] ) ) {
		return;
	}
}

/*
 * Keep this list in sync with Schema::tables().
 */
$tiermint_tables = array(
	'products',
	'product_prices',
	'bundle_items',
	'orders',
	'order_items',
	'order_meta',
	'customers',
	'licenses',
	'license_meta',
	'activations',
	'terms',
	'term_relationships',
	'discounts',
	'releases',
	'gateway_events',
	'api_keys',
	'logs',
);

// Inlined custom capabilities.
$tiermint_caps = array( 'manage_tiermint', 'manage_tiermint_settings', 'view_tiermint_reports' );

// Object cache groups the plugin's classes keep rows in (their CACHE_GROUP constants).
$tiermint_cache_groups = array(
	'tiermint',
	'tiermint_activations',
	'tiermint_customers',
	'tiermint_discounts',
	'tiermint_licenses',
	'tiermint_orders',
	'tiermint_products',
	'tiermint_terms',
);

/*
 * Get the direct WP_Filesystem, or null when WordPress would use another method.
 * An instance WordPress already set up, for example while it deletes plugins over
 * FTP, is reused only when it is direct and is never replaced.
 */
$tiermint_direct_filesystem = static function ( string $context ) {
	global $wp_filesystem;

	if ( $wp_filesystem instanceof WP_Filesystem_Base ) {
		return 'direct' === $wp_filesystem->method ? $wp_filesystem : null;
	}

	if ( ! function_exists( 'get_filesystem_method' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	// PHP writes the uploads folder itself, so file ownership is not checked.
	if ( 'direct' !== get_filesystem_method( array(), $context, true ) || ! WP_Filesystem( false, $context, true ) ) {
		return null;
	}

	return $wp_filesystem instanceof WP_Filesystem_Base ? $wp_filesystem : null;
};

/*
 * Process each site because its data and consent are site-specific.
 */
if ( is_multisite() ) {
	$tiermint_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
} else {
	$tiermint_site_ids = array( get_current_blog_id() );
}

foreach ( $tiermint_site_ids as $tiermint_site_id ) {
	if ( is_multisite() ) {
		switch_to_blog( (int) $tiermint_site_id );

		// Sweep this site only after its own consent check.
		$tiermint_settings = get_option( 'tiermint_settings' );

		if ( ! is_array( $tiermint_settings ) || empty( $tiermint_settings['delete_data_on_uninstall'] ) ) {
			restore_current_blog();
			continue;
		}
	}

	global $wpdb;

	foreach ( $tiermint_tables as $tiermint_base ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall drops the tiermint_ tables.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'tiermint_' . $tiermint_base ) );
	}

	/*
	 * Drop any other table under this site's TierMint prefix, such as one an
	 * extension or an older version left. Escaping keeps `_` literal, so only
	 * this site's tiermint_ tables match.
	 */
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup, nothing to cache.
	$tiermint_leftovers = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'tiermint_' ) . '%' ) );

	foreach ( $tiermint_leftovers as $tiermint_table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall drops the tiermint_ tables.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', (string) $tiermint_table ) );
	}

	/*
	 * Delete provisioned storefront pages. The ids come from the settings read
	 * above, before the options sweep below wipes the pointer. Only pages that
	 * are both tracked in settings and still carry the provisioning marker are
	 * removed: a page the merchant picked by hand is tracked but unmarked, so it
	 * survives. The marker literal is inlined because uninstall.php has no
	 * autoloader (see PageProvisioner::MARKER_META).
	 */
	$tiermint_pages = ( isset( $tiermint_settings['pages'] ) && is_array( $tiermint_settings['pages'] ) )
		? $tiermint_settings['pages']
		: array();

	foreach ( $tiermint_pages as $tiermint_page_id ) {
		$tiermint_page_id = (int) $tiermint_page_id;

		if ( $tiermint_page_id <= 0 ) {
			continue;
		}

		// Only pages TierMint provisioned carry this marker.
		if ( '' === (string) get_post_meta( $tiermint_page_id, '_tiermint_page', true ) ) {
			continue;
		}

		// Force delete so the marker meta goes with the page.
		wp_delete_post( $tiermint_page_id, true );
	}

	/*
	 * Remove TierMint options, secrets, and transient value and timeout rows.
	 */
	$tiermint_like = array(
		$wpdb->esc_like( 'tiermint_' ) . '%',
		$wpdb->esc_like( '_transient_tiermint_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_tiermint_' ) . '%',
		$wpdb->esc_like( '_site_transient_tiermint_' ) . '%',
		$wpdb->esc_like( '_site_transient_timeout_tiermint_' ) . '%',
	);

	// The rows go by raw SQL, so note their names to clear the cache below.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup, nothing to cache.
	$tiermint_option_names = $wpdb->get_col(
		$wpdb->prepare(
			'SELECT option_name FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s',
			$wpdb->options,
			$tiermint_like[0],
			$tiermint_like[1],
			$tiermint_like[2],
			$tiermint_like[3],
			$tiermint_like[4]
		)
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup, nothing to cache.
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s',
			$wpdb->options,
			$tiermint_like[0],
			$tiermint_like[1],
			$tiermint_like[2],
			$tiermint_like[3],
			$tiermint_like[4]
		)
	);

	// A cached schema version must not outlive its tables.
	foreach ( $tiermint_option_names as $tiermint_option_name ) {
		wp_cache_delete( (string) $tiermint_option_name, 'options' );
	}
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );

	/*
	 * Remove the per-user upsell dismissals. update_user_option() stores them in
	 * usermeta under a blog-prefixed key, so the tiermint_% option sweep above
	 * never reaches them. The key literal mirrors Upsell\Dismissals::OPTION_KEY.
	 */
	$tiermint_dismiss_key = $wpdb->prefix . 'tiermint_upsell_dismissed';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup, nothing to cache.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key = %s', $wpdb->usermeta, $tiermint_dismiss_key ) );

	/*
	 * Remove the TierMint Action Scheduler group only. Its tables are shared.
	 */
	$tiermint_as_actions = $wpdb->prefix . 'actionscheduler_actions';
	$tiermint_as_groups  = $wpdb->prefix . 'actionscheduler_groups';
	$tiermint_as_logs    = $wpdb->prefix . 'actionscheduler_logs';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup, nothing to cache.
	$tiermint_as_present = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $tiermint_as_groups ) ) );

	if ( null !== $tiermint_as_present ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup, nothing to cache.
		$tiermint_group_id = $wpdb->get_var( $wpdb->prepare( 'SELECT group_id FROM %i WHERE slug = %s', $tiermint_as_groups, 'tiermint' ) );

		if ( null !== $tiermint_group_id ) {
			$tiermint_group_id = (int) $tiermint_group_id;

			// Remove TierMint scheduler rows only.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup, nothing to cache.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE action_id IN ( SELECT action_id FROM %i WHERE group_id = %d )', $tiermint_as_logs, $tiermint_as_actions, $tiermint_group_id ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup, nothing to cache.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE group_id = %d', $tiermint_as_actions, $tiermint_group_id ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup, nothing to cache.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE group_id = %d', $tiermint_as_groups, $tiermint_group_id ) );

			// Action Scheduler caches group ids by slug; drop the deleted one.
			wp_cache_delete( 'tiermint', 'action_scheduler_groups' );
		}
	}

	/*
	 * Remove legacy TierMint WP-Cron events.
	 */
	$tiermint_cron = _get_cron_array();

	if ( is_array( $tiermint_cron ) ) {
		$tiermint_cron_hooks = array();

		foreach ( $tiermint_cron as $tiermint_events ) {
			if ( ! is_array( $tiermint_events ) ) {
				continue;
			}

			foreach ( array_keys( $tiermint_events ) as $tiermint_hook ) {
				if ( 0 === strncmp( (string) $tiermint_hook, 'tiermint_', 9 ) ) {
					$tiermint_cron_hooks[ $tiermint_hook ] = true;
				}
			}
		}

		foreach ( array_keys( $tiermint_cron_hooks ) as $tiermint_hook ) {
			wp_unschedule_hook( (string) $tiermint_hook );
		}
	}

	/*
	 * Remove only the current site's TierMint upload directory.
	 */
	$tiermint_uploads     = wp_upload_dir();
	$tiermint_package_dir = trailingslashit( $tiermint_uploads['basedir'] ) . 'tiermint';

	if ( is_dir( $tiermint_package_dir ) ) {
		$tiermint_filesystem = $tiermint_direct_filesystem( $tiermint_package_dir );
		$tiermint_files      = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $tiermint_package_dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $tiermint_files as $tiermint_file ) {
			if ( $tiermint_file->isDir() ) {
				/*
				 * Remove only confirmed empty directories. Ignore filesystem failures.
				 */
				$tiermint_entries = scandir( $tiermint_file->getPathname() );

				if ( is_array( $tiermint_entries ) && 2 === count( $tiermint_entries ) ) {
					if ( null !== $tiermint_filesystem ) {
						$tiermint_filesystem->rmdir( $tiermint_file->getPathname() );
					} else {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Another filesystem method needs credentials; PHP can remove its own empty upload folders.
						rmdir( $tiermint_file->getPathname() );
					}
				}
			} else {
				wp_delete_file( $tiermint_file->getPathname() );
			}
		}

		$tiermint_entries = scandir( $tiermint_package_dir );

		if ( is_array( $tiermint_entries ) && 2 === count( $tiermint_entries ) ) {
			if ( null !== $tiermint_filesystem ) {
				$tiermint_filesystem->rmdir( $tiermint_package_dir );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Another filesystem method needs credentials; PHP can remove its own empty upload folders.
				rmdir( $tiermint_package_dir );
			}
		}
	}

	/*
	 * Remove custom capabilities.
	 */
	foreach ( wp_roles()->role_objects as $tiermint_role ) {
		foreach ( $tiermint_caps as $tiermint_cap ) {
			if ( $tiermint_role->has_cap( $tiermint_cap ) ) {
				$tiermint_role->remove_cap( $tiermint_cap );
			}
		}
	}

	/*
	 * Flush the plugin's cache groups so no row outlives its dropped table. Without
	 * group flushes, those rows and other cached values, such as transients in an
	 * external object cache, expire on their own.
	 */
	if ( wp_cache_supports( 'flush_group' ) ) {
		foreach ( $tiermint_cache_groups as $tiermint_cache_group ) {
			wp_cache_flush_group( $tiermint_cache_group );
		}
	}

	if ( is_multisite() ) {
		restore_current_blog();
	}
}
