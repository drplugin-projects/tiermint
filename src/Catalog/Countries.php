<?php
/**
 * Static helper for ISO 3166-1 alpha-2 country codes and names.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Catalog;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Holds the translatable ISO 3166-1 alpha-2 country list.
 * Sites can extend the list with `tiermint_countries`.
 *
 * @since 1.0.0
 */
final class Countries {

	/**
	 * Return all countries as a code => name map, sorted by name.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string> ISO 3166-1 alpha-2 code to translated name.
	 */
	public static function all(): array {
		$countries = self::names();

		asort( $countries, SORT_LOCALE_STRING );

		/**
		 * Filter the countries list.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string> $countries Country code to name.
		 */
		$filtered = apply_filters( 'tiermint_countries', $countries );

		if ( is_array( $filtered ) && ! empty( $filtered ) ) {
			return $filtered;
		}

		return $countries;
	}

	/**
	 * Check whether a country code is in the catalog, case-insensitive.
	 *
	 * Use the filtered list so site-added codes remain selectable.
	 *
	 * @since 1.0.0
	 *
	 * @param string $code ISO 3166-1 alpha-2 code.
	 * @return bool True when the code is one the catalog offers.
	 */
	public static function has( string $code ): bool {
		$code = strtoupper( trim( $code ) );
		$all  = self::all();

		return isset( $all[ $code ] );
	}

	/**
	 * Get the translated name for a country code, or null when the catalog does
	 * not offer it.
	 *
	 * Use the filtered list so this matches {@see has()}.
	 *
	 * @since 1.0.0
	 *
	 * @param string $code ISO 3166-1 alpha-2 code.
	 * @return string|null The translated name, or null.
	 */
	public static function name( string $code ): ?string {
		$code = strtoupper( trim( $code ) );
		$all  = self::all();

		return $all[ $code ] ?? null;
	}

