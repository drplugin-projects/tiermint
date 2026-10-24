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

		$page    = max( 1, $page );
		$perPage = max( 1, $perPage );
		$wpdb    = $this->wpdb;
		$values  = array_merge( array( $this->table() ), $orderIds, array( $perPage, ( $page - 1 ) * $perPage ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE type = 'download' AND object_type = 'order' AND object_id IN ( " .
				implode( ', ', array_fill( 0, count( $orderIds ), '%d' ) ) . ' ) ORDER BY id ASC LIMIT %d OFFSET %d',
				$values
			)
		);

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

		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET ip_address = NULL WHERE type = 'download' AND object_type = 'order'" .
				' AND ip_address IS NOT NULL AND object_id IN ( ' . implode( ', ', array_fill( 0, count( $orderIds ), '%d' ) ) . ' )',
				array_merge( array( $this->table() ), $orderIds )
			)
		);
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
		$wpdb    = $this->wpdb;
		$values  = array_merge( array( $this->table() ), $this->licenseNeedles( $licenseIds ), array( $perPage, ( $page - 1 ) * $perPage ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- array_fill() writes one LIKE pair per licence.
			$wpdb->prepare(
				"SELECT * FROM %i WHERE type = 'download' AND object_type = 'release' AND ( " .
				implode( ' OR ', array_fill( 0, count( $licenseIds ), '( context LIKE %s OR context LIKE %s )' ) ) .
				' ) ORDER BY id ASC LIMIT %d OFFSET %d',
				$values
			)
		);

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

		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- array_fill() writes one LIKE pair per licence.
			$wpdb->prepare(
				"UPDATE %i SET ip_address = NULL WHERE type = 'download' AND object_type = 'release'" .
				' AND ip_address IS NOT NULL AND ( ' .
				implode( ' OR ', array_fill( 0, count( $licenseIds ), '( context LIKE %s OR context LIKE %s )' ) ) . ' )',
				array_merge( array( $this->table() ), $this->licenseNeedles( $licenseIds ) )
			)
		);
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

		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET message = 'License event (details removed after a personal data erasure request).', ip_address = NULL" .
				" WHERE object_type = 'license' AND object_id IN ( " . implode( ', ', array_fill( 0, count( $licenseIds ), '%d' ) ) . ' )',
				array_merge( array( $this->table() ), $licenseIds )
			)
		);
	}

	/**
	 * Build the LIKE patterns that find licence IDs in JSON context, two per licence.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, int> $licenseIds The license ids to match.
	 * @return array<int, string> The patterns, in placeholder order.
	 */
	private function licenseNeedles( array $licenseIds ): array {
		$needles = array();

		foreach ( $licenseIds as $licenseId ) {
			$needle    = '"license_id":' . (int) $licenseId;
			$needles[] = '%' . $this->wpdb->esc_like( $needle . ',' ) . '%';
			$needles[] = '%' . $this->wpdb->esc_like( $needle . '}' ) . '%';
		}

		return $needles;
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
