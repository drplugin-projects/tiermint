<?php
/**
 * Shared argument schema and accumulation loop for the bulk-action routes.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\REST;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use Throwable;
use WP_Error;
use WP_REST_Response;

/**
 * Arguments and results for batch routes.
 *
 * Each id is handled independently.
 *
 * @since 1.0.0
 */
final class BatchRequest {

	/**
	 * Maximum ids in one request.
	 *
	 * @var int
	 */
	public const MAX_IDS = 100;

	/**
	 * Build batch route arguments.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string> $actions The action names this resource accepts.
	 * @return array<string, array<string, mixed>> The parameter schema.
	 */
	public static function args( array $actions ): array {
		return array(
			'ids'    => array(
				'description'       => __( 'The ids to act on.', 'tiermint' ),
				'type'              => 'array',
				'required'          => true,
				'minItems'          => 1,
				'maxItems'          => self::MAX_IDS,
				'items'             => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'sanitize_callback' => 'rest_sanitize_request_arg',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'action' => array(
				'description'       => __( 'The action to apply to every id.', 'tiermint' ),
				'type'              => 'string',
				'required'          => true,
				'enum'              => array_values( $actions ),
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * Run each id and collect its result.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, int>                $ids     The ids to act on.
	 * @param callable(int): (WP_Error|null) $handler The per-id action.
	 * @return WP_REST_Response The `{succeeded, failed}` result at HTTP 200.
	 */
	public static function run( array $ids, callable $handler ): WP_REST_Response {
		$succeeded = array();
		$failed    = array();

		foreach ( self::normalize( $ids ) as $id ) {
			try {
				$result = $handler( $id );
			} catch ( Throwable $e ) {
				$result = new WP_Error(
					'tiermint_server_error',
					__( 'The request could not be completed. Please try again.', 'tiermint' )
				);
			}

			if ( $result instanceof WP_Error ) {
				$failed[] = array(
					'id'      => $id,
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				);

				continue;
			}

			$succeeded[] = $id;
		}

		return new WP_REST_Response(
			array(
				'succeeded' => $succeeded,
				'failed'    => $failed,
			),
			200
		);
	}

	/**
	 * Keep distinct positive ids in request order.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, mixed> $ids The submitted ids.
	 * @return array<int, int> The distinct positive ids, in order.
	 */
	private static function normalize( array $ids ): array {
		$positive = array_filter(
			array_map( static fn ( $id ): int => (int) $id, $ids ),
			static fn ( int $id ): bool => $id > 0
		);

		return array_values( array_unique( $positive ) );
	}
}
