<?php
/**
 * Maps the order/license meta tables onto `$wpdb` for the core metadata API.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Database;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Maps custom order and license tables for the WordPress metadata API.
 *
 * @since 1.0.0
 */
final class MetaTables {

	/**
	 * Maps the metadata tables for this request.
	 *
	 * @since 1.0.0
	 *
	 * @param \wpdb $wpdb The WordPress database abstraction to annotate.
	 * @return void
	 */
	public static function register( \wpdb $wpdb ): void {
		$wpdb->tiermint_ordermeta   = Schema::tableName( $wpdb, 'order_meta' );
		$wpdb->tiermint_licensemeta = Schema::tableName( $wpdb, 'license_meta' );
	}
}
