/**
 * Hash path and query helpers shared below the app layer.
 */

/**
 * Normalize a raw hash to a canonical route path.
 *
 * An empty hash and `#` mean the root screen.
 *
 * @param {string} [hash] The raw `window.location.hash` value.
 * @return {string} The normalized hash, never empty.
 */
export function normalizeHash( hash ) {
	if ( ! hash || hash === '#' ) {
		return '#/';
	}
	return hash;
}

/**
 * Split a hash into its normalized path and its raw query string.
 *
 * Split only at the first `?`.
 *
 * @param {string} [hash] A raw or normalized hash.
 * @return {{path: string, query: string}} The two halves; query is '' when
 *         there is none.
 */
export function splitHashQuery( hash ) {
	const raw = typeof hash === 'string' ? hash : '';
	const index = raw.indexOf( '?' );

	if ( index === -1 ) {
		return { path: normalizeHash( raw ), query: '' };
	}

	return {
		path: normalizeHash( raw.slice( 0, index ) ),
		query: raw.slice( index + 1 ),
	};
}

/**
 * Decode one key or value, keeping it as-is when it will not decode.
 *
 * Keep malformed user input from stopping app boot.
 *
 * @param {string} value The raw, still-encoded fragment.
 * @return {string} The decoded value, or the input unchanged.
 */
function decodePart( value ) {
	try {
		return decodeURIComponent( value );
	} catch {
		return value;
	}
}

/**
 * Read a query string into decoded key/value pairs.
 *
 * @param {string} [query] The raw query half of a hash, no leading `?`.
 * @return {Object<string, string>} The decoded params; empty when there are none.
 */
export function parseHashQuery( query ) {
	const params = {};

	if ( ! query ) {
		return params;
	}

	query.split( '&' ).forEach( ( pair ) => {
		if ( ! pair ) {
			return;
		}

		const index = pair.indexOf( '=' );
		const key = index === -1 ? pair : pair.slice( 0, index );
		const value = index === -1 ? '' : pair.slice( index + 1 );

		params[ decodePart( key ) ] = decodePart( value );
	} );

	return params;
}

/**
 * Build a hash from a path and a set of params.
 *
 * Keep empty values. Only null and undefined are omitted.
 *
 * @param {string} path   A hash path, leading `#` included.
 * @param {Object} params The params to write, values coerced to strings.
 * @return {string} The full hash.
 */
export function buildHash( path, params ) {
	const query = Object.keys( params )
		.filter( ( key ) => {
			const value = params[ key ];
			return value !== undefined && value !== null;
		} )
		.map(
			( key ) =>
				encodeURIComponent( key ) +
				'=' +
				encodeURIComponent( params[ key ] )
		)
		.join( '&' );

	const base = normalizeHash( path );

	return query ? base + '?' + query : base;
}
