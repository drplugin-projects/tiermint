<?php
/**
 * Canonical definitions of every custom TierMint table.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Database;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Canonical table definitions for dbDelta.
 *
 * Indexed utf8mb4 VARCHAR columns fit legacy InnoDB prefix limits. Money uses minor
 * units and timestamps use UTC datetime values.
 *
 * @since 1.0.0
 */
final class Schema {

	/**
	 * Base table names in creation order.
	 *
	 * Table methods add the prefixes.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, string> Base table names without any prefix.
	 */
	public static function tables(): array {
		return array(
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
	}

	/**
	 * The fully-qualified table name for a base name (`{$prefix}tiermint_{$base}`).
	 *
	 * @since 1.0.0
	 *
	 * @param \wpdb  $wpdb The WordPress database abstraction (for the site prefix).
	 * @param string $base A base name from {@see tables()}.
	 * @return string The prefixed, TierMint-namespaced table name.
	 */
	public static function tableName( \wpdb $wpdb, string $base ): string {
		return $wpdb->prefix . 'tiermint_' . $base;
	}

	/**
	 * Return dbDelta-formatted CREATE TABLE statements by table name.
	 *
	 * @since 1.0.0
	 *
	 * @param \wpdb $wpdb The WordPress database abstraction.
	 * @return array<string, string> Map of table name => CREATE TABLE SQL.
	 */
	public static function statements( \wpdb $wpdb ): array {
		$charset    = $wpdb->get_charset_collate();
		$statements = array();

		foreach ( self::bodies() as $base => $body ) {
			$name                = self::tableName( $wpdb, $base );
			$statements[ $name ] = "CREATE TABLE {$name} (\n{$body}\n) {$charset};";
		}

		return $statements;
	}

	/**
	 * Return CREATE TABLE bodies by base name.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string> Map of base name => CREATE TABLE inner body.
	 */
	private static function bodies(): array {
		return array(
			'products'           => <<<'SQL'
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL,
			slug varchar(191) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'draft',
			type varchar(20) NOT NULL DEFAULT 'digital',
			overview longtext,
			description longtext,
			is_licensed tinyint(1) NOT NULL DEFAULT 1,
			update_slug varchar(191) DEFAULT NULL,
			files longtext,
			settings longtext,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			UNIQUE KEY update_slug (update_slug),
			KEY status (status)
			SQL,

			'product_prices'     => <<<'SQL'
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			product_id bigint(20) unsigned NOT NULL,
			name varchar(200) NOT NULL,
			amount bigint(20) NOT NULL,
			activation_limit int(10) unsigned DEFAULT NULL,
			license_length varchar(20) DEFAULT '+1 year',
			is_subscription tinyint(1) NOT NULL DEFAULT 0,
			billing_period varchar(10) DEFAULT NULL,
			billing_interval tinyint(3) unsigned DEFAULT NULL,
			trial_days smallint(5) unsigned NOT NULL DEFAULT 0,
			sort_order smallint(5) unsigned NOT NULL DEFAULT 0,
			min_quantity smallint(5) unsigned NOT NULL DEFAULT 1,
			max_quantity smallint(5) unsigned DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY product_id (product_id)
			SQL,

			// composite PK, no surrogate id.
			'bundle_items'       => <<<'SQL'
			bundle_product_id bigint(20) unsigned NOT NULL,
			child_product_id bigint(20) unsigned NOT NULL,
			child_price_id bigint(20) unsigned DEFAULT NULL,
			sort_order smallint(5) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (bundle_product_id,child_product_id),
			KEY child_product (child_product_id)
			SQL,

			'orders'             => <<<'SQL'
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			uuid char(36) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			customer_id bigint(20) unsigned DEFAULT NULL,
			email varchar(190) NOT NULL,
			currency char(3) NOT NULL,
			subtotal bigint(20) NOT NULL DEFAULT 0,
			discount_total bigint(20) NOT NULL DEFAULT 0,
			tax_total bigint(20) NOT NULL DEFAULT 0,
			total bigint(20) NOT NULL DEFAULT 0,
			gateway varchar(32) DEFAULT NULL,
			gateway_txn_id varchar(191) DEFAULT NULL,
			discount_id bigint(20) unsigned DEFAULT NULL,
			discount_code varchar(50) DEFAULT NULL,
			type varchar(20) NOT NULL DEFAULT 'purchase',
			parent_id bigint(20) unsigned DEFAULT NULL,
			ip_address varchar(45) DEFAULT NULL,
			country char(2) DEFAULT NULL,
			created_at datetime NOT NULL,
			completed_at datetime DEFAULT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uuid (uuid),
			KEY status (status),
			KEY customer_id (customer_id),
			KEY email (email),
			KEY gateway_txn_id (gateway_txn_id),
			KEY parent_id (parent_id),
			KEY created_at (created_at),
			KEY status_completed (status,completed_at)
			SQL,

			'order_items'        => <<<'SQL'
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned NOT NULL,
			product_id bigint(20) unsigned NOT NULL,
			price_id bigint(20) unsigned DEFAULT NULL,
			product_name varchar(255) NOT NULL,
			price_name varchar(200) DEFAULT NULL,
			quantity smallint(5) unsigned NOT NULL DEFAULT 1,
			subtotal bigint(20) NOT NULL DEFAULT 0,
			discount bigint(20) NOT NULL DEFAULT 0,
			tax bigint(20) NOT NULL DEFAULT 0,
			total bigint(20) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY order_id (order_id),
			KEY product_id (product_id)
			SQL,

			// WP-style meta table.
			'order_meta'         => <<<'SQL'
			meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			tiermint_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			meta_key varchar(191) DEFAULT NULL,
			meta_value longtext,
			PRIMARY KEY  (meta_id),
			KEY tiermint_order_id (tiermint_order_id),
			KEY meta_key (meta_key),
			KEY meta_key_order (meta_key, tiermint_order_id)
			SQL,

			'customers'          => <<<'SQL'
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned DEFAULT NULL,
			email varchar(190) NOT NULL,
			name varchar(200) DEFAULT NULL,
			first_name varchar(200) DEFAULT NULL,
			last_name varchar(200) DEFAULT NULL,
			purchase_count int(10) unsigned NOT NULL DEFAULT 0,
			purchase_total bigint(20) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_id (user_id),
			UNIQUE KEY email (email)
			SQL,

			// the hot-path table.
			'licenses'           => <<<'SQL'
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			license_key char(35) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			product_id bigint(20) unsigned NOT NULL,
			price_id bigint(20) unsigned DEFAULT NULL,
			order_id bigint(20) unsigned NOT NULL,
			order_item_id bigint(20) unsigned DEFAULT NULL,
			customer_id bigint(20) unsigned NOT NULL,
			activation_limit int(10) unsigned DEFAULT NULL,
			activation_count int(10) unsigned NOT NULL DEFAULT 0,
			expires_at datetime DEFAULT NULL,
			parent_id bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY license_key (license_key),
			KEY status_expires (status,expires_at),
			KEY product_id (product_id),
			KEY order_id (order_id),
			KEY customer_id (customer_id),
			KEY parent_id (parent_id)
			SQL,

			// WP-style meta table.
			'license_meta'       => <<<'SQL'
			meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			tiermint_license_id bigint(20) unsigned NOT NULL DEFAULT 0,
			meta_key varchar(191) DEFAULT NULL,
			meta_value longtext,
			PRIMARY KEY  (meta_id),
			KEY tiermint_license_id (tiermint_license_id),
			KEY meta_key (meta_key)
			SQL,

			'activations'        => <<<'SQL'
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			license_id bigint(20) unsigned NOT NULL,
			site_url varchar(191) NOT NULL,
			site_hash char(64) NOT NULL,
			fingerprint varchar(64) DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			is_local tinyint(1) NOT NULL DEFAULT 0,
			environment longtext,
			activated_at datetime NOT NULL,
			deactivated_at datetime DEFAULT NULL,
			last_checked_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY license_site (license_id,site_hash),
			KEY last_checked_at (last_checked_at)
			SQL,

			// core-created, Pro-written.
			'subscriptions'      => <<<'SQL'
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			customer_id bigint(20) unsigned NOT NULL,
			product_id bigint(20) unsigned NOT NULL,
			price_id bigint(20) unsigned DEFAULT NULL,
			initial_order_id bigint(20) unsigned NOT NULL,
			license_id bigint(20) unsigned DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			gateway varchar(32) NOT NULL,
			gateway_subscription_id varchar(191) DEFAULT NULL,
			gateway_customer_id varchar(191) DEFAULT NULL,
			amount bigint(20) NOT NULL,
			currency char(3) NOT NULL,
			billing_period varchar(10) NOT NULL,
			billing_interval tinyint(3) unsigned NOT NULL DEFAULT 1,
			trial_ends_at datetime DEFAULT NULL,
			current_period_end datetime DEFAULT NULL,
			cancel_at_period_end tinyint(1) NOT NULL DEFAULT 0,
			cancelled_at datetime DEFAULT NULL,
			pending_price_id bigint(20) unsigned DEFAULT NULL,
			pending_change_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY gateway_subscription_id (gateway_subscription_id),
			KEY customer_id (customer_id),
			KEY product_id (product_id),
			KEY license_id (license_id),
			KEY status_period (status,current_period_end),
			KEY gateway_customer_id (gateway_customer_id)
			SQL,

			// custom taxonomy store (category|tag discriminator).
			'terms'              => <<<'SQL'
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			taxonomy char(8) NOT NULL,
			name varchar(200) NOT NULL,
			slug varchar(183) NOT NULL,
			parent bigint(20) unsigned NOT NULL DEFAULT 0,
			description text,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY taxonomy_slug (taxonomy,slug),
			KEY parent (parent)
			SQL,

			// product<->term join, composite PK.
			'term_relationships' => <<<'SQL'
			term_id bigint(20) unsigned NOT NULL,
			product_id bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (term_id,product_id),
			KEY product_term (product_id,term_id)
			SQL,

			'discounts'          => <<<'SQL'
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			code varchar(50) NOT NULL,
			name varchar(200) DEFAULT NULL,
			type varchar(10) NOT NULL DEFAULT 'percent',
			amount_percent decimal(5,2) DEFAULT NULL,
			amount_fixed bigint(20) DEFAULT NULL,
			product_ids longtext,
			min_total bigint(20) DEFAULT NULL,
			max_uses int(10) unsigned DEFAULT NULL,
			use_count int(10) unsigned NOT NULL DEFAULT 0,
			once_per_customer tinyint(1) NOT NULL DEFAULT 0,
			starts_at datetime DEFAULT NULL,
			expires_at datetime DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			auto tinyint(1) NOT NULL DEFAULT 0,
			scope_type varchar(12) NOT NULL DEFAULT 'all',
			category_ids longtext,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY code (code),
			KEY status (status),
			KEY auto_status (auto,status)
			SQL,

			// canonical package/version source.
			'releases'           => <<<'SQL'
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			product_id bigint(20) unsigned NOT NULL,
			version varchar(32) NOT NULL,
			channel varchar(20) NOT NULL DEFAULT 'stable',
			storage_provider varchar(32) NOT NULL DEFAULT 'local',
			storage_key varchar(500) NOT NULL,
			filesize bigint(20) unsigned DEFAULT NULL,
			checksum_sha256 char(64) DEFAULT NULL,
			requires_wp varchar(12) DEFAULT NULL,
			tested_up_to varchar(12) DEFAULT NULL,
			requires_php varchar(12) DEFAULT NULL,
			changelog longtext,
			rollout_percent tinyint(3) unsigned NOT NULL DEFAULT 100,
			status varchar(20) NOT NULL DEFAULT 'draft',
			released_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY product_version (product_id,version),
			KEY latest_lookup (product_id,channel,status,released_at)
			SQL,

			// inbound webhook idempotency ledger.
			'gateway_events'     => <<<'SQL'
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			gateway varchar(20) NOT NULL,
			event_id varchar(170) NOT NULL,
			event_type varchar(64) NOT NULL,
			payload longtext,
			status varchar(20) NOT NULL DEFAULT 'received',
			error text,
			claimed_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY gateway_event (gateway,event_id),
			KEY created_at (created_at)
			SQL,

			// management-API credentials.
			'api_keys'           => <<<'SQL'
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			label varchar(200) NOT NULL,
			public_key varchar(64) NOT NULL,
			secret_hash varchar(255) NOT NULL,
			permissions varchar(10) NOT NULL DEFAULT 'read',
			last_used_at datetime DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY public_key (public_key),
			KEY user_id (user_id)
			SQL,

			// unified append-only log.
			'logs'               => <<<'SQL'
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(32) NOT NULL,
			object_type varchar(32) DEFAULT NULL,
			object_id bigint(20) unsigned DEFAULT NULL,
			message text NOT NULL,
			context longtext,
			ip_address varchar(45) DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY type (type),
			KEY object (object_type,object_id),
			KEY created_at (created_at)
			SQL,

			// affiliate program: one row per affiliate. Core-created, Pro-written.
			'affiliates'         => <<<'SQL'
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			customer_id bigint(20) unsigned NOT NULL,
			code varchar(32) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			rate_bps int(10) unsigned DEFAULT NULL,
			payout_note varchar(255) DEFAULT NULL,
			payout_email varchar(190) DEFAULT NULL,
			payout_method varchar(20) DEFAULT NULL,
			approved_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY customer_id (customer_id),
			UNIQUE KEY code (code),
			KEY status (status)
			SQL,

			// affiliate program: the commission ledger, one row per attributed order.
			'commissions'        => <<<'SQL'
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			affiliate_id bigint(20) unsigned NOT NULL,
			order_id bigint(20) unsigned NOT NULL,
			currency char(3) NOT NULL,
			base_amount bigint(20) NOT NULL DEFAULT 0,
			rate_bps int(10) unsigned NOT NULL DEFAULT 0,
			amount bigint(20) NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'pending',
			payout_id bigint(20) unsigned DEFAULT NULL,
			paid_at datetime DEFAULT NULL,
			reversed_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY order_id (order_id),
			KEY affiliate_id (affiliate_id),
			KEY status (status),
			KEY payout_id (payout_id),
			KEY created_at (created_at)
			SQL,

			// cart recovery: one row per recovery-tracked order (the drip state machine). Core-created, Pro-written.
			'recoveries'         => <<<'SQL'
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			steps_sent tinyint(3) unsigned NOT NULL DEFAULT 0,
			last_step_at datetime DEFAULT NULL,
			recovered_order_id bigint(20) unsigned DEFAULT NULL,
			recovered_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY order_id (order_id),
			KEY status (status),
			KEY created_at (created_at),
			KEY last_step_at (last_step_at)
			SQL,

			// cart recovery: the append-only per-step send ledger. Core-created, Pro-written.
			'recovery_emails'    => <<<'SQL'
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			recovery_id bigint(20) unsigned NOT NULL,
			step tinyint(3) unsigned NOT NULL,
			discount_id bigint(20) unsigned DEFAULT NULL,
			sent_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY recovery_step (recovery_id,step),
			KEY sent_at (sent_at),
			KEY step (step)
			SQL,

			// affiliate payouts: one row per affiliate per currency per run. Core-created, Pro-written.
			'affiliate_payouts'  => <<<'SQL'
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_id char(32) NOT NULL,
			affiliate_id bigint(20) unsigned NOT NULL,
			provider varchar(20) NOT NULL,
			currency char(3) NOT NULL,
			amount bigint(20) NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'queued',
			sender_batch_id varchar(64) NOT NULL,
			sender_item_id varchar(64) NOT NULL,
			provider_batch_id varchar(64) DEFAULT NULL,
			provider_item_id varchar(64) DEFAULT NULL,
			payout_email varchar(190) DEFAULT NULL,
			error text,
			note varchar(255) DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			paid_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY sender_item_id (sender_item_id),
			KEY run_id (run_id),
			KEY affiliate_id (affiliate_id),
			KEY status (status),
			KEY sender_batch_id (sender_batch_id),
			KEY provider_batch_id (provider_batch_id),
			KEY created_at (created_at)
			SQL,
		);
	}
}
