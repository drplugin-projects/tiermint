<?php
/**
 * REST controller for the reference catalogs (countries, currencies, pages).
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\REST;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Catalog\Countries;
use DrPlugin\WP\TierMint\Catalog\Currencies;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Exposes ordered country, currency and page catalogs.
 * Public catalogs contain only static reference data. Page data needs settings access.
 *
 * @since 1.0.0
 */
final class CatalogController extends AbstractRestController {

	/**
	 * Register the three catalog routes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			'/catalog/countries',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'countries' ),
					// Public: static ISO 3166 reference data, cacheable, and the checkout country dropdown must load it for logged-out buyers.
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/catalog/currencies',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'currencies' ),
					// Public for the same reason as the country list: static reference data.
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/catalog/pages',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'pages' ),
					// A page list leaks site structure, so it is gated like its pickers.
					'permission_callback' => array( $this, 'settingsPermission' ),
				),
			)
		);
	}

	/**
	 * Return countries in name order.
	 *
	 * @since 1.0.0
	 *
	 * @return WP_REST_Response The country list.
	 */
	public function countries(): WP_REST_Response {
		$out = array();

		foreach ( Countries::all() as $code => $name ) {
			$out[] = array(
				'code' => (string) $code,
				'name' => (string) $name,
			);
		}

		return new WP_REST_Response( $out, 200 );
	}

	/**
	 * Return currencies in label order.
	 *
	 * @since 1.0.0
	 *
	 * @return WP_REST_Response The currency list.
	 */
	public function currencies(): WP_REST_Response {
		$out = array();

		foreach ( Currencies::all() as $code => $entry ) {
			$out[] = array(
				'code'     => (string) $code,
				'label'    => (string) $entry['label'],
				'symbol'   => (string) $entry['symbol'],
				'decimals' => (int) $entry['decimals'],
			);
		}

		return new WP_REST_Response( $out, 200 );
	}

	/**
	 * Return published pages for settings pickers.
	 *
	 * @since 1.0.0
	 *
	 * @return WP_REST_Response The page list.
	 */
	public function pages(): WP_REST_Response {
		$pages = get_pages();
		if ( ! is_array( $pages ) ) {
			$pages = array();
		}

		$out = array();

		foreach ( $pages as $page ) {
			$title = trim( (string) $page->post_title );

			$out[] = array(
				'id'    => (int) $page->ID,
				'title' => '' !== $title ? $title : __( '(no title)', 'tiermint' ),
			);
		}

		return new WP_REST_Response( $out, 200 );
	}
}
