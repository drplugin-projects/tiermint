<?php
/**
 * REST surface streaming the sales report as a CSV file.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\REST;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Customers\Customer;
use DrPlugin\WP\TierMint\Orders\Order;
use DrPlugin\WP\TierMint\Orders\OrderBuyers;
use DrPlugin\WP\TierMint\Orders\OrderItem;
use DrPlugin\WP\TierMint\Orders\OrderRepository;
use DrPlugin\WP\TierMint\Reports\CsvWriter;
use DrPlugin\WP\TierMint\Reports\ReportPersistenceException;
use DrPlugin\WP\TierMint\Reports\ReportService;
use DrPlugin\WP\TierMint\Reports\ReportValidationException;
use DrPlugin\WP\TierMint\Util\Money;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST routes for sales and order CSV exports.
 *
 * Exports share data with their JSON views. The stream filter sends staged CSV files.
 *
 * @since 1.0.0
 */
final class ReportsExportController extends AbstractRestController {

	/**
	 * The default maximum rows for an orders export.
	 *
	 * @var int
	 */
	private const ORDERS_EXPORT_MAX_ROWS = 10000;

	/**
	 * The sales report service, the sole owner of the aggregation and date rules.
	 *
	 * @var ReportService
	 */
	private ReportService $reports;

	/**
	 * The RFC 4180 CSV serializer.
	 *
	 * @var CsvWriter
	 */
	private CsvWriter $csv;

	/**
	 * The order repository, for the bounded orders-export read.
	 *
	 * @var OrderRepository
	 */
	private OrderRepository $orders;

	/**
	 * The shared batched buyer resolver.
	 *
	 * @var OrderBuyers
	 */
	private OrderBuyers $buyers;

	/**
	 * The pending CSV body and filename.
	 *
	 * @var array{body: string, filename: string}|null
	 */
	private ?array $pending = null;

	/**
	 * Wire the controller.
	 *
	 * @since 1.0.0
	 *
	 * @param ReportService   $reports The sales report service.
	 * @param CsvWriter       $csv     The RFC 4180 CSV serializer.
	 * @param OrderRepository $orders  The order repository, for the bounded orders read.
	 * @param OrderBuyers     $buyers  The batched buyer resolver, shared with the orders list.
	 */
	public function __construct( ReportService $reports, CsvWriter $csv, OrderRepository $orders, OrderBuyers $buyers ) {
		$this->reports = $reports;
		$this->csv     = $csv;
		$this->orders  = $orders;
		$this->buyers  = $buyers;
	}

	/**
	 * Register the export route and the streaming serve filter.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function hooks(): void {
		parent::hooks();
		add_filter( 'rest_pre_serve_request', array( $this, 'serve' ), 10, 2 );
	}

	/**
	 * Register `GET /reports/sales/export`.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			'/reports/sales/export',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'salesExport' ),
					'permission_callback' => array( $this, 'reportsPermission' ),
					'args'                => $this->rangeArgs(),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/reports/orders/export',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'ordersExport' ),
					'permission_callback' => array( $this, 'managePermission' ),
					'args'                => $this->rangeArgs(),
				),
			)
		);
	}

	/**
	 * Stage a sales CSV export.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error The staged response, or an error envelope.
	 */
	public function salesExport( WP_REST_Request $request ) {
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
		} catch ( Throwable $e ) {
			return $this->serverError();
		}

		$this->pending = array(
			'body'     => $this->csv->write( $this->header(), $this->rows( $report ) ),
			'filename' => 'tiermint-sales-' . $report['from'] . '-to-' . $report['to'] . '.csv',
		);

