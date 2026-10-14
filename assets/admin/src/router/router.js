/**
 * Hash router for the admin SPA.
 */

/**
 * External dependencies
 */
import { useState, useEffect } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { normalizeHash, splitHashQuery } from './hashQuery';

export { normalizeHash };

/**
 * The current normalized hash for the document.
 *
 * @return {string} The normalized `window.location.hash`.
 */
export function getCurrentHash() {
	return normalizeHash(
		typeof window !== 'undefined' ? window.location.hash : ''
	);
}

/**
 * Split a hash into its non-empty path segments, query dropped.
 *
 * Query values are not route segments.
 *
 * @param {string} path A hash path, with or without a query.
 * @return {string[]} The path segments.
 */
function segmentsOf( path ) {
	return splitHashQuery( path )
		.path.replace( /^#/, '' )
		.split( '/' )
		.filter( ( segment ) => segment !== '' );
}

/**
 * Match a single route pattern against concrete path segments.
 *
 * @param {string[]} patternSegments The route pattern segments (may include
 *                                   `:name` params).
 * @param {string[]} pathSegments    The concrete path segments to match.
 * @return {Object|null} The extracted params object on a match, or null.
 */
function matchPattern( patternSegments, pathSegments ) {
	if ( patternSegments.length !== pathSegments.length ) {
		return null;
	}

	const params = {};
	for ( let i = 0; i < patternSegments.length; i++ ) {
		const patternSegment = patternSegments[ i ];
		const pathSegment = pathSegments[ i ];

		if ( patternSegment.startsWith( ':' ) ) {
			params[ patternSegment.slice( 1 ) ] =
				decodeURIComponent( pathSegment );
			continue;
		}

		if ( patternSegment !== pathSegment ) {
			return null;
		}
	}

	return params;
}

/**
 * Match a hash against a list of route entries.
 *
 * Prefer literal routes over parameter routes.
 *
 * @param {Array}  routes The route entries to match against.
 * @param {string} hash   The raw hash to resolve.
 * @return {{route: Object, params: Object}|null} The matched entry and its
 *         extracted params, or null when nothing matches.
 */
export function matchRoute( routes, hash ) {
	const pathSegments = segmentsOf( normalizeHash( hash ) );

	let best = null;
	let bestParamCount = Infinity;

	for ( const route of routes ) {
		const patternSegments = segmentsOf( route.path );
		const params = matchPattern( patternSegments, pathSegments );
		if ( params === null ) {
			continue;
		}

		const paramCount = patternSegments.filter( ( segment ) =>
			segment.startsWith( ':' )
		).length;

		if ( paramCount < bestParamCount ) {
			best = { route, params };
			bestParamCount = paramCount;
		}
	}

	return best;
}

/**
 * React hook that tracks the current normalized hash.
 *
 * Re-read the hash on mount and after each hashchange event.
 *
 * @return {string} The current normalized hash.
 */
export function useHashRoute() {
	const [ hash, setHash ] = useState( getCurrentHash() );

	useEffect( () => {
		const handleChange = () => setHash( getCurrentHash() );
		window.addEventListener( 'hashchange', handleChange );
		// Guard against a hash that changed before this effect ran.
		handleChange();
		return () => window.removeEventListener( 'hashchange', handleChange );
	}, [] );

	return hash;
}
