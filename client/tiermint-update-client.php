<?php
/**
 * Adds WordPress updates for a TierMint product.
 *
 * Rename the class when another bundled copy can load.
 *
 * @package DrPlugin\WP\TierMint
 */

// Block direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Allow another bundled copy to load first.
if ( ! class_exists( 'TierMint_Update_Client' ) ) {

	/**
	 * Connects a plugin to the TierMint update API.
	 *
	 * @since 1.0.0
	 */
	class TierMint_Update_Client {

		/**
		 * Transient key prefix for the 12-hour freshness cache.
		 *
		 * @var string
		 */
		const CACHE_PREFIX = 'tiermint_uc_';

		/**
		 * Transient key prefix for the 72-hour last-known-good grace store.
		 *
		 * @var string
		 */
		const GRACE_PREFIX = 'tiermint_ucg_';

		/**
		 * Freshness window in hours: within it, no network call is made.
		 *
		 * @var int
		 */
		const FRESH_HOURS = 12;

		/**
		 * Default grace window after a failed store request.
		 *
		 * @var int
		 */
		const DEFAULT_GRACE_HOURS = 72;

		/**
		 * HTTP timeout in seconds for the update-check request.
		 *
		 * @var int
		 */
		const HTTP_TIMEOUT = 15;

		/**
		 * Store API base URL.
		 *
		 * @var string
		 */
		private $apiUrl;

		/**
		 * The product's catalog slug, the identifier the store matches on.
		 *
		 * @var string
		 */
		private $productSlug;

		/**
		 * Absolute path to the host plugin file.
		 *
		 * @var string
		 */
		private $pluginFile;

		/**
		 * The license key credential sent with every update-check.
		 *
		 * @var string
		 */
		private $licenseKey;

		/**
		 * Optional release channel to ask the store for.
		 *
		 * @var string
		 */
		private $channel;

		/**
		 * Per-request memo of the installed version read from the plugin header.
		 *
		 * @var string|null
		 */
		private $installedVersionCache = null;

		/**
		 * Configure the client and register filters.
		 *
		 * @since 1.0.0
		 *
		 * @param array $options {
		 *     Configuration.
		 *
		 *     @type string $api_url      The store's `tiermint/v1` REST base URL.
		 *     @type string $product_slug The product's catalog slug.
		 *     @type string $plugin_file  The host plugin's `__FILE__`.
		 *     @type string $license_key  The license key credential (optional; empty allowed).
		 *     @type string $channel      The release channel to ask for (optional). A store that
		 *                                runs TierMint Pro reads it; other stores ignore it.
		 * }
		 */
		public function __construct( array $options ) {
			$this->apiUrl      = untrailingslashit( isset( $options['api_url'] ) ? (string) $options['api_url'] : '' );
			$this->productSlug = isset( $options['product_slug'] ) ? (string) $options['product_slug'] : '';
			$this->pluginFile  = isset( $options['plugin_file'] ) ? (string) $options['plugin_file'] : '';
			$this->licenseKey  = isset( $options['license_key'] ) ? (string) $options['license_key'] : '';
			$this->channel     = isset( $options['channel'] ) ? (string) $options['channel'] : '';

			add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'filterUpdatePlugins' ) );
			add_filter( 'plugins_api', array( $this, 'filterPluginsApi' ), 10, 3 );
			add_filter( 'upgrader_pre_download', array( $this, 'filterUpgraderPreDownload' ), 10, 4 );
		}

		/**
		 * Add this product to the plugin update transient.
		 *
		 * @since 1.0.0
		 *
		 * @param mixed $transient The `update_plugins` transient value (object, or false early on).
		 * @return object The transient with this product's slot populated.
		 */
		public function filterUpdatePlugins( $transient ) {
			if ( ! is_object( $transient ) ) {
				$transient = new stdClass();
			}

			$payload = $this->getUpdateData();

			if ( null === $payload ) {
				return $transient;
			}

			if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
				$transient->response = array();
			}

			if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
				$transient->no_update = array();
			}

			$basename  = plugin_basename( $this->pluginFile );
			$installed = $this->installedVersion();

			// A cached verdict can refer to the previous installed version.
			if ( isset( $payload['new_version'] ) && '' !== (string) $payload['new_version'] ) {
				$isUpdate = version_compare( (string) $payload['new_version'], $installed, '>' );
			} else {
				$isUpdate = ! empty( $payload['update_available'] );
			}

			$entry = $this->pluginEntry( $payload, $basename, $installed, $isUpdate );

			if ( $isUpdate ) {
				$transient->response[ $basename ] = $entry;
				unset( $transient->no_update[ $basename ] );
			} else {
				$transient->no_update[ $basename ] = $entry;
				unset( $transient->response[ $basename ] );
			}

			return $transient;
		}

		/**
		 * Answer this product's details request.
		 *
		 * @since 1.0.0
		 *
		 * @param mixed  $result The incoming result (false unless another handler answered).
		 * @param string $action The `plugins_api` action being performed.
		 * @param object $args    The request arguments; `slug` selects the plugin.
		 * @return mixed The information object for this product, or the incoming result.
		 */
		public function filterPluginsApi( $result, $action, $args ) {
			if ( 'plugin_information' !== $action ) {
				return $result;
			}

			if ( ! is_object( $args ) || ! isset( $args->slug ) ) {
				return $result;
			}

			$payload = $this->getUpdateData();

			if ( null === $payload ) {
				return $result;
			}

			$slug = isset( $payload['slug'] ) ? (string) $payload['slug'] : $this->productSlug;

			if ( (string) $args->slug !== $slug ) {
				return $result;
			}

			$changelog = isset( $payload['sections']['changelog'] )
				? (string) $payload['sections']['changelog']
				: '';

			$info               = new stdClass();
			$info->name         = isset( $payload['name'] ) ? (string) $payload['name'] : $slug;
			$info->slug         = $slug;
			$info->version      = isset( $payload['new_version'] ) ? (string) $payload['new_version'] : $this->installedVersion();
			$info->requires     = isset( $payload['requires'] ) ? (string) $payload['requires'] : '';
			$info->tested       = isset( $payload['tested'] ) ? (string) $payload['tested'] : '';
			$info->requires_php = isset( $payload['requires_php'] ) ? (string) $payload['requires_php'] : '';
			$info->last_updated = isset( $payload['last_updated'] ) ? (string) $payload['last_updated'] : '';
			$info->sections     = array(
				'description' => $info->name . '. See the changelog tab for the latest changes.',
				'changelog'   => $changelog,
			);

			if ( isset( $payload['package'] ) && '' !== (string) $payload['package'] ) {
				$info->download_link = (string) $payload['package'];
			}

			return $info;
		}

		/**
		 * Replace an expired package URL before the upgrader downloads it.
		 *
		 * @since 1.0.0
		 *
		 * @param mixed  $reply      The upgrader's short-circuit value (false to proceed normally).
		 * @param string $package    The package URL the upgrader is about to download.
		 * @param mixed  $upgrader   The WP_Upgrader instance (unused; filter signature).
		 * @param array  $hookExtra  Context, including `plugin` (the basename) when known.
		 * @return mixed The downloaded file path on success, a WP_Error on failure, or $reply when the package is not ours.
		 */
		public function filterUpgraderPreDownload( $reply, $package = '', $upgrader = null, $hookExtra = array() ) {
			if ( ! is_string( $package ) || '' === $package || ! $this->isOurPackageUrl( $package ) ) {
				return $reply;
			}

			// Only claim this plugin when WordPress provides its basename.
			if ( is_array( $hookExtra ) && isset( $hookExtra['plugin'] )
				&& $hookExtra['plugin'] !== plugin_basename( $this->pluginFile ) ) {
				return $reply;
			}

			// Request a new package URL.
			$this->flush_cache();
			$payload = $this->getUpdateData();

			if ( ! is_array( $payload ) || ! isset( $payload['package'] ) || '' === (string) $payload['package'] ) {
				return new WP_Error(
					'tiermint_no_fresh_package',
					'Could not refresh the download link for this update. Please try updating again.'
				);
			}

			if ( ! function_exists( 'download_url' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			$file = download_url( (string) $payload['package'] );

			if ( is_wp_error( $file ) ) {
				return new WP_Error(
					'tiermint_download_failed',
					'The update package could not be downloaded. Please try updating again.'
				);
			}

			return $file;
		}

		/**
		 * Check whether a package URL belongs to this store.
		 *
		 * @param string $package The package URL to test.
		 * @return bool True when the URL is one this client should refresh.
		 */
		private function isOurPackageUrl( $package ) {
			$apiHost = wp_parse_url( $this->apiUrl, PHP_URL_HOST );
			$pkgHost = wp_parse_url( $package, PHP_URL_HOST );

			return '' !== (string) $apiHost
				&& $pkgHost === $apiHost
				&& false !== strpos( $package, '/package/download' );
		}

		/**
		 * Discard cached update data.
		 *
		 * @since 1.0.0
		 *
		 * @return void
		 */
		public function flush_cache() {
			delete_transient( $this->cacheKey( self::CACHE_PREFIX ) );
			delete_transient( $this->cacheKey( self::GRACE_PREFIX ) );
		}

		/**
		 * Get cached, fresh, or grace-period update data.
		 *
		 * @return array|null The decoded payload, or null.
		 */
		private function getUpdateData() {
			$fresh = get_transient( $this->cacheKey( self::CACHE_PREFIX ) );

			if ( is_array( $fresh ) ) {
				return $fresh;
			}

			$response = $this->requestUpdateCheck();

			if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
				$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

				if ( is_array( $decoded ) ) {
					// Cache all valid store answers, including invalid licenses.
					set_transient( $this->cacheKey( self::CACHE_PREFIX ), $decoded, self::FRESH_HOURS * HOUR_IN_SECONDS );

					$graceSeconds = $this->graceSeconds();

					if ( $graceSeconds > 0 ) {
						set_transient(
							$this->cacheKey( self::GRACE_PREFIX ),
							array(
								'fetched_at' => time(),
								'payload'    => $decoded,
							),
							$graceSeconds
						);
					}

					return $decoded;
				}
			}

			// Use the last valid answer during the grace window.
			$grace = get_transient( $this->cacheKey( self::GRACE_PREFIX ) );

			if ( is_array( $grace ) && isset( $grace['payload'], $grace['fetched_at'] ) && is_array( $grace['payload'] ) ) {
				if ( ( time() - (int) $grace['fetched_at'] ) <= $this->graceSeconds() ) {
					return $grace['payload'];
				}
			}

			return null;
		}

		/**
		 * Perform the update-check HTTP request against the store.
		 *
		 * @return array|WP_Error The `wp_remote_get` result.
		 */
		private function requestUpdateCheck() {
			$args = array(
				'product_slug'      => $this->productSlug,
				'license_key'       => $this->licenseKey,
				'site_url'          => home_url(),
				'installed_version' => $this->installedVersion(),
			);

			// Only sent when opted in, so a default install's request is unchanged.
			if ( '' !== $this->channel ) {
				$args['channel'] = $this->channel;
			}

			$url = add_query_arg( $args, $this->apiUrl . '/update-check' );

			return wp_remote_get(
				$url,
				array(
					'timeout' => self::HTTP_TIMEOUT,
				)
			);
		}

		/**
		 * Build the stdClass entry WordPress stores in `response` or `no_update`.
		 *
		 * @param array  $payload   The decoded update-check payload.
		 * @param string $basename  The host plugin's basename (its transient key).
		 * @param string $installed The installed version (the `no_update` version).
		 * @param bool   $isUpdate  Whether this entry represents an available update.
		 * @return stdClass The populated entry.
		 */
		private function pluginEntry( array $payload, $basename, $installed, $isUpdate ) {
			$slug = isset( $payload['slug'] ) ? (string) $payload['slug'] : $this->productSlug;

			$entry               = new stdClass();
			$entry->id           = $this->apiUrl . '/' . $slug;
			$entry->slug         = $slug;
			$entry->plugin       = $basename;
			$entry->new_version  = $isUpdate && isset( $payload['new_version'] )
				? (string) $payload['new_version']
				: (string) $installed;
			$entry->url          = '';
			$entry->package      = isset( $payload['package'] ) ? (string) $payload['package'] : '';
			$entry->tested       = isset( $payload['tested'] ) ? (string) $payload['tested'] : '';
			$entry->requires     = isset( $payload['requires'] ) ? (string) $payload['requires'] : '';
			$entry->requires_php = isset( $payload['requires_php'] ) ? (string) $payload['requires_php'] : '';
			$entry->icons        = array();

			if ( $isUpdate && isset( $payload['upgrade_notice'] ) && '' !== (string) $payload['upgrade_notice'] ) {
				$entry->upgrade_notice = (string) $payload['upgrade_notice'];
			}

			return $entry;
		}

		/**
		 * The installed version read once per request from the plugin's header.
		 *
		 * @return string The version string, or an empty string if unreadable.
		 */
		private function installedVersion() {
			if ( null !== $this->installedVersionCache ) {
				return $this->installedVersionCache;
			}

			$data = get_file_data( $this->pluginFile, array( 'Version' => 'Version' ) );

			$this->installedVersionCache = isset( $data['Version'] ) ? (string) $data['Version'] : '';

			return $this->installedVersionCache;
		}

		/**
		 * Build a cache key for this product and channel.
		 *
		 * @param string $prefix The key prefix (freshness or grace).
		 * @return string The full transient key.
		 */
		private function cacheKey( $prefix ) {
			return $prefix . md5( $this->apiUrl . '|' . $this->productSlug . '|' . $this->licenseKey . '|' . $this->channel );
		}

		/**
		 * Get the grace window in seconds.
		 *
		 * @return int The grace window in seconds (never negative).
		 */
		private function graceSeconds() {
			/**
			 * Filters the client-side grace window in hours.
			 *
			 * @since 1.0.0
			 *
			 * @param int $hours The grace window in hours. Default 72.
			 */
			$hours = (int) apply_filters( 'tiermint_client_grace_hours', self::DEFAULT_GRACE_HOURS );

			if ( $hours < 0 ) {
				$hours = 0;
			}

			return $hours * HOUR_IN_SECONDS;
		}
	}
}
