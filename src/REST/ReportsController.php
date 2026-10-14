<?php
/**
 * REST controller for the basic sales report resource.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\REST;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Reports\ReportPersistenceException;
use DrPlugin\WP\TierMint\Reports\ReportService;
use DrPlugin\WP\TierMint\Reports\ReportValidationException;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Exposes the read-only sales report to store managers.
 *
 * @since 1.0.0
 */
final class ReportsController extends AbstractRestController {

	/**
	 * The sales report service this controller delegates to.
	 *
	 * @var ReportService
	 */
	private ReportService $reports;

	/**
	 * Wire the controller with the report service.
	 *
	 * @since 1.0.0
	 *
	 * @param ReportService $reports The sales report service.
	 */
	public function __construct( ReportService $reports ) {
		$this->reports = $reports;
	}

	/**
	 * Register the `GET /reports/sales` route.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			'/reports/sales',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'sales' ),
					'permission_callback' => array( $this, 'reportsPermission' ),
					'args'                => $this->salesArgs(),
				),
			)
		);
	}

	/**
	 * Return sales totals and daily values for a UTC date range.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|\WP_Error The report, or an error envelope.
	 */
	public function sales( WP_REST_Request $request ) {
		list( $from, $to ) = $this->reports->defaultRange(
			(string) $request->get_param( 'from' ),
			(string) $request->get_param( 'to' )
		);

		try {
			$report = $this->reports->sales( $from, $to );
		} catch ( ReportValidationException $e ) {
			$errors = $e->getErrors();

			return $this->invalidParams( array() === $errors ? array( $e->getMessage() ) : $errors );
		} catch ( ReportPersistenceException $e ) {
			return $this->serverError();
		} catch ( \Throwable $e ) {
			// Do not expose unexpected errors.
			return $this->serverError();
		}

		return new WP_REST_Response( $report, 200 );
	}

	/**
	 * Return the optional date parameter schema.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>> The parameter schema.
	 */
	private function salesArgs(): array {
		return array(
			'from' => array(
				'description'       => __( 'Inclusive start date (YYYY-MM-DD, UTC). Defaults to 29 days before "to".', 'tiermint' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'to'   => array(
				'description'       => __( 'Inclusive end date (YYYY-MM-DD, UTC). Defaults to the current UTC date.', 'tiermint' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}
}
