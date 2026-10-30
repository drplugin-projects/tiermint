<?php
/**
 * Plugin Name:       TierMint
 * Plugin URI:        https://github.com/drplugin-projects/tiermint
 * Description:       Sell digital products with licensing, updates, and Stripe payments, right from WordPress.
 * Version:           1.0.0
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Author:            DrPlugin
 * Author URI:        https://drplugin.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tiermint
 * Domain Path:       /languages
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Keep TIERMINT_VERSION equal to the plugin header version.
define( 'TIERMINT_VERSION', '1.0.0' );
define( 'TIERMINT_FILE', __FILE__ );
define( 'TIERMINT_PATH', plugin_dir_path( __FILE__ ) );
define( 'TIERMINT_URL', plugin_dir_url( __FILE__ ) );

/*
 * Keep this equal to the highest migration version.
 */
define( 'TIERMINT_DB_VERSION', 4 );

/**
 * Autoload TierMint classes.
 *
 * TierMint's own classes load without Composer's autoloader.
 *
 * @since 1.0.0
 *
 * @param string $class_name Fully-qualified class name being autoloaded.
 * @return void
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'DrPlugin\\WP\\TierMint\\';
		$length = strlen( $prefix );

		/* Ignore non-core classes. */
		if ( strncmp( $prefix, $class_name, $length ) !== 0 ) {
			return;
		}

		$relative = substr( $class_name, $length );
		$path     = __DIR__ . '/src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

/*
 * Load the bundled Action Scheduler job queue.
 *
 * Action Scheduler shares a version registry.
 */
require_once __DIR__ . '/lib/action-scheduler/action-scheduler.php';

/**
 * Access the shared TierMint plugin instance.
 *
 * Build on first use. Block render files reach the container through it; extensions can too.
 *
 * @since 1.0.0
 *
 * @return \DrPlugin\WP\TierMint\Core\Plugin The plugin instance.
 */
function tiermint(): \DrPlugin\WP\TierMint\Core\Plugin {
	static $plugin = null;

	if ( null === $plugin ) {
		$plugin = new \DrPlugin\WP\TierMint\Core\Plugin(
			TIERMINT_FILE,
			new \DrPlugin\WP\TierMint\Core\Container(),
			array(
				new \DrPlugin\WP\TierMint\Core\CoreServiceProvider(),
				new \DrPlugin\WP\TierMint\Database\DatabaseServiceProvider(),
				new \DrPlugin\WP\TierMint\Storage\StorageServiceProvider(),
				new \DrPlugin\WP\TierMint\Products\ProductsServiceProvider(),
				new \DrPlugin\WP\TierMint\Customers\CustomersServiceProvider(),
				new \DrPlugin\WP\TierMint\Orders\OrdersServiceProvider(),
				new \DrPlugin\WP\TierMint\Discounts\DiscountsServiceProvider(),
				new \DrPlugin\WP\TierMint\Payments\PaymentsServiceProvider(),
				new \DrPlugin\WP\TierMint\Licensing\LicensingServiceProvider(),
				new \DrPlugin\WP\TierMint\Updates\UpdatesServiceProvider(),
				new \DrPlugin\WP\TierMint\Emails\EmailsServiceProvider(),
				new \DrPlugin\WP\TierMint\Reports\ReportsServiceProvider(),
				new \DrPlugin\WP\TierMint\Webhooks\WebhooksServiceProvider(),
				new \DrPlugin\WP\TierMint\Jobs\JobsServiceProvider(),
				new \DrPlugin\WP\TierMint\Privacy\PrivacyServiceProvider(),
				new \DrPlugin\WP\TierMint\Frontend\FrontendServiceProvider(),
				new \DrPlugin\WP\TierMint\REST\RestServiceProvider(),
				new \DrPlugin\WP\TierMint\Admin\AdminServiceProvider(),
				new \DrPlugin\WP\TierMint\Upsell\UpsellServiceProvider(),
			)
		);
	}

	return $plugin;
}

// Register hooks. WordPress boots the plugin on plugins_loaded.
tiermint()->hooks();
