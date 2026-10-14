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
	'subscriptions',
	'terms',
	'term_relationships',
	'discounts',
	'releases',
	'gateway_events',
	'api_keys',
	'logs',
	'affiliates',
	'commissions',
	'recoveries',
	'recovery_emails',
	'affiliate_payouts',
);

// Inlined custom capabilities.
$tiermint_caps = array( 'manage_tiermint', 'manage_tiermint_settings', 'view_tiermint_reports' );

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

	// Fixed table names allow safe interpolation.
	foreach ( $tiermint_tables as $tiermint_base ) {
		$tiermint_table = $wpdb->prefix . 'tiermint_' . $tiermint_base;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall teardown; $tiermint_table is built from a fixed literal base name, never input, and DROP TABLE cannot be parameterized.
		$wpdb->query( 'DROP TABLE IF EXISTS `' . $tiermint_table . '`' );
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

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall teardown; every LIKE pattern is bound through prepare() below.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$tiermint_like[0],
			$tiermint_like[1],
			$tiermint_like[2],
			$tiermint_like[3],
			$tiermint_like[4]
		)
	);

	/*
	 * Remove the per-user upsell dismissals. update_user_option() stores them in
	 * usermeta under a blog-prefixed key, so the tiermint_% option sweep above
	 * never reaches them. The key literal mirrors Upsell\Dismissals::OPTION_KEY.
	 */
	$tiermint_dismiss_key = $wpdb->prefix . 'tiermint_upsell_dismissed';

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall teardown; the meta key is bound through prepare().
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s", $tiermint_dismiss_key ) );

	/*
	 * Remove the TierMint Action Scheduler group only. Its tables are shared.
	 */
	$tiermint_as_actions = $wpdb->prefix . 'actionscheduler_actions';
	$tiermint_as_groups  = $wpdb->prefix . 'actionscheduler_groups';
	$tiermint_as_logs    = $wpdb->prefix . 'actionscheduler_logs';

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall teardown; the table name is bound through prepare() for the existence probe.
	$tiermint_as_present = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tiermint_as_groups ) );

	if ( null !== $tiermint_as_present ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall teardown; the slug is bound through prepare(), the table name is a Schema-derived literal.
		$tiermint_group_id = $wpdb->get_var( $wpdb->prepare( "SELECT group_id FROM {$tiermint_as_groups} WHERE slug = %s", 'tiermint' ) );

		if ( null !== $tiermint_group_id ) {
			$tiermint_group_id = (int) $tiermint_group_id;

			// Remove TierMint scheduler rows only.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall teardown; the group id is bound through prepare(), the table names are Schema-derived literals.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$tiermint_as_logs} WHERE action_id IN ( SELECT action_id FROM {$tiermint_as_actions} WHERE group_id = %d )", $tiermint_group_id ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall teardown; the group id is bound through prepare(), the table name is a Schema-derived literal.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$tiermint_as_actions} WHERE group_id = %d", $tiermint_group_id ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall teardown; the group id is bound through prepare(), the table name is a Schema-derived literal.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$tiermint_as_groups} WHERE group_id = %d", $tiermint_group_id ) );
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
		$tiermint_files = new RecursiveIteratorIterator(
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
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- uninstall teardown of the plugin's own uploads tree; WP_Filesystem is not initialized in uninstall context.
					rmdir( $tiermint_file->getPathname() );
				}
			} else {
				wp_delete_file( $tiermint_file->getPathname() );
			}
		}

		$tiermint_entries = scandir( $tiermint_package_dir );

		if ( is_array( $tiermint_entries ) && 2 === count( $tiermint_entries ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- uninstall teardown of the plugin's own uploads tree; WP_Filesystem is not initialized in uninstall context.
			rmdir( $tiermint_package_dir );
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
	 * Let external cache values expire.
	 */

	if ( is_multisite() ) {
		restore_current_blog();
	}
}
