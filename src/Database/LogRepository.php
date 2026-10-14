<?php
/**
 * Read/anonymize access to the `tiermint_logs` table for privacy tooling.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Database;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Reads and anonymizes logs for privacy tools.
 *
 * Logger keeps write operations.
 *
 * @since 1.0.0
 */
class LogRepository {

	/**
	 * The WordPress database abstraction.
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * Wire the repository with its database handle.
	 *
	 * @since 1.0.0
	 *
	 * @param \wpdb $wpdb The WordPress database abstraction.
	 */
	public function __construct( \wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Find download logs for orders, oldest first.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, int> $orderIds The customer's order ids.
	 * @param int             $page     The 1-based page number.
	 * @param int             $perPage  The page size.
	 * @return array<int, object> The matching log rows.
	 */
	public function findDownloadsForOrders( array $orderIds, int $page, int $perPage ): array {
		if ( array() === $orderIds ) {
			return array();
		}

		$page         = max( 1, $page );
		$perPage      = max( 1, $perPage );
		$placeholders = implode( ', ', array_fill( 0, count( $orderIds ), '%d' ) );

		$sql = 'SELECT * FROM ' . $this->table()
			. " WHERE type = 'download' AND object_type = 'order' AND object_id IN ( {$placeholders} )"
			. ' ORDER BY id ASC LIMIT %d OFFSET %d';

		$params   = $orderIds;
		$params[] = $perPage;
		$params[] = ( $page - 1 ) * $perPage;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The SQL embeds only the Schema-derived table name and generated %d placeholders; every value is bound via prepare(); a bounded privacy-tooling read.
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $params ) );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Remove IP addresses from order download logs.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, int> $orderIds The customer's order ids.
	 * @return void
	 */
	public function anonymizeDownloadIpsForOrders( array $orderIds ): void {
		if ( array() === $orderIds ) {
			return;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $orderIds ), '%d' ) );

		$sql = 'UPDATE ' . $this->table()
			. " SET ip_address = NULL WHERE type = 'download' AND object_type = 'order'"
			. " AND ip_address IS NOT NULL AND object_id IN ( {$placeholders} )";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The SQL embeds only the Schema-derived table name and generated %d placeholders; every id is bound via prepare(); logs carry no object cache.
		$this->wpdb->query( $this->wpdb->prepare( $sql, $orderIds ) );
	}

	/**
	 * Find release download logs for licences, oldest first.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, int> $licenseIds The customer's license ids.
	 * @param int             $page       The 1-based page number.
	 * @param int             $perPage    The page size.
	 * @return array<int, object> The matching log rows.
	 */
	public function findDownloadsForLicenses( array $licenseIds, int $page, int $perPage ): array {
		if ( array() === $licenseIds ) {
			return array();
		}

		$page    = max( 1, $page );
		$perPage = max( 1, $perPage );

		list( $where, $params ) = $this->licenseContextWhere( $licenseIds );

		$sql = 'SELECT * FROM ' . $this->table()
			. " WHERE type = 'download' AND object_type = 'release' AND ( {$where} )"
			. ' ORDER BY id ASC LIMIT %d OFFSET %d';

		$params[] = $perPage;
		$params[] = ( $page - 1 ) * $perPage;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The SQL embeds only the Schema-derived table name and generated %s/%d placeholders; every value is bound via prepare(); a bounded privacy-tooling read.
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $params ) );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Remove IP addresses from release download logs.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, int> $licenseIds The customer's license ids.
	 * @return void
	 */
	public function anonymizeDownloadIpsForLicenses( array $licenseIds ): void {
		if ( array() === $licenseIds ) {
			return;
		}

		list( $where, $params ) = $this->licenseContextWhere( $licenseIds );

		$sql = 'UPDATE ' . $this->table()
			. " SET ip_address = NULL WHERE type = 'download' AND object_type = 'release'"
			. " AND ip_address IS NOT NULL AND ( {$where} )";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The SQL embeds only the Schema-derived table name and generated %s placeholders; every pattern is bound via prepare(); logs carry no object cache.
		$this->wpdb->query( $this->wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Redact personal data from licence audit logs.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, int> $licenseIds The customer's license ids.
	 * @return void
	 */
	public function anonymizeLicenseLogsForLicenses( array $licenseIds ): void {
		if ( array() === $licenseIds ) {
			return;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $licenseIds ), '%d' ) );

		$sql = 'UPDATE ' . $this->table()
			. " SET message = 'License event (details removed after a personal data erasure request).', ip_address = NULL"
			. " WHERE object_type = 'license' AND object_id IN ( {$placeholders} )";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The SQL embeds only the Schema-derived table name, a fixed literal message, and generated %d placeholders; every id is bound via prepare(); logs carry no object cache.
		$this->wpdb->query( $this->wpdb->prepare( $sql, $licenseIds ) );
	}

	/**
	 * Build a LIKE clause for licence IDs in JSON context.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, int> $licenseIds The license ids to match.
	 * @return array{0: string, 1: array<int, string>} The WHERE fragment of %s placeholders and its bind values.
	 */
	private function licenseContextWhere( array $licenseIds ): array {
		$clauses = array();
		$params  = array();

		foreach ( $licenseIds as $licenseId ) {
			$clauses[] = '( context LIKE %s OR context LIKE %s )';
			$needle    = '"license_id":' . (int) $licenseId;
			$params[]  = '%' . $this->wpdb->esc_like( $needle . ',' ) . '%';
			$params[]  = '%' . $this->wpdb->esc_like( $needle . '}' ) . '%';
		}

		return array( implode( ' OR ', $clauses ), $params );
	}

	/**
	 * Get the log table name.
	 *
	 * @since 1.0.0
	 *
	 * @return string The table name.
	 */
	private function table(): string {
		return Schema::tableName( $this->wpdb, 'logs' );
	}
}
