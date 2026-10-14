<?php
/**
 * Static catalog for ISO 4217 currency codes, labels, symbols, and decimals.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Catalog;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Holds currency labels, symbols and supported decimal places.
 * Three-decimal currencies are excluded because stored amounts support zero or two decimals.
 *
 * @since 1.0.0
 */
final class Currencies {

	/**
	 * Return the full currency catalog, sorted by label, code => entry.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array{label: string, symbol: string, decimals: int}>
	 */
	public static function all(): array {
		$currencies = self::map();

		uasort(
			$currencies,
			static fn ( array $a, array $b ): int => strcmp( (string) $a['label'], (string) $b['label'] )
		);

		/**
		 * Filter the currency catalog.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, array{label: string, symbol: string, decimals: int}> $currencies Code to entry.
		 */
		$filtered = apply_filters( 'tiermint_currencies', $currencies );

		if ( is_array( $filtered ) && ! empty( $filtered ) ) {
			return $filtered;
		}

		return $currencies;
	}

	/**
	 * Whether a currency code is in the catalog, case-insensitive.
	 *
	 * Use the filtered list so this matches the rendered dropdown.
	 *
	 * @since 1.0.0
	 *
	 * @param string $code ISO 4217 code.
	 * @return bool True when the code is in the catalog.
	 */
	public static function has( string $code ): bool {
		return null !== self::get( $code );
	}

	/**
	 * Get one currency entry by code, case-insensitive, or null when absent.
	 *
	 * @since 1.0.0
	 *
	 * @param string $code ISO 4217 code.
	 * @return array{label: string, symbol: string, decimals: int}|null
	 */
	public static function get( string $code ): ?array {
		$code = strtoupper( trim( $code ) );
		$all  = self::all();

		return $all[ $code ] ?? null;
	}

