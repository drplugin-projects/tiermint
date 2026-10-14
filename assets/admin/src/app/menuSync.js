/**
 * Synchronises the wp-admin submenu with the SPA route.
 *
 * WordPress cannot read hashes. Change only active menu classes.
 */

/**
 * Internal dependencies
 */
import { findActivePath } from './activeRoute';

/**
 * A page's identity for menu purposes: its admin file plus its `page` query var.
 *
 * Compare the parsed page value to avoid matching another plugin's URL.
 *
 * @param {string} href A submenu href, absolute or admin-relative.
 * @return {string|null} The comparable key, or null when the href will not parse.
 */
function pageKey( href ) {
	try {
		const url = new URL( href, window.location.href );
		return (
			url.pathname + '?page=' + ( url.searchParams.get( 'page' ) ?? '' )
		);
	} catch {
		return null;
	}
}

/**
 * The hash a submenu href points at, or the empty string when it has none.
 *
 * The dashboard has no hash but still owns the root route.
 *
 * @param {string} href A submenu href.
 * @return {string} The hash, leading `#` included, or ''.
 */
function hashOf( href ) {
	const index = href.indexOf( '#' );

	return index === -1 ? '' : href.slice( index );
}

/**
 * Add or remove the `current` marking on one submenu anchor and its list item.
 *
 * @param {Element} link     The submenu anchor.
 * @param {boolean} isActive Whether this entry owns the current hash.
 * @return {void}
 */
function mark( link, isActive ) {
	const item = link.closest( 'li' );

	link.classList.toggle( 'current', isActive );
	if ( item ) {
		item.classList.toggle( 'current', isActive );
	}

	if ( isActive ) {
		link.setAttribute( 'aria-current', 'page' );
	} else {
		link.removeAttribute( 'aria-current' );
	}
}

/**
 * Move the sidebar's current marker onto the entry owning the current hash.
 *
 * Do nothing outside wp-admin chrome or when this page has no submenu entry.
 *
 * @return {void}
 */
export function syncAdminMenu() {
	const menu = document.getElementById( 'adminmenu' );
	if ( ! menu ) {
		return;
	}

	const here = pageKey( window.location.href );
	const entries = Array.from( menu.querySelectorAll( '.wp-submenu a[href]' ) )
		.map( ( link ) => ( {
			link,
			href: link.getAttribute( 'href' ) || '',
		} ) )
		.filter( ( entry ) => pageKey( entry.href ) === here );

	// An empty entry list is safe because the loop below is a no-op.
	const active = findActivePath(
		entries.map( ( entry ) => hashOf( entry.href ) ),
		window.location.hash
	);

	// Keep the first duplicate, matching the SPA navigation.
	let claimed = false;
	entries.forEach( ( entry ) => {
		const isActive =
			! claimed && active !== null && hashOf( entry.href ) === active;
		claimed = claimed || isActive;
		mark( entry.link, isActive );
	} );
}

/**
 * Start syncing the sidebar: once now, then on every hash change.
 *
 * @return {Function} A teardown that stops listening.
 */
export function startMenuSync() {
	const handle = () => syncAdminMenu();

	handle();
	window.addEventListener( 'hashchange', handle );

	return () => window.removeEventListener( 'hashchange', handle );
}