		return new WP_REST_Response( null, 200 );
	}

	/**
	 * Stage an orders CSV export.
	 *
	 * Refuse oversized ranges instead of truncating buyer data.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error The staged response, or an error envelope.
	 */
	public function ordersExport( WP_REST_Request $request ) {
		list( $from, $to ) = $this->reports->defaultRange(
			(string) $request->get_param( 'from' ),
			(string) $request->get_param( 'to' )
		);

		try {
			$this->reports->assertValidRange( $from, $to );
		} catch ( ReportValidationException $e ) {
			$errors = $e->getErrors();

			return $this->invalidParams( array() === $errors ? array( $e->getMessage() ) : $errors );
		}

		$cap = $this->ordersExportCap();

		try {
			$orders = $this->orders->exportRange( $from, $to, $cap + 1 );
		} catch ( Throwable $e ) {
			return $this->serverError();
		}

		if ( count( $orders ) > $cap ) {
			return $this->invalidParams(
				array(
					sprintf(
						/* translators: %s: the maximum number of rows an orders export may hold. */
						__( 'This range holds more than %s orders. Narrow the date range and export again.', 'tiermint' ),
						number_format_i18n( $cap )
					),
				)
			);
		}

		$this->pending = array(
			'body'     => $this->csv->write( $this->ordersHeader(), $this->orderRows( $orders ) ),
			'filename' => 'tiermint-orders-' . $from . '-to-' . $to . '.csv',
		);

		return new WP_REST_Response( null, 200 );
	}

	/**
	 * Stream the staged CSV response.
	 *
	 * Return true only after this controller sends a file.
	 *
	 * @since 1.0.0
	 *
	 * @param bool  $served Whether something already served the response.
	 * @param mixed $result The dispatched result (unused; filter signature).
	 * @return bool True when this filter streamed the CSV; the incoming value otherwise.
	 */
	public function serve( $served, $result ) {
		if ( $served || null === $this->pending ) {
			return (bool) $served;
		}

		$pending       = $this->pending;
		$this->pending = null;

		if ( ! headers_sent() ) {
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $pending['filename'] ) . '"' );
			header( 'X-Robots-Tag: noindex, nofollow' );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Streams a pre-serialized, RFC 4180-quoted text/csv body, not HTML; escaping it would corrupt the file.
		echo $pending['body'];

		return true;
	}

	/**
	 * Return the sales CSV header.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, string>
	 */
	private function header(): array {
		return array( 'date', 'currency', 'gross_revenue', 'refund_total', 'net_revenue', 'order_count', 'refund_count' );
	}

	/**
	 * Return sales CSV rows.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $report The sales report.
	 * @return array<int, array<int, string>>
	 */
	private function rows( array $report ): array {
		$currency = (string) $report['currency'];
		$rows     = array();

		foreach ( $report['series'] as $day ) {
			$rows[] = array(
				(string) $day['date'],
				$currency,
				Money::toDecimalString( (int) $day['gross_revenue'], $currency ),
				Money::toDecimalString( (int) $day['refund_total'], $currency ),
				Money::toDecimalString( (int) $day['net_revenue'], $currency ),
				(string) (int) $day['order_count'],
				(string) (int) $day['refund_count'],
			);
		}

		return $rows;
	}

	/**
	 * Return the filterable orders-export limit.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	private function ordersExportCap(): int {
		/**
		 * Filter the maximum rows in an orders CSV export.
		 *
		 * @since 1.0.0
		 *
		 * @param int $cap The row cap. Raising it trades memory and request time for a larger file.
		 */
		$cap = (int) apply_filters( 'tiermint_reports_orders_export_max_rows', self::ORDERS_EXPORT_MAX_ROWS );

		return max( 1, $cap );
	}

	/**
	 * Return the orders CSV header.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, string>
	 */
	private function ordersHeader(): array {
		return array( 'order_id', 'date', 'customer_name', 'customer_email', 'products', 'status', 'type', 'total', 'currency', 'gateway' );
	}

	/**
	 * Return orders CSV rows.
	 *
	 * Deleted buyers leave an empty name cell.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, Order> $orders The orders to write.
	 * @return array<int, array<int, string>>
	 */
	private function orderRows( array $orders ): array {
		$buyers = $this->buyers->forOrders( $orders );
		$rows   = array();

		foreach ( $orders as $order ) {
			$buyer    = $buyers[ (int) $order->customerId() ] ?? null;
			$currency = (string) $order->currency();

			$rows[] = array(
				(string) (int) $order->id(),
				(string) $order->createdAt(),
				$buyer instanceof Customer ? (string) $buyer->name() : '',
				(string) $order->email(),
				$this->productsCell( $order ),
				$order->status()->value,
				$order->type()->value,
				Money::toDecimalString( (int) $order->total(), $currency ),
				$currency,
				null === $order->gateway() ? '' : (string) $order->gateway(),
			);
		}

		return $rows;
	}

	/**
	 * Return order items as one CSV cell.
	 *
	 * @since 1.0.0
	 *
	 * @param Order $order The order.
	 * @return string
	 */
	private function productsCell( Order $order ): string {
		$parts = array_map(
			static function ( OrderItem $item ): string {
				$name = (string) $item->productName();
				$tier = (string) $item->priceName();

				return '' === $tier ? $name : $name . ' (' . $tier . ')';
			},
			$order->items()
		);

		return implode( '; ', $parts );
	}

	/**
	 * Return the optional date-range schema.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function rangeArgs(): array {
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