	/**
	 * The unfiltered currency catalog, code => entry.
	 *
	 * Keep symbols and decimal places aligned with {@see \DrPlugin\WP\TierMint\Util\Money}.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array{label: string, symbol: string, decimals: int}>
	 */
	private static function map(): array {
		return array(
			'USD' => array(
				'label'    => __( 'US Dollar', 'tiermint' ),
				'symbol'   => '$',
				'decimals' => 2,
			),
			'EUR' => array(
				'label'    => __( 'Euro', 'tiermint' ),
				'symbol'   => '€',
				'decimals' => 2,
			),
			'GBP' => array(
				'label'    => __( 'British Pound', 'tiermint' ),
				'symbol'   => '£',
				'decimals' => 2,
			),
			'AUD' => array(
				'label'    => __( 'Australian Dollar', 'tiermint' ),
				'symbol'   => 'A$',
				'decimals' => 2,
			),
			'BRL' => array(
				'label'    => __( 'Brazilian Real', 'tiermint' ),
				'symbol'   => 'R$',
				'decimals' => 2,
			),
			'CAD' => array(
				'label'    => __( 'Canadian Dollar', 'tiermint' ),
				'symbol'   => 'C$',
				'decimals' => 2,
			),
			'CHF' => array(
				'label'    => __( 'Swiss Franc', 'tiermint' ),
				'symbol'   => 'CHF ',
				'decimals' => 2,
			),
			'CNY' => array(
				'label'    => __( 'Chinese Yuan', 'tiermint' ),
				'symbol'   => '¥',
				'decimals' => 2,
			),
			'DKK' => array(
				'label'    => __( 'Danish Krone', 'tiermint' ),
				'symbol'   => 'kr ',
				'decimals' => 2,
			),
			'INR' => array(
				'label'    => __( 'Indian Rupee', 'tiermint' ),
				'symbol'   => '₹',
				'decimals' => 2,
			),
			'JPY' => array(
				'label'    => __( 'Japanese Yen', 'tiermint' ),
				'symbol'   => '¥',
				'decimals' => 0,
			),
			'KRW' => array(
				'label'    => __( 'South Korean Won', 'tiermint' ),
				'symbol'   => '₩',
				'decimals' => 0,
			),
			'MXN' => array(
				'label'    => __( 'Mexican Peso', 'tiermint' ),
				'symbol'   => 'MX$',
				'decimals' => 2,
			),
			'NOK' => array(
				'label'    => __( 'Norwegian Krone', 'tiermint' ),
				'symbol'   => 'kr ',
				'decimals' => 2,
			),
			'NZD' => array(
				'label'    => __( 'New Zealand Dollar', 'tiermint' ),
				'symbol'   => 'NZ$',
				'decimals' => 2,
			),
			'PLN' => array(
				'label'    => __( 'Polish Zloty', 'tiermint' ),
				'symbol'   => 'zł ',
				'decimals' => 2,
			),
			'SEK' => array(
				'label'    => __( 'Swedish Krona', 'tiermint' ),
				'symbol'   => 'kr ',
				'decimals' => 2,
			),
			'AED' => array(
				'label'    => __( 'UAE Dirham', 'tiermint' ),
				'symbol'   => 'AED',
				'decimals' => 2,
			),
			'ARS' => array(
				'label'    => __( 'Argentine Peso', 'tiermint' ),
				'symbol'   => 'ARS',
				'decimals' => 2,
			),
			'BDT' => array(
				'label'    => __( 'Bangladeshi Taka', 'tiermint' ),
				'symbol'   => 'BDT',
				'decimals' => 2,
			),
			'BGN' => array(
				'label'    => __( 'Bulgarian Lev', 'tiermint' ),
				'symbol'   => 'BGN',
				'decimals' => 2,
			),
			'CLP' => array(
				'label'    => __( 'Chilean Peso', 'tiermint' ),
				'symbol'   => 'CLP',
				'decimals' => 0,
			),
			'COP' => array(
				'label'    => __( 'Colombian Peso', 'tiermint' ),
				'symbol'   => 'COP',
				'decimals' => 2,
			),
			'CZK' => array(
				'label'    => __( 'Czech Koruna', 'tiermint' ),
				'symbol'   => 'CZK',
				'decimals' => 2,
			),
			'EGP' => array(
				'label'    => __( 'Egyptian Pound', 'tiermint' ),
				'symbol'   => 'EGP',
				'decimals' => 2,
			),
			'HKD' => array(
				'label'    => __( 'Hong Kong Dollar', 'tiermint' ),
				'symbol'   => 'HKD',
				'decimals' => 2,
			),
			'HUF' => array(
				'label'    => __( 'Hungarian Forint', 'tiermint' ),
				'symbol'   => 'HUF',
				'decimals' => 2,
			),
			'IDR' => array(
				'label'    => __( 'Indonesian Rupiah', 'tiermint' ),
				'symbol'   => 'IDR',
				'decimals' => 2,
			),
			'ILS' => array(
				'label'    => __( 'Israeli New Shekel', 'tiermint' ),
				'symbol'   => 'ILS',
				'decimals' => 2,
			),
			'KES' => array(
				'label'    => __( 'Kenyan Shilling', 'tiermint' ),
				'symbol'   => 'KES',
				'decimals' => 2,
			),
			'MAD' => array(
				'label'    => __( 'Moroccan Dirham', 'tiermint' ),
				'symbol'   => 'MAD',
				'decimals' => 2,
			),
			'MYR' => array(
				'label'    => __( 'Malaysian Ringgit', 'tiermint' ),
				'symbol'   => 'MYR',
				'decimals' => 2,
			),
			'NGN' => array(
				'label'    => __( 'Nigerian Naira', 'tiermint' ),
				'symbol'   => 'NGN',
				'decimals' => 2,
			),
			'PEN' => array(
				'label'    => __( 'Peruvian Sol', 'tiermint' ),
				'symbol'   => 'PEN',
				'decimals' => 2,
			),
			'PHP' => array(
				'label'    => __( 'Philippine Peso', 'tiermint' ),
				'symbol'   => 'PHP',
				'decimals' => 2,
			),
			'PKR' => array(
				'label'    => __( 'Pakistani Rupee', 'tiermint' ),
				'symbol'   => 'PKR',
				'decimals' => 2,
			),
			'QAR' => array(
				'label'    => __( 'Qatari Riyal', 'tiermint' ),
				'symbol'   => 'QAR',
				'decimals' => 2,
			),
			'RON' => array(
				'label'    => __( 'Romanian Leu', 'tiermint' ),
				'symbol'   => 'RON',
				'decimals' => 2,
			),
			'RUB' => array(
				'label'    => __( 'Russian Ruble', 'tiermint' ),
				'symbol'   => 'RUB',
				'decimals' => 2,
			),
			'SAR' => array(
				'label'    => __( 'Saudi Riyal', 'tiermint' ),
				'symbol'   => 'SAR',
				'decimals' => 2,
			),
			'SGD' => array(
				'label'    => __( 'Singapore Dollar', 'tiermint' ),
				'symbol'   => 'SGD',
				'decimals' => 2,
			),
			'THB' => array(
				'label'    => __( 'Thai Baht', 'tiermint' ),
				'symbol'   => 'THB',
				'decimals' => 2,
			),
			'TRY' => array(
				'label'    => __( 'Turkish Lira', 'tiermint' ),
				'symbol'   => 'TRY',
				'decimals' => 2,
			),
			'TWD' => array(
				'label'    => __( 'New Taiwan Dollar', 'tiermint' ),
				'symbol'   => 'TWD',
				'decimals' => 2,
			),
			'UAH' => array(
				'label'    => __( 'Ukrainian Hryvnia', 'tiermint' ),
				'symbol'   => 'UAH',
				'decimals' => 2,
			),
			'ZAR' => array(
				'label'    => __( 'South African Rand', 'tiermint' ),
				'symbol'   => 'ZAR',
				'decimals' => 2,
			),
			'BIF' => array(
				'label'    => __( 'Burundian Franc', 'tiermint' ),
				'symbol'   => 'BIF',
				'decimals' => 0,
			),
			'DJF' => array(
				'label'    => __( 'Djiboutian Franc', 'tiermint' ),
				'symbol'   => 'DJF',
				'decimals' => 0,
			),
			'GNF' => array(
				'label'    => __( 'Guinean Franc', 'tiermint' ),
				'symbol'   => 'GNF',
				'decimals' => 0,
			),
			'KMF' => array(
				'label'    => __( 'Comorian Franc', 'tiermint' ),
				'symbol'   => 'KMF',
				'decimals' => 0,
			),
			'MGA' => array(
				'label'    => __( 'Malagasy Ariary', 'tiermint' ),
				'symbol'   => 'MGA',
				'decimals' => 0,
			),
			'PYG' => array(
				'label'    => __( 'Paraguayan Guarani', 'tiermint' ),
				'symbol'   => 'PYG',
				'decimals' => 0,
			),
			'RWF' => array(
				'label'    => __( 'Rwandan Franc', 'tiermint' ),
				'symbol'   => 'RWF',
				'decimals' => 0,
			),
			'UGX' => array(
				'label'    => __( 'Ugandan Shilling', 'tiermint' ),
				'symbol'   => 'UGX',
				'decimals' => 0,
			),
			'VND' => array(
				'label'    => __( 'Vietnamese Dong', 'tiermint' ),
				'symbol'   => 'VND',
				'decimals' => 0,
			),
			'VUV' => array(
				'label'    => __( 'Vanuatu Vatu', 'tiermint' ),
				'symbol'   => 'VUV',
				'decimals' => 0,
			),
			'XAF' => array(
				'label'    => __( 'Central African CFA Franc', 'tiermint' ),
				'symbol'   => 'XAF',
				'decimals' => 0,
			),
			'XOF' => array(
				'label'    => __( 'West African CFA Franc', 'tiermint' ),
				'symbol'   => 'XOF',
				'decimals' => 0,
			),
			'XPF' => array(
				'label'    => __( 'CFP Franc', 'tiermint' ),
				'symbol'   => 'XPF',
				'decimals' => 0,
			),
		);
	}
}
