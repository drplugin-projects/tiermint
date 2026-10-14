<?php
/**
 * REST controller for the WordPress user picker the admin screens search through.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\REST;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_User;
use WP_User_Query;

/**
 * Provides a capped user picker for store managers.
 * It exposes only the fields the picker needs.
 *
 * @since 1.0.0
 */
final class UsersController extends AbstractRestController {

	/**
	 * Limit each picker search.
	 *
	 * @var int
	 */
	private const MAX_RESULTS = 20;

	/**
	 * Register the user-search route.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			'/users/search',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'search' ),
					'permission_callback' => array( $this, 'managePermission' ),
					'args'                => $this->searchArgs(),
				),
			)
		);
	}

	/**
	 * Return matching picker accounts. An empty term returns no accounts.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response The matching accounts.
	 */
	public function search( WP_REST_Request $request ): WP_REST_Response {
		// Avoid an all-account match from a wildcard-only term.
		$term = trim( (string) $request->get_param( 'term' ), " \t\n\r\0\x0B*" );

		if ( '' === $term ) {
			return new WP_REST_Response( array(), 200 );
		}

		$query = new WP_User_Query(
			array(
				// Match only the values shown in the picker.
				'search'         => '*' . $term . '*',
				'search_columns' => array( 'user_email', 'display_name' ),
				'number'         => self::MAX_RESULTS,
				'orderby'        => 'display_name',
				'order'          => 'ASC',
				'fields'         => array( 'ID', 'display_name', 'user_email' ),
				// The picker does not need a result count.
				'count_total'    => false,
			)
		);

		return new WP_REST_Response( array_map( array( $this, 'presentUser' ), $query->get_results() ), 200 );
	}

	/**
	 * Shape a picker account without HTML escaping JSON values.
	 *
	 * @since 1.0.0
	 *
	 * @param object|WP_User $user A row from the field-limited user query.
	 * @return array<string, mixed> The response representation.
	 */
	private function presentUser( $user ): array {
		return array(
			'id'    => (int) $user->ID,
			'name'  => (string) $user->display_name,
			'email' => (string) $user->user_email,
		);
	}

	/**
	 * The `args` schema for the search query.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>> The parameter schema.
	 */
	private function searchArgs(): array {
		return array(
			'term' => array(
				'description'       => __( 'Match accounts whose display name or email contains this text.', 'tiermint' ),
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}
}
