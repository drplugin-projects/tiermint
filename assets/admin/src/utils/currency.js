/**
 * Shared money helpers for the admin SPA.
 *
 * Keep client money conversion aligned with the server.
 */

/**
 * Currencies with no fractional minor unit.
 *
 * @type {string[]}
 */
const ZERO_DECIMAL = [
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
];

/**
 * Fallback symbols when the boot catalog is unavailable.
 *
 * @type {Object<string, string>}
 */
const FALLBACK_SYMBOLS = {
	AUD: 'A$',
	BRL: 'R$',
	CAD: 'C$',
	CHF: 'CHF ',
	CNY: '¥',
	DKK: 'kr ',
	EUR: '€',
	GBP: '£',
	INR: '₹',
	JPY: '¥',
	KRW: '₩',
	MXN: 'MX$',
	NOK: 'kr ',
	NZD: 'NZ$',
	PLN: 'zł ',
	SEK: 'kr ',
	USD: '$',
};

/**
 * Check whether a currency has no fractional minor unit.
 *
 * @param {string} currency The ISO 4217 currency code.
 * @return {boolean} True when the currency is zero-decimal.
 */
export function isZeroDecimal( currency ) {
	return ZERO_DECIMAL.includes( String( currency || 'USD' ).toUpperCase() );
}

/**
 * Get a currency symbol from the catalog or fallback map.
 *
 * @param {string} code         The ISO 4217 currency code.
 * @param {Array}  [currencies] The boot catalog currency list ([{code, symbol}]).
 * @return {string} The display symbol, or the code when none is known.
 */
export function currencySymbol( code, currencies ) {
	const upper = String( code || '' ).toUpperCase();

	if ( Array.isArray( currencies ) ) {
		const found = currencies.find(
			( entry ) =>
				entry && String( entry.code || '' ).toUpperCase() === upper
		);
		if ( found && found.symbol ) {
			return String( found.symbol );
		}
	}

	return FALLBACK_SYMBOLS[ upper ] || upper;
}

/**
 * Get a currency's minor-unit divisor.
 *
 * @param {string} currency The ISO 4217 currency code.
 * @return {number} The divisor between minor and major units.
 */
function minorDivisor( currency ) {
	return isZeroDecimal( currency ) ? 1 : 100;
}

/**
 * Convert a minor-unit value for a text input.
 *
 * @param {number} minor    The amount in the currency's minor unit.
 * @param {string} currency The ISO 4217 currency code.
 * @return {string} The decimal string for display in an input, or empty.
 */
export function minorToDecimal( minor, currency ) {
	const value = Number( minor );
	if ( ! Number.isFinite( value ) ) {
		return '';
	}
	return isZeroDecimal( currency )
		? String( value )
		: ( value / 100 ).toFixed( 2 );
}

/**
 * Convert a text input value to minor units.
 *
 * @param {string} value    The amount as typed, in major units.
 * @param {string} currency The ISO 4217 currency code.
 * @return {number} The amount in minor units as an integer, or 0.
 */
export function decimalToMinor( value, currency ) {
	const number = parseFloat( value );
	if ( ! Number.isFinite( number ) ) {
		return 0;
	}
	return Math.round( number * minorDivisor( currency ) );
}

/**
 * Format a minor-unit value for display.
 *
 * @param {number} minor    The amount in the currency's minor unit (may be negative).
 * @param {string} currency The ISO 4217 currency code.
 * @return {string} The formatted, human-readable amount.
 */
export function formatMinor( minor, currency ) {
	const code = String( currency || 'USD' ).toUpperCase();
	const value = Number( minor || 0 ) / minorDivisor( code );

	try {
		return new Intl.NumberFormat( undefined, {
			style: 'currency',
			currency: code,
		} ).format( value );
	} catch {
		return `${ code } ${ value.toFixed( isZeroDecimal( code ) ? 0 : 2 ) }`;
	}
}
