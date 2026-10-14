/**
 * Stores list filters and pages in the URL hash.
 *
 * Filter edits replace history. Page moves add entries. Default values stay out
 * of the URL.
 */

/**
 * External dependencies
 */
import { useCallback, useRef } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { useHashRoute } from '../router/router';
import { buildHash, parseHashQuery, splitHashQuery } from '../router/hashQuery';
import { replaceHashAnnounced, setHash } from './navigate';

/**
 * The highest page a URL can ask for.
 *
 * @type {number}
 */
const MAX_PAGE = 1000000;

/**
 * Read a raw page param as a page number.
 *
 * Invalid values use the first page. The limit prevents invalid REST queries.
 *
 * @param {string} [raw] The raw param value.
 * @return {number} A page number, between 1 and {@link MAX_PAGE}.
 */
function pageFrom( raw ) {
	if ( typeof raw !== 'string' || ! /^\d+$/.test( raw ) ) {
		return 1;
	}

	return Math.min( Math.max( parseInt( raw, 10 ), 1 ), MAX_PAGE );
}

/**
 * Track a list screen's page and filters through the URL hash.
 *
 * @param {Object} defaults Screen params, their defaults and URL order.
 * @return {{page: number, params: Object, setPage: Function, replacePage: Function, setFilter: Function, setFilters: Function}} Query state and writers.
 */
export function useHashQuery( defaults ) {
	const hash = useHashRoute();
	const { path, query } = splitHashQuery( hash );
	const present = parseHashQuery( query );

	const params = {};
	Object.keys( defaults ).forEach( ( key ) => {
		params[ key ] =
			present[ key ] === undefined ? defaults[ key ] : present[ key ];
	} );

	const page = pageFrom( present.page );

	// Use a ref for current hash state and stable writers in effects and handlers.
	const stateRef = useRef();
	stateRef.current = { path, params, page, defaults };

	const write = useCallback( ( next, push ) => {
		const current = stateRef.current;
		const merged = { ...current.params, ...next };
		const wanted = { page: current.page, ...next };

		const out = {};
		if ( pageFrom( String( wanted.page ) ) > 1 ) {
			out.page = wanted.page;
		}

		Object.keys( current.defaults ).forEach( ( key ) => {
			if ( merged[ key ] !== current.defaults[ key ] ) {
				out[ key ] = merged[ key ];
			}
		} );

		const target = buildHash( current.path, out );

		// An identical hash fires no `hashchange`, so the announcement below
		// would never be consumed and would exempt the next navigation the
		// merchant made themselves.
		if ( target === window.location.hash ) {
			return;
		}

		if ( push ) {
			setHash( target );
			return;
		}

		replaceHashAnnounced( target );
	}, [] );

	return {
		page,
		params,

		/**
		 * Move to a page the merchant asked for, as its own history entry.
		 *
		 * @param {number} value The page to move to.
		 * @return {void}
		 */
		setPage: useCallback(
			( value ) => write( { page: value }, true ),
			[ write ]
		),

		/**
		 * Correct the current page without adding a history entry.
		 *
		 * @param {number} value The page to land on.
		 * @return {void}
		 */
		replacePage: useCallback(
			( value ) => write( { page: value }, false ),
			[ write ]
		),

		/**
		 * Change one filter, back to the first page.
		 *
		 * @param {string} name  The param name.
		 * @param {string} value The new value.
		 * @return {void}
		 */
		setFilter: useCallback(
			( name, value ) => write( { [ name ]: value, page: 1 }, false ),
			[ write ]
		),

		/**
		 * Change several filters together, back to the first page.
		 *
		 * @param {Object} next The params to change.
		 * @return {void}
		 */
		setFilters: useCallback(
			( next ) => write( { ...next, page: 1 }, false ),
			[ write ]
		),
	};
}

/**
 * Send a page that outran the list back to the last one that exists.
 *
 * Empty pages can come from stale links or concurrent changes. Move to the last
 * valid page and read it again.
 *
 * @param {Object}   query            The clamp inputs.
 * @param {number}   query.page       The current page.
 * @param {number}   query.totalPages The page count the read reported.
 * @param {boolean}  query.ready      Whether a read has actually answered.
 * @param {Function} query.onClamp    Called with the page to land on.
 * @return {void}
 */
export function clampPage( { page, totalPages, ready, onClamp } ) {
	if ( ! ready ) {
		return;
	}

	const last = Math.max( totalPages || 0, 1 );

	if ( page > last ) {
		onClamp( last );
	}
}
