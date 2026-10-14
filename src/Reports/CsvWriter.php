<?php
/**
 * A small RFC 4180 CSV serializer with spreadsheet formula-injection guarding.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Reports;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * RFC 4180 CSV serializer with formula-injection protection.
 *
 * It returns a string, so it does not use {@see \fputcsv()}.
 *
 * @since 1.0.0
 */
final class CsvWriter {

	/**
	 * The RFC 4180 record terminator.
	 *
	 * @var string
	 */
	private const CRLF = "\r\n";

	/**
	 * Leading characters that spreadsheets can treat as formulas.
	 *
	 * @var string
	 */
	private const FORMULA_LEADS = "=+-@\t\r\n";

	/**
	 * Decimal number pattern, exempt from the formula guard.
	 *
	 * @var string
	 */
	private const LOOKS_NUMERIC = '/\A-?\d+(\.\d+)?\z/';

	/**
	 * Serialize a header and rows into RFC 4180 CSV text.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string>             $header The column names.
	 * @param array<int, array<int, string>> $rows   The data rows, each a list of string cells.
	 * @return string The CSV document, CRLF-terminated, guarded and quoted per the class contract.
	 */
	public function write( array $header, array $rows ): string {
		$out = $this->record( $header );

		foreach ( $rows as $row ) {
			$out .= $this->record( $row );
		}

		return $out;
	}

	/**
	 * Guard, quote and serialize one record.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string> $fields The record's cells.
	 * @return string The CRLF-terminated record.
	 */
	private function record( array $fields ): string {
		$cells = array();

		foreach ( $fields as $field ) {
			$cells[] = $this->quote( $this->guard( $field ) );
		}

		return implode( ',', $cells ) . self::CRLF;
	}

	/**
	 * Prefix formula-shaped text with a quote.
	 *
	 * Decimal numbers remain numeric for spreadsheet totals.
	 *
	 * @since 1.0.0
	 *
	 * @param string $field The raw cell value.
	 * @return string The guarded value.
	 */
	private function guard( string $field ): string {
		if ( '' === $field || 1 === preg_match( self::LOOKS_NUMERIC, $field ) ) {
			return $field;
		}

		if ( false !== strpos( self::FORMULA_LEADS, $field[0] ) ) {
			return "'" . $field;
		}

		return $field;
	}

	/**
	 * Quote a field only when RFC 4180 requires it.
	 *
	 * @since 1.0.0
	 *
	 * @param string $field The (already guarded) cell value.
	 * @return string The field, enclosed and escaped only if it needs it.
	 */
	private function quote( string $field ): string {
		if ( 1 !== preg_match( '/[",\r\n]/', $field ) ) {
			return $field;
		}

		return '"' . str_replace( '"', '""', $field ) . '"';
	}
}
