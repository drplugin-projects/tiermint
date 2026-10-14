/**
 * Keeps the browser tab and history title in step with the SPA route.
 *
 * WordPress renders the page title once, so every SPA screen shows the menu
 * page's name. Swap only the leading segment for the active route's title.
 */

/**
 * Internal dependencies
 */
import { findActivePath } from './activeRoute';

/**
 * The separator wp-admin puts between the screen name and the site name.
 *
 * `admin-header.php` prints the screen name, a left angle quote (U+2039), then
 * the site name. Only the text before the first separator is ours to replace.
 *
 * @type {string}
 */
const SEPARATOR = ' ‹ ';

/**
 * The active route's title for the current hash, or null when none owns it.
 *
 * @param {Array<{path: string, title: string}>} routes The route list.
 * @param {string}                               hash   The current hash.
 * @return {string|null} The route title, or null.
 */
function routeTitle( routes, hash ) {
	const active = findActivePath(
		routes.map( ( route ) => route.path ),
		hash
	);
	if ( active === null ) {
		return null;
	}

	const route = routes.find( ( entry ) => entry.path === active );
	if ( ! route || typeof route.title !== 'string' || route.title === '' ) {
		return null;
	}

	return route.title;
}

/**
 * Set the document title from the current hash, keeping the server-rendered tail.
 *
 * An unknown hash, or a matched route with no title, restores the original.
 *
 * @param {Array<{path: string, title: string}>} routes   The route list.
 * @param {string}                               original The title the server printed.
 * @return {void}
 */
export function applyDocumentTitle( routes, original ) {
	const title = routeTitle( routes, window.location.hash );
	if ( title === null ) {
		document.title = original;
		return;
	}

	const index = original.indexOf( SEPARATOR );
	const tail = index === -1 ? '' : original.slice( index );

	document.title = title + tail;
}

/**
 * Start syncing the title: once now, then on every hash change.
 *
 * The server title is captured once so repeated syncs never compound, and the
 * route list is read fresh each time so a late Pro registration is picked up.
 *
 * @param {Function} getRoutes Returns the current route list.
 * @return {Function} A teardown that stops listening.
 */
export function startDocumentTitle( getRoutes ) {
	const original = document.title;
	const handle = () => applyDocumentTitle( getRoutes(), original );

	handle();
	window.addEventListener( 'hashchange', handle );

	return () => window.removeEventListener( 'hashchange', handle );
}
