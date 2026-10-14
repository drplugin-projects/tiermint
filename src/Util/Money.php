<?php
/**
 * Locale-aware money formatting over minor-unit integers.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Store currency access and minor-unit amount formatting.
 *
 * Store currency defaults to USD.
 *
 * @since 1.0.0
 */
class Money {

	/**
	 * Currencies stored as whole amounts.
	 *
	 * Keep this aligned with the currency catalogue.
	 *
	 * @var array<int, string>
	 */
	public const ZERO_DECIMAL = array(
		'BIF',
		'CLP',
		'DJF',
		'GNF',
		'JPY',
		'KMF',
		'KRW',
		'MGA',
		'PYG',
		'RWF',
		'UGX',
		'VND',
		'VUV',
		'XAF',
		'XOF',
		'XPF',
	);

	/**
	 * Symbols for common currencies; anything else renders as "CODE amount".
	 *
	 * Keep this aligned with the currency catalogue.
	 *
	 * @var array<string, string>
	 */
	public const SYMBOLS = array(
		'AUD' => 'A$',
		'BRL' => 'R$',
		'CAD' => 'C$',
		'CHF' => 'CHF ',
		'CNY' => '¥',
		'DKK' => 'kr ',
		'EUR' => '€',
		'GBP' => '£',
		'INR' => '₹',
		'JPY' => '¥',
		'KRW' => '₩',
		'MXN' => 'MX$',
		'NOK' => 'kr ',
		'NZD' => 'NZ$',
		'PLN' => 'zł ',
		'SEK' => 'kr ',
		'USD' => '$',
	);

	/**
	 * Get the configured ISO 4217 currency code.
	 *
	 * @return string
	 */
	public function storeCurrency(): string {
		$settings = get_option( 'tiermint_settings' );
		$currency = ( is_array( $settings ) && ! empty( $settings['currency'] ) ) ? (string) $settings['currency'] : 'USD';
		$currency = strtoupper( substr( $currency, 0, 3 ) );

		return 1 === preg_match( '/^[A-Z]{3}$/', $currency ) ? $currency : 'USD';
	}

	/**
	 * Render a minor-unit amount as a locale-free decimal string.
	 *
	 * It is suitable for CSV and API values. Callers must supply the currency.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $minorUnits Amount in minor units.
	 * @param string $currency   ISO 4217 code.
	 * @return string Decimal value.
	 */
	public static function toDecimalString( int $minorUnits, string $currency ): string {
		$code     = strtoupper( $currency );
		$decimals = in_array( $code, self::ZERO_DECIMAL, true ) ? 0 : 2;
		$factor   = 0 === $decimals ? 1 : 100;

		return number_format( $minorUnits / $factor, $decimals, '.', '' );
	}

	/**
	 * Format a minor-unit amount for display.
	 *
	 * @param int         $minorUnits Amount in minor units.
	 * @param string|null $currency   ISO 4217 code, or null for the store currency.
	 * @return string
	 */
	public function format( int $minorUnits, ?string $currency = null ): string {
		$code     = strtoupper( $currency ?? $this->storeCurrency() );
		$decimals = in_array( $code, self::ZERO_DECIMAL, true ) ? 0 : 2;
		$factor   = 0 === $decimals ? 1 : 100;

		$sign   = $minorUnits < 0 ? '-' : '';
		$number = number_format_i18n( abs( $minorUnits ) / $factor, $decimals );

		$symbol = self::SYMBOLS[ $code ] ?? null;

		if ( null === $symbol ) {
			return $sign . $code . ' ' . $number;
		}

		return $sign . $symbol . $number;
	}
}