	/**
	 * The unfiltered country list, code => translated name.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string>
	 */
	private static function names(): array {
		return array(
			'AD' => __( 'Andorra', 'tiermint' ),
			'AE' => __( 'United Arab Emirates', 'tiermint' ),
			'AF' => __( 'Afghanistan', 'tiermint' ),
			'AG' => __( 'Antigua and Barbuda', 'tiermint' ),
			'AI' => __( 'Anguilla', 'tiermint' ),
			'AL' => __( 'Albania', 'tiermint' ),
			'AM' => __( 'Armenia', 'tiermint' ),
			'AO' => __( 'Angola', 'tiermint' ),
			'AQ' => __( 'Antarctica', 'tiermint' ),
			'AR' => __( 'Argentina', 'tiermint' ),
			'AS' => __( 'American Samoa', 'tiermint' ),
			'AT' => __( 'Austria', 'tiermint' ),
			'AU' => __( 'Australia', 'tiermint' ),
			'AW' => __( 'Aruba', 'tiermint' ),
			'AX' => __( 'Aland Islands', 'tiermint' ),
			'AZ' => __( 'Azerbaijan', 'tiermint' ),
			'BA' => __( 'Bosnia and Herzegovina', 'tiermint' ),
			'BB' => __( 'Barbados', 'tiermint' ),
			'BD' => __( 'Bangladesh', 'tiermint' ),
			'BE' => __( 'Belgium', 'tiermint' ),
			'BF' => __( 'Burkina Faso', 'tiermint' ),
			'BG' => __( 'Bulgaria', 'tiermint' ),
			'BH' => __( 'Bahrain', 'tiermint' ),
			'BI' => __( 'Burundi', 'tiermint' ),
			'BJ' => __( 'Benin', 'tiermint' ),
			'BL' => __( 'Saint Barthelemy', 'tiermint' ),
			'BM' => __( 'Bermuda', 'tiermint' ),
			'BN' => __( 'Brunei Darussalam', 'tiermint' ),
			'BO' => __( 'Bolivia', 'tiermint' ),
			'BQ' => __( 'Bonaire Sint Eustatius and Saba', 'tiermint' ),
			'BR' => __( 'Brazil', 'tiermint' ),
			'BS' => __( 'Bahamas', 'tiermint' ),
			'BT' => __( 'Bhutan', 'tiermint' ),
			'BV' => __( 'Bouvet Island', 'tiermint' ),
			'BW' => __( 'Botswana', 'tiermint' ),
			'BY' => __( 'Belarus', 'tiermint' ),
			'BZ' => __( 'Belize', 'tiermint' ),
			'CA' => __( 'Canada', 'tiermint' ),
			'CC' => __( 'Cocos Islands', 'tiermint' ),
			'CD' => __( 'Democratic Republic of the Congo', 'tiermint' ),
			'CF' => __( 'Central African Republic', 'tiermint' ),
			'CG' => __( 'Republic of the Congo', 'tiermint' ),
			'CH' => __( 'Switzerland', 'tiermint' ),
			'CI' => __( 'Cote d\'Ivoire', 'tiermint' ),
			'CK' => __( 'Cook Islands', 'tiermint' ),
			'CL' => __( 'Chile', 'tiermint' ),
			'CM' => __( 'Cameroon', 'tiermint' ),
			'CN' => __( 'China', 'tiermint' ),
			'CO' => __( 'Colombia', 'tiermint' ),
			'CR' => __( 'Costa Rica', 'tiermint' ),
			'CU' => __( 'Cuba', 'tiermint' ),
			'CV' => __( 'Cape Verde', 'tiermint' ),
			'CW' => __( 'Curacao', 'tiermint' ),
			'CX' => __( 'Christmas Island', 'tiermint' ),
			'CY' => __( 'Cyprus', 'tiermint' ),
			'CZ' => __( 'Czechia', 'tiermint' ),
			'DE' => __( 'Germany', 'tiermint' ),
			'DJ' => __( 'Djibouti', 'tiermint' ),
			'DK' => __( 'Denmark', 'tiermint' ),
			'DM' => __( 'Dominica', 'tiermint' ),
			'DO' => __( 'Dominican Republic', 'tiermint' ),
			'DZ' => __( 'Algeria', 'tiermint' ),
			'EC' => __( 'Ecuador', 'tiermint' ),
			'EE' => __( 'Estonia', 'tiermint' ),
			'EG' => __( 'Egypt', 'tiermint' ),
			'EH' => __( 'Western Sahara', 'tiermint' ),
			'ER' => __( 'Eritrea', 'tiermint' ),
			'ES' => __( 'Spain', 'tiermint' ),
			'ET' => __( 'Ethiopia', 'tiermint' ),
			'FI' => __( 'Finland', 'tiermint' ),
			'FJ' => __( 'Fiji', 'tiermint' ),
			'FK' => __( 'Falkland Islands', 'tiermint' ),
			'FM' => __( 'Micronesia', 'tiermint' ),
			'FO' => __( 'Faroe Islands', 'tiermint' ),
			'FR' => __( 'France', 'tiermint' ),
			'GA' => __( 'Gabon', 'tiermint' ),
			'GB' => __( 'United Kingdom', 'tiermint' ),
			'GD' => __( 'Grenada', 'tiermint' ),
			'GE' => __( 'Georgia', 'tiermint' ),
			'GF' => __( 'French Guiana', 'tiermint' ),
			'GG' => __( 'Guernsey', 'tiermint' ),
			'GH' => __( 'Ghana', 'tiermint' ),
			'GI' => __( 'Gibraltar', 'tiermint' ),
			'GL' => __( 'Greenland', 'tiermint' ),
			'GM' => __( 'Gambia', 'tiermint' ),
			'GN' => __( 'Guinea', 'tiermint' ),
			'GP' => __( 'Guadeloupe', 'tiermint' ),
			'GQ' => __( 'Equatorial Guinea', 'tiermint' ),
			'GR' => __( 'Greece', 'tiermint' ),
			'GS' => __( 'South Georgia and the South Sandwich Islands', 'tiermint' ),
			'GT' => __( 'Guatemala', 'tiermint' ),
			'GU' => __( 'Guam', 'tiermint' ),
			'GW' => __( 'Guinea-Bissau', 'tiermint' ),
			'GY' => __( 'Guyana', 'tiermint' ),
			'HK' => __( 'Hong Kong', 'tiermint' ),
			'HM' => __( 'Heard Island and McDonald Islands', 'tiermint' ),
			'HN' => __( 'Honduras', 'tiermint' ),
			'HR' => __( 'Croatia', 'tiermint' ),
			'HT' => __( 'Haiti', 'tiermint' ),
			'HU' => __( 'Hungary', 'tiermint' ),
			'ID' => __( 'Indonesia', 'tiermint' ),
			'IE' => __( 'Ireland', 'tiermint' ),
			'IL' => __( 'Israel', 'tiermint' ),
			'IM' => __( 'Isle of Man', 'tiermint' ),
			'IN' => __( 'India', 'tiermint' ),
			'IO' => __( 'British Indian Ocean Territory', 'tiermint' ),
			'IQ' => __( 'Iraq', 'tiermint' ),
			'IR' => __( 'Iran', 'tiermint' ),
			'IS' => __( 'Iceland', 'tiermint' ),
			'IT' => __( 'Italy', 'tiermint' ),
			'JE' => __( 'Jersey', 'tiermint' ),
			'JM' => __( 'Jamaica', 'tiermint' ),
			'JO' => __( 'Jordan', 'tiermint' ),
			'JP' => __( 'Japan', 'tiermint' ),
			'KE' => __( 'Kenya', 'tiermint' ),
			'KG' => __( 'Kyrgyzstan', 'tiermint' ),
			'KH' => __( 'Cambodia', 'tiermint' ),
			'KI' => __( 'Kiribati', 'tiermint' ),
			'KM' => __( 'Comoros', 'tiermint' ),
			'KN' => __( 'Saint Kitts and Nevis', 'tiermint' ),
			'KP' => __( 'North Korea', 'tiermint' ),
			'KR' => __( 'South Korea', 'tiermint' ),
			'KW' => __( 'Kuwait', 'tiermint' ),
			'KY' => __( 'Cayman Islands', 'tiermint' ),
			'KZ' => __( 'Kazakhstan', 'tiermint' ),
			'LA' => __( 'Laos', 'tiermint' ),
			'LB' => __( 'Lebanon', 'tiermint' ),
			'LC' => __( 'Saint Lucia', 'tiermint' ),
			'LI' => __( 'Liechtenstein', 'tiermint' ),
			'LK' => __( 'Sri Lanka', 'tiermint' ),
			'LR' => __( 'Liberia', 'tiermint' ),
			'LS' => __( 'Lesotho', 'tiermint' ),
			'LT' => __( 'Lithuania', 'tiermint' ),
			'LU' => __( 'Luxembourg', 'tiermint' ),
			'LV' => __( 'Latvia', 'tiermint' ),
			'LY' => __( 'Libya', 'tiermint' ),
			'MA' => __( 'Morocco', 'tiermint' ),
			'MC' => __( 'Monaco', 'tiermint' ),
			'MD' => __( 'Moldova', 'tiermint' ),
			'ME' => __( 'Montenegro', 'tiermint' ),
			'MF' => __( 'Saint Martin', 'tiermint' ),
			'MG' => __( 'Madagascar', 'tiermint' ),
			'MH' => __( 'Marshall Islands', 'tiermint' ),
			'MK' => __( 'North Macedonia', 'tiermint' ),
			'ML' => __( 'Mali', 'tiermint' ),
			'MM' => __( 'Myanmar', 'tiermint' ),
			'MN' => __( 'Mongolia', 'tiermint' ),
			'MO' => __( 'Macao', 'tiermint' ),
			'MP' => __( 'Northern Mariana Islands', 'tiermint' ),
			'MQ' => __( 'Martinique', 'tiermint' ),
			'MR' => __( 'Mauritania', 'tiermint' ),
			'MS' => __( 'Montserrat', 'tiermint' ),
			'MT' => __( 'Malta', 'tiermint' ),
			'MU' => __( 'Mauritius', 'tiermint' ),
			'MV' => __( 'Maldives', 'tiermint' ),
			'MW' => __( 'Malawi', 'tiermint' ),
			'MX' => __( 'Mexico', 'tiermint' ),
			'MY' => __( 'Malaysia', 'tiermint' ),
			'MZ' => __( 'Mozambique', 'tiermint' ),
			'NA' => __( 'Namibia', 'tiermint' ),
			'NC' => __( 'New Caledonia', 'tiermint' ),
			'NE' => __( 'Niger', 'tiermint' ),
			'NF' => __( 'Norfolk Island', 'tiermint' ),
			'NG' => __( 'Nigeria', 'tiermint' ),
			'NI' => __( 'Nicaragua', 'tiermint' ),
			'NL' => __( 'Netherlands', 'tiermint' ),
			'NO' => __( 'Norway', 'tiermint' ),
			'NP' => __( 'Nepal', 'tiermint' ),
			'NR' => __( 'Nauru', 'tiermint' ),
			'NU' => __( 'Niue', 'tiermint' ),
			'NZ' => __( 'New Zealand', 'tiermint' ),
			'OM' => __( 'Oman', 'tiermint' ),
			'PA' => __( 'Panama', 'tiermint' ),
			'PE' => __( 'Peru', 'tiermint' ),
			'PF' => __( 'French Polynesia', 'tiermint' ),
			'PG' => __( 'Papua New Guinea', 'tiermint' ),
			'PH' => __( 'Philippines', 'tiermint' ),
			'PK' => __( 'Pakistan', 'tiermint' ),
			'PL' => __( 'Poland', 'tiermint' ),
			'PM' => __( 'Saint Pierre and Miquelon', 'tiermint' ),
			'PN' => __( 'Pitcairn', 'tiermint' ),
			'PR' => __( 'Puerto Rico', 'tiermint' ),
			'PS' => __( 'Palestine', 'tiermint' ),
			'PT' => __( 'Portugal', 'tiermint' ),
			'PW' => __( 'Palau', 'tiermint' ),
			'PY' => __( 'Paraguay', 'tiermint' ),
			'QA' => __( 'Qatar', 'tiermint' ),
			'RE' => __( 'Reunion', 'tiermint' ),
			'RO' => __( 'Romania', 'tiermint' ),
			'RS' => __( 'Serbia', 'tiermint' ),
			'RU' => __( 'Russia', 'tiermint' ),
			'RW' => __( 'Rwanda', 'tiermint' ),
			'SA' => __( 'Saudi Arabia', 'tiermint' ),
			'SB' => __( 'Solomon Islands', 'tiermint' ),
			'SC' => __( 'Seychelles', 'tiermint' ),
			'SD' => __( 'Sudan', 'tiermint' ),
			'SE' => __( 'Sweden', 'tiermint' ),
			'SG' => __( 'Singapore', 'tiermint' ),
			'SH' => __( 'Saint Helena', 'tiermint' ),
			'SI' => __( 'Slovenia', 'tiermint' ),
			'SJ' => __( 'Svalbard and Jan Mayen', 'tiermint' ),
			'SK' => __( 'Slovakia', 'tiermint' ),
			'SL' => __( 'Sierra Leone', 'tiermint' ),
			'SM' => __( 'San Marino', 'tiermint' ),
			'SN' => __( 'Senegal', 'tiermint' ),
			'SO' => __( 'Somalia', 'tiermint' ),
			'SR' => __( 'Suriname', 'tiermint' ),
			'SS' => __( 'South Sudan', 'tiermint' ),
			'ST' => __( 'Sao Tome and Principe', 'tiermint' ),
			'SV' => __( 'El Salvador', 'tiermint' ),
			'SX' => __( 'Sint Maarten', 'tiermint' ),
			'SY' => __( 'Syria', 'tiermint' ),
			'SZ' => __( 'Eswatini', 'tiermint' ),
			'TC' => __( 'Turks and Caicos Islands', 'tiermint' ),
			'TD' => __( 'Chad', 'tiermint' ),
			'TF' => __( 'French Southern Territories', 'tiermint' ),
			'TG' => __( 'Togo', 'tiermint' ),
			'TH' => __( 'Thailand', 'tiermint' ),
			'TJ' => __( 'Tajikistan', 'tiermint' ),
			'TK' => __( 'Tokelau', 'tiermint' ),
			'TL' => __( 'Timor-Leste', 'tiermint' ),
			'TM' => __( 'Turkmenistan', 'tiermint' ),
			'TN' => __( 'Tunisia', 'tiermint' ),
			'TO' => __( 'Tonga', 'tiermint' ),
			'TR' => __( 'Turkiye', 'tiermint' ),
			'TT' => __( 'Trinidad and Tobago', 'tiermint' ),
			'TV' => __( 'Tuvalu', 'tiermint' ),
			'TW' => __( 'Taiwan', 'tiermint' ),
			'TZ' => __( 'Tanzania', 'tiermint' ),
			'UA' => __( 'Ukraine', 'tiermint' ),
			'UG' => __( 'Uganda', 'tiermint' ),
			'UM' => __( 'United States Minor Outlying Islands', 'tiermint' ),
			'US' => __( 'United States', 'tiermint' ),
			'UY' => __( 'Uruguay', 'tiermint' ),
			'UZ' => __( 'Uzbekistan', 'tiermint' ),
			'VA' => __( 'Vatican City', 'tiermint' ),
			'VC' => __( 'Saint Vincent and the Grenadines', 'tiermint' ),
			'VE' => __( 'Venezuela', 'tiermint' ),
			'VG' => __( 'Virgin Islands British', 'tiermint' ),
			'VI' => __( 'Virgin Islands US', 'tiermint' ),
			'VN' => __( 'Vietnam', 'tiermint' ),
			'VU' => __( 'Vanuatu', 'tiermint' ),
			'WF' => __( 'Wallis and Futuna', 'tiermint' ),
			'WS' => __( 'Samoa', 'tiermint' ),
			'YE' => __( 'Yemen', 'tiermint' ),
			'YT' => __( 'Mayotte', 'tiermint' ),
			'ZA' => __( 'South Africa', 'tiermint' ),
			'ZM' => __( 'Zambia', 'tiermint' ),
			'ZW' => __( 'Zimbabwe', 'tiermint' ),
		);
	}
}
