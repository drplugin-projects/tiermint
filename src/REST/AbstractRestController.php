<?php
/**
 * Shared base for TierMint REST controllers.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\REST;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Core\Capabilities;
use DrPlugin\WP\TierMint\Core\Hookable;
use WP_Error;
use WP_REST_Response;

/**
 * Common helpers for management REST controllers.
 *
 * @since 1.0.0
 */
abstract class AbstractRestController implements Hookable {

	/**
	 * REST route namespace.
	 *
	 * @var string
	 */
	protected const API_NAMESPACE = 'tiermint/v1';

	/**
	 * Register the controller's routes when WordPress builds the REST API.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	/**
	 * Register this controller's routes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	abstract public function registerRoutes(): void;

	/**
	 * Check the management capability.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when the current user may manage TierMint resources.
	 */
	public function managePermission(): bool {
		return current_user_can( Capabilities::MANAGE );
	}

	/**
	 * Check the configuration capability.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when the current user may change TierMint configuration.
	 */
	public function settingsPermission(): bool {
		return current_user_can( Capabilities::MANAGE_SETTINGS );
	}

	/**
	 * The read-only reporting permission gate.
	 *
	 * Store managers pass too: manage_tiermint already reads raw orders and customers, so it
	 * is never locked out of the less-sensitive aggregate reports. PII-bearing exports keep
	 * their own manage_tiermint gate; this only covers aggregate report reads.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when the current user may view TierMint reports.
	 */
	public function reportsPermission(): bool {
		return current_user_can( Capabilities::VIEW_REPORTS ) || current_user_can( Capabilities::MANAGE );
	}

	/**
	 * Build a standard error response.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $code    The stable, `tiermint_`-prefixed error code.
	 * @param string               $message The human-readable error message.
	 * @param int                  $status  The HTTP status to return.
	 * @param array<string, mixed> $extra   Extra fields to merge into `data`.
	 * @return WP_Error The error in the standard envelope.
	 */
	protected function error( string $code, string $message, int $status, array $extra = array() ): WP_Error {
		return new WP_Error( $code, $message, array_merge( array( 'status' => $status ), $extra ) );
	}

	/**
	 * The standard 404 envelope for a resource that does not exist.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message An optional override message.
	 * @return WP_Error The `tiermint_not_found` error at HTTP 404.
	 */
	protected function notFound( string $message = '' ): WP_Error {
		return $this->error(
			'tiermint_not_found',
			'' !== $message ? $message : __( 'The requested resource was not found.', 'tiermint' ),
			404
		);
	}

	/**
	 * Build a validation error with field messages.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string> $errors  The individual field-level validation messages.
	 * @param string             $message An optional override summary message.
	 * @return WP_Error The `tiermint_invalid_params` error at HTTP 400.
	 */
	protected function invalidParams( array $errors, string $message = '' ): WP_Error {
		return $this->error(
			'tiermint_invalid_params',
			'' !== $message ? $message : __( 'One or more parameters were invalid.', 'tiermint' ),
			400,
			array( 'errors' => array_values( $errors ) )
		);
	}

	/**
	 * Build a safe server error response.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message An optional safe message.
	 * @return WP_Error The `tiermint_server_error` error at HTTP 500.
	 */
	protected function serverError( string $message = '' ): WP_Error {
		return $this->error(
			'tiermint_server_error',
			'' !== $message ? $message : __( 'The request could not be completed. Please try again.', 'tiermint' ),
			500
		);
	}

	/**
	 * Add collection pagination headers.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Response $response The collection response to decorate.
	 * @param int              $total    The total number of matching records.
	 * @param int              $perPage  The page size used for the query.
	 * @return WP_REST_Response The same response, with pagination headers set.
	 */
	protected function withPagination( WP_REST_Response $response, int $total, int $perPage ): WP_REST_Response {
		$totalPages = $perPage > 0 ? (int) ceil( $total / $perPage ) : 0;

		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $totalPages );

		return $response;
	}
}
