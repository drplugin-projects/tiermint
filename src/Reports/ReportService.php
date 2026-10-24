<?php
/**
 * Sales reporting service: revenue and refunds aggregated by UTC calendar day.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Reports;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use DrPlugin\WP\TierMint\Database\Schema;
use DrPlugin\WP\TierMint\Orders\OrderType;
use DrPlugin\WP\TierMint\Util\Money;

/**
 * Builds the admin sales report in minor units and UTC days.
 *
 * Keep currencies separate. Refund rows reduce revenue only through the refund total.
 *
 * @since 1.0.0
 */
class ReportService {

	/**
	 * Maximum inclusive range, including a leap year.
	 *
	 * @var int
	 */
	private const MAX_RANGE_DAYS = 366;

	/**
	 * Default offset for the 30-day inclusive range.
	 *
	 * @var int
	 */
	private const DEFAULT_WINDOW_DAYS = 29;

	/**
	 * Sale statuses. Refunds remain a separate total.
	 *
	 * @var array<int, string>
	 */
	public const SALE_STATUSES = array( 'completed', 'partially_refunded', 'refunded' );

	/**
	 * Database handle.
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * Set the database handle.
	 *
	 * @since 1.0.0
	 *
	 * @param \wpdb $wpdb Database handle.
	 */
	public function __construct( \wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Build a sales report for an inclusive UTC range.
	 *
	 * @since 1.0.0
	 *
	 * @param string $from Inclusive UTC start date.
	 * @param string $to   Inclusive UTC end date.
	 * @return array{
	 *     currency: string,
	 *     timezone: string,
	 *     from: string,
	 *     to: string,
	 *     totals: array{gross_revenue: int, refund_total: int, net_revenue: int, order_count: int, refund_count: int},
	 *     series: array<int, array{date: string, gross_revenue: int, refund_total: int, net_revenue: int, order_count: int, refund_count: int}>,
	 *     excluded: array<string, array{gross_revenue: int, refund_total: int, net_revenue: int, order_count: int, refund_count: int}>
	 * } Report data in minor units.
	 *
	 * @throws ReportValidationException When the range is invalid.
	 * @throws ReportPersistenceException When a query fails.
	 */
	public function sales( string $from, string $to ): array {
		list( $fromDate, $toDate ) = $this->validateRange( $from, $to );

		$storeCurrency = ( new Money() )->storeCurrency();

		$sales   = $this->salesByDay( $from, $to, $storeCurrency );
		$refunds = $this->refundsByDay( $from, $to, $storeCurrency );

		$salesByDay   = $sales['store'];
		$refundsByDay = $refunds['store'];

		$series      = array();
		$grossTotal  = 0;
		$refundTotal = 0;
		$orderCount  = 0;
		$refundCount = 0;

		// Return zero rows for days without activity.
		$cursor = $fromDate;
		$oneDay = new DateInterval( 'P1D' );

		while ( $cursor <= $toDate ) {
			$day = $cursor->format( 'Y-m-d' );

			$gross  = isset( $salesByDay[ $day ] ) ? (int) $salesByDay[ $day ]['gross_revenue'] : 0;
			$orders = isset( $salesByDay[ $day ] ) ? (int) $salesByDay[ $day ]['order_count'] : 0;
			$refund = isset( $refundsByDay[ $day ] ) ? (int) $refundsByDay[ $day ]['refund_total'] : 0;
			$rcount = isset( $refundsByDay[ $day ] ) ? (int) $refundsByDay[ $day ]['refund_count'] : 0;

			$series[] = array(
				'date'          => $day,
				'gross_revenue' => $gross,
				'refund_total'  => $refund,
				'net_revenue'   => $gross - $refund,
				'order_count'   => $orders,
				'refund_count'  => $rcount,
			);

			$grossTotal  += $gross;
			$refundTotal += $refund;
			$orderCount  += $orders;
			$refundCount += $rcount;

			$cursor = $cursor->add( $oneDay );
		}

		return array(
			'currency' => $storeCurrency,
			'timezone' => 'UTC',
			'from'     => $from,
			'to'       => $to,
			'totals'   => array(
				'gross_revenue' => $grossTotal,
				'refund_total'  => $refundTotal,
				'net_revenue'   => $grossTotal - $refundTotal,
				'order_count'   => $orderCount,
				'refund_count'  => $refundCount,
			),
			'series'   => $series,
			'excluded' => $this->mergeExcluded( $sales['excluded'], $refunds['excluded'] ),
		);
	}

	/**
	 * Validate a report date range.
	 *
	 * @since 1.0.0
	 *
	 * @param string $from Inclusive UTC start date.
	 * @param string $to   Inclusive UTC end date.
	 * @return void
	 *
	 * @throws ReportValidationException When the range is invalid.
	 */
	public function assertValidRange( string $from, string $to ): void {
		$this->validateRange( $from, $to );
	}

	/**
	 * Resolve raw dates to a default 30-day UTC range.
	 *
	 * @since 1.0.0
	 *
	 * @param string $from Raw start date.
	 * @param string $to   Raw end date.
	 * @return array{0: string, 1: string} Resolved dates.
	 */
	public function defaultRange( string $from, string $to ): array {
		$to   = $this->defaultTo( $to );
		$from = $this->defaultFrom( $from, $to );

		return array( $from, $to );
	}

	/**
	 * Default an empty end date to today in UTC.
	 *
	 * @since 1.0.0
	 *
	 * @param string $to Raw end date.
	 * @return string End date.
	 */
	private function defaultTo( string $to ): string {
		$to = trim( $to );

		return '' === $to ? gmdate( 'Y-m-d' ) : $to;
	}

	/**
	 * Default an empty start date from the end date.
	 *
	 * @since 1.0.0
	 *
	 * @param string $from Raw start date.
	 * @param string $to   Resolved end date.
	 * @return string Start date.
	 */
	private function defaultFrom( string $from, string $to ): string {
		$from = trim( $from );

		if ( '' !== $from ) {
			return $from;
		}

		$window = new DateInterval( 'P' . self::DEFAULT_WINDOW_DAYS . 'D' );

		try {
			$anchor = new DateTimeImmutable( $to, new DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			$anchor = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		}

		return $anchor->sub( $window )->format( 'Y-m-d' );
	}

	/**
	 * Aggregate non-refund sales by UTC day and currency.
	 *
	 * @since 1.0.0
	 *
	 * @param string $from          Inclusive start date.
	 * @param string $to            Inclusive end date.
	 * @param string $storeCurrency Store currency.
	 * @return array{store: array<string, array{gross_revenue: int, order_count: int}>, excluded: array<string, array{gross_revenue: int, order_count: int}>}
	 *         Store and excluded rows.
	 *
	 * @throws ReportPersistenceException When the query fails.
	 */
	private function salesByDay( string $from, string $to, string $storeCurrency ): array {
		$wpdb     = $this->wpdb;
		$statuses = self::SALE_STATUSES;
		$values   = array_merge(
			array( $this->ordersTable(), OrderType::Refund->value ),
			$statuses,
			array( $from . ' 00:00:00', $to . ' 23:59:59' )
		);

		$rows = $this->fetchRows(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
			fn () => $wpdb->get_results(
				$wpdb->prepare(
					'SELECT DATE(completed_at) AS bucket, currency, SUM(total) AS gross_revenue, COUNT(*) AS order_count' .
					' FROM %i WHERE type <> %s AND status IN (' . implode( ', ', array_fill( 0, count( $statuses ), '%s' ) ) . ')' .
					' AND completed_at >= %s AND completed_at <= %s GROUP BY DATE(completed_at), currency',
					$values
				)
			)
		);

		$store    = array();
		$excluded = array();
		foreach ( $rows as $row ) {
			$currency = strtoupper( (string) $row->currency );
			$gross    = (int) $row->gross_revenue;
			$orders   = (int) $row->order_count;

			// Keep the store currency for the day series; fold every other currency into the
			// excluded map rather than summing minor units that do not share a scale.
			if ( $currency === $storeCurrency ) {
				$store[ (string) $row->bucket ] = array(
					'gross_revenue' => $gross,
					'order_count'   => $orders,
				);
				continue;
			}

			if ( ! isset( $excluded[ $currency ] ) ) {
				$excluded[ $currency ] = array(
					'gross_revenue' => 0,
					'order_count'   => 0,
				);
			}
			$excluded[ $currency ]['gross_revenue'] += $gross;
			$excluded[ $currency ]['order_count']   += $orders;
		}

		return array(
			'store'    => $store,
			'excluded' => $excluded,
		);
	}

	/**
	 * Aggregate refunds by UTC day and currency.
	 *
	 * @since 1.0.0
	 *
	 * @param string $from          Inclusive start date.
	 * @param string $to            Inclusive end date.
	 * @param string $storeCurrency Store currency.
	 * @return array{store: array<string, array{refund_total: int, refund_count: int}>, excluded: array<string, array{refund_total: int, refund_count: int}>}
	 *         Store and excluded rows.
	 *
	 * @throws ReportPersistenceException When the query fails.
	 */
	private function refundsByDay( string $from, string $to, string $storeCurrency ): array {
		$wpdb = $this->wpdb;
		$rows = $this->fetchRows(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
			fn () => $wpdb->get_results(
				$wpdb->prepare(
					'SELECT DATE(created_at) AS bucket, currency, SUM(ABS(total)) AS refund_total, COUNT(*) AS refund_count' .
					' FROM %i WHERE type = %s AND created_at >= %s AND created_at <= %s GROUP BY DATE(created_at), currency',
					$this->ordersTable(),
					OrderType::Refund->value,
					$from . ' 00:00:00',
					$to . ' 23:59:59'
				)
			)
		);

		$store    = array();
		$excluded = array();
		foreach ( $rows as $row ) {
			$currency = strtoupper( (string) $row->currency );
			$refund   = (int) $row->refund_total;
			$rcount   = (int) $row->refund_count;

			// Keep foreign currencies separate.
			if ( $currency === $storeCurrency ) {
				$store[ (string) $row->bucket ] = array(
					'refund_total' => $refund,
					'refund_count' => $rcount,
				);
				continue;
			}

			if ( ! isset( $excluded[ $currency ] ) ) {
				$excluded[ $currency ] = array(
					'refund_total' => 0,
					'refund_count' => 0,
				);
			}
			$excluded[ $currency ]['refund_total'] += $refund;
			$excluded[ $currency ]['refund_count'] += $rcount;
		}

		return array(
			'store'    => $store,
			'excluded' => $excluded,
		);
	}

	/**
	 * Merge and sort excluded currency totals.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, array{gross_revenue: int, order_count: int}> $salesExcluded   Excluded sales.
	 * @param array<string, array{refund_total: int, refund_count: int}> $refundsExcluded Excluded refunds.
	 * @return array<string, array{gross_revenue: int, refund_total: int, net_revenue: int, order_count: int, refund_count: int}>
	 *         Excluded currency totals.
	 */
	private function mergeExcluded( array $salesExcluded, array $refundsExcluded ): array {
		$excluded = array();

		$codes = array_unique(
			array_merge( array_keys( $salesExcluded ), array_keys( $refundsExcluded ) )
		);

		foreach ( $codes as $code ) {
			$gross  = isset( $salesExcluded[ $code ] ) ? (int) $salesExcluded[ $code ]['gross_revenue'] : 0;
			$orders = isset( $salesExcluded[ $code ] ) ? (int) $salesExcluded[ $code ]['order_count'] : 0;
			$refund = isset( $refundsExcluded[ $code ] ) ? (int) $refundsExcluded[ $code ]['refund_total'] : 0;
			$rcount = isset( $refundsExcluded[ $code ] ) ? (int) $refundsExcluded[ $code ]['refund_count'] : 0;

			$excluded[ $code ] = array(
				'gross_revenue' => $gross,
				'refund_total'  => $refund,
				'net_revenue'   => $gross - $refund,
				'order_count'   => $orders,
				'refund_count'  => $rcount,
			);
		}

		ksort( $excluded );

		return $excluded;
	}

	/**
	 * Validate a date range and return UTC bounds.
	 *
	 * @since 1.0.0
	 *
	 * @param string $from Inclusive start date.
	 * @param string $to   Inclusive end date.
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable} UTC bounds.
	 *
	 * @throws ReportValidationException When the range is invalid.
	 */
	private function validateRange( string $from, string $to ): array {
		$errors   = array();
		$fromDate = $this->parseDate( $from );
		$toDate   = $this->parseDate( $to );

		if ( null === $fromDate ) {
			$errors[] = __( 'The "from" date must be a valid date in YYYY-MM-DD format.', 'tiermint' );
		}

		if ( null === $toDate ) {
			$errors[] = __( 'The "to" date must be a valid date in YYYY-MM-DD format.', 'tiermint' );
		}

		// Compare dates only after both format checks pass.
		if ( null !== $fromDate && null !== $toDate ) {
			if ( $fromDate > $toDate ) {
				$errors[] = __( 'The "from" date must not be later than the "to" date.', 'tiermint' );
			} elseif ( $this->inclusiveDays( $fromDate, $toDate ) > self::MAX_RANGE_DAYS ) {
				/* translators: %d: the maximum number of days a report range may span. */
				$errors[] = sprintf( __( 'The date range must not span more than %d days.', 'tiermint' ), self::MAX_RANGE_DAYS );
			}
		}

		if ( array() !== $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw ReportValidationException::withErrors( $errors );
		}

		return array( $fromDate, $toDate );
	}

	/**
	 * Parse a real UTC calendar date.
	 *
	 * @since 1.0.0
	 *
	 * @param string $date Candidate date.
	 * @return DateTimeImmutable|null UTC date, or null.
	 */
	private function parseDate( string $date ): ?DateTimeImmutable {
		$utc    = new DateTimeZone( 'UTC' );
		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $utc );

		if ( false === $parsed || $parsed->format( 'Y-m-d' ) !== $date ) {
			return null;
		}

		return $parsed;
	}

	/**
	 * Count inclusive days.
	 *
	 * @since 1.0.0
	 *
	 * @param DateTimeImmutable $from Start date.
	 * @param DateTimeImmutable $to   End date.
	 * @return int Inclusive day count.
	 */
	private function inclusiveDays( DateTimeImmutable $from, DateTimeImmutable $to ): int {
		return (int) $from->diff( $to )->days + 1;
	}

	/**
	 * Get the orders table name.
	 *
	 * @since 1.0.0
	 *
	 * @return string Table name.
	 */
	private function ordersTable(): string {
		return Schema::tableName( $this->wpdb, 'orders' );
	}

	/**
	 * Run a prepared aggregate query.
	 *
	 * @since 1.0.0
	 *
	 * @param callable(): mixed $query Runs the prepared query.
	 * @return array<int, object> Result rows.
	 *
	 * @throws ReportPersistenceException When the query fails.
	 */
	private function fetchRows( callable $query ): array {
		$wpdb = $this->wpdb;
		$rows = $query();

		if ( '' !== (string) $wpdb->last_error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new ReportPersistenceException( 'A sales report query failed: ' . $wpdb->last_error );
		}

		return is_array( $rows ) ? $rows : array();
	}
}
