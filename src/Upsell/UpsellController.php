<?php
/**
 * REST route that records an upsell notice dismissal.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Upsell;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\REST\AbstractRestController;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The one write the upsell surfaces make: dismissing a notice.
 *
 * REST, not `wp_ajax_`, so the free core adds no admin-ajax handler. The topic
 * is bound to a closed enum so the stored list cannot fill with junk.
 *
 * @since 1.0.0
 */
final class UpsellController extends AbstractRestController {

	/**
	 * The dismissal store.
	 *
	 * @var Dismissals
	 */
	private Dismissals $dismissals;

	/**
	 * Wire the controller with the dismissal store.
	 *
	 * @since 1.0.0
	 *
	 * @param Dismissals $dismissals The dismissal store.
	 */
	public function __construct( Dismissals $dismissals ) {
		$this->dismissals = $dismissals;
	}

	/**
	 * Register the dismiss route.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			'/upsell/dismiss',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'dismiss' ),
					'permission_callback' => array( $this, 'managePermission' ),
					'args'                => array(
						'topic' => array(
							'description' => __( 'The upsell notice to dismiss.', 'tiermint' ),
							'type'        => 'string',
							'required'    => true,
							'enum'        => Dismissals::TOPICS,
						),
					),
				),
			)
		);
	}

	/**
	 * Record the dismissal and return the current dismissed list.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response The dismissed topics after the write.
	 */
	public function dismiss( WP_REST_Request $request ): WP_REST_Response {
		$this->dismissals->dismiss( (string) $request->get_param( 'topic' ) );

		return new WP_REST_Response( array( 'dismissed' => $this->dismissals->topics() ), 200 );
	}
}
