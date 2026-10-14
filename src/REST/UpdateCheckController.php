<?php
/**
 * Public update-check REST endpoint.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\REST;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Licensing\LicensePersistenceException;
use DrPlugin\WP\TierMint\Products\ProductNotFoundException;
use DrPlugin\WP\TierMint\Products\ProductPersistenceException;
use DrPlugin\WP\TierMint\Updates\ReleasePersistenceException;
use DrPlugin\WP\TierMint\Updates\UpdateService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Provides license-key update checks for customer plugins.
 *
 * @since 1.0.0
 */
final class UpdateCheckController extends AbstractRestController {

	/**
	 * The payload assembler.
	 *
	 * @var UpdateService
	 */
	private UpdateService $updates;

	/**
	 * Wire the controller with the update service.
	 *
	 * @since 1.0.0
	 *
	 * @param UpdateService $updates The update-check payload assembler.
	 */
	public function __construct( UpdateService $updates ) {
		$this->updates = $updates;
	}

	/**
	 * Register the public update-check route.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			'/update-check',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'check' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'product_slug'      => array(
							'type'              => 'string',
							'required'          => true,
							'description'       => __( 'The product\'s catalog slug, the same identifier the licensing endpoints match on.', 'tiermint' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
						'license_key'       => array(
							'type'              => 'string',
							'required'          => true,
							'description'       => __( 'The license key credential.', 'tiermint' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
						'site_url'          => array(
							'type'              => 'string',
							'required'          => false,
							'description'       => __( 'The requesting site\'s URL; without it no package URL can be issued.', 'tiermint' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
						'installed_version' => array(
							'type'              => 'string',
							'required'          => false,
							'description'       => __( 'The currently installed version, for the update_available verdict.', 'tiermint' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
						'channel'           => array(
							'type'              => 'string',
							'required'          => false,
							'description'       => __( 'Release channel. The free core always serves stable; Pro honors this parameter.', 'tiermint' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	/**
	 * Return update data and a package URL when allowed.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error The update-check payload, or an error envelope.
	 */
	public function check( WP_REST_Request $request ) {
		$productSlug = trim( (string) $request->get_param( 'product_slug' ) );
		$licenseKey  = trim( (string) $request->get_param( 'license_key' ) );

		if ( '' === $productSlug || '' === $licenseKey ) {
			return $this->invalidParams( array( __( 'Both product_slug and license_key are required.', 'tiermint' ) ) );
		}

		$siteUrl   = trim( (string) $request->get_param( 'site_url' ) );
		$installed = trim( (string) $request->get_param( 'installed_version' ) );
		$channel   = trim( (string) $request->get_param( 'channel' ) );

		try {
			$payload = $this->updates->check(
				$productSlug,
				$licenseKey,
				'' !== $siteUrl ? $siteUrl : null,
				'' !== $installed ? $installed : null,
				null,
				'' !== $channel ? $channel : null
			);
		} catch ( ProductNotFoundException $e ) {
			// Do not reveal product names.
			return $this->notFound();
		} catch ( LicensePersistenceException | ProductPersistenceException | ReleasePersistenceException $e ) {
			// Let the client use its grace period.
			return $this->serverError();
		}

		return new WP_REST_Response( $payload, 200 );
	}
}
