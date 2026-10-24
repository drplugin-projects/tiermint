/**
 * Routes for core screens and registered extensions.
 *
 * Core paths cannot be replaced. Late registrations notify subscribers.
 */

/**
 * Internal dependencies
 */
import { splitHashQuery } from '../router/hashQuery';
import { route as dashboard } from '../screens/Dashboard';
import { route as products } from '../screens/Products';
import { route as productEdit } from '../screens/ProductEdit';
import { route as terms } from '../screens/Terms';
import { route as orders } from '../screens/Orders';
import { route as orderDetail } from '../screens/OrderDetail';
import { route as customers } from '../screens/Customers';
import { route as licenses } from '../screens/Licenses';
import { route as licenseDetail } from '../screens/LicenseDetail';
import { route as discounts } from '../screens/Discounts';
import { route as reports } from '../screens/Reports';
import {
	route as settings,
	tabRoute as settingsTab,
	subtabRoute as settingsSubtab,
} from '../screens/Settings';
import { route as upgrade } from '../screens/Upgrade';

/**
 * Built-in routes in navigation order.
 *
 * @type {Array<{path: string, title: string, capability: string, component: Function, hideWhenPro?: boolean}>}
 */
export const coreRoutes = [
	dashboard,
	products,
	terms,
	productEdit,
	orders,
	orderDetail,
	customers,
	licenses,
	licenseDetail,
	discounts,
	reports,
	settings,
	settingsTab,
	settingsSubtab,
	upgrade,
];

/**
 * Whether a value looks like a usable route entry.
 *
 * @param {*} entry The candidate entry.
 * @return {boolean} True when the entry has a string path and a component.
 */
function isValidRoute( entry ) {
	return Boolean(
		entry &&
			typeof entry === 'object' &&
			typeof entry.path === 'string' &&
			entry.component
	);
}

/**
 * Read the externally pushed routes from a boot object.
 *
 * @param {Object} [boot] The normalized boot object.
 * @return {Array} The valid external route entries (possibly empty).
 */
function readExternalRoutes( boot ) {
	const pushed = boot && boot.admin ? boot.admin.routes : undefined;
	if ( ! Array.isArray( pushed ) ) {
		return [];
	}
	return pushed.filter( isValidRoute );
}

/**
 * Merge core routes with external routes that use free paths.
 *
 * @param {Object} [boot] The normalized boot object.
 * @return {Array} The merged route list.
 */
export function buildRoutes( boot ) {
	// Drop core routes flagged to hide while Pro is active (the #/pro teaser),
	// mirroring the PHP menu gating. Build the path set from the kept routes so a
	// dropped path can be reclaimed by an external route, not shadowed by it.
	const proActive = Boolean( boot && boot.pro );
	const cores = coreRoutes.filter(
		( route ) => ! ( proActive && route.hideWhenPro )
	);
	const corePaths = new Set( cores.map( ( route ) => route.path ) );
	const merged = [ ...cores ];

	readExternalRoutes( boot ).forEach( ( entry ) => {
		if ( ! corePaths.has( entry.path ) ) {
			merged.push( entry );
		}
	} );

	return merged;
}

/**
 * Whether the current user's capability map grants a capability.
 *
 * An entry with no capability is always granted; a missing map denies.
 *
 * @param {Object} caps       The `caps` map from the boot object.
 * @param {string} capability The required capability key.
 * @return {boolean} True when access is granted.
 */
export function hasCapability( caps, capability ) {
	if ( ! capability ) {
		return true;
	}
	return Boolean( caps && caps[ capability ] );
}

/**
 * Filter routes down to the ones the current user may access.
 *
 * @param {Array}  routes The route entries to filter.
 * @param {Object} caps   The `caps` map from the boot object.
 * @return {Array} The routes the user has the capability for.
 */
export function filterByCapability( routes, caps ) {
	return routes.filter( ( route ) =>
		hasCapability( caps, route.capability )
	);
}

/**
 * Get accessible routes without parameter segments.
 *
 * @param {Array}  routes The route entries to filter.
 * @param {Object} caps   The `caps` map from the boot object.
 * @return {Array} The routes to show in the nav.
 */
export function getNavRoutes( routes, caps ) {
	return filterByCapability( routes, caps ).filter(
		( route ) => ! route.path.includes( ':' )
	);
}

/**
 * Redirect denied root visits to the first allowed navigation route.
 *
 * A denied deep link still shows the access-denied panel.
 *
 * @param {Array}  routes The full route list.
 * @param {Object} caps   The `caps` map from the boot object.
 * @param {string} hash   The current hash.
 * @return {string|null} The path to redirect to, or null to render as matched.
 */
export function resolveLandingRedirect( routes, caps, hash ) {
	// Match the root path without its query.
	if ( splitHashQuery( hash ).path !== '#/' ) {
		return null;
	}

	const root = routes.find( ( route ) => route.path === '#/' );

	// Let the router show not-found when no root exists.
	if ( ! root ) {
		return null;
	}

	if ( hasCapability( caps, root.capability ) ) {
		return null;
	}

	// Keep the access-denied panel when no route is allowed.
	const allowed = getNavRoutes( routes, caps );

	return allowed.length > 0 ? allowed[ 0 ].path : null;
}

/**
 * Create a route registry for the boot object.
 *
 * @param {Object} boot The normalized boot object.
 * @return {{getRoutes: Function, register: Function, subscribe: Function}} The
 *         registry API.
 */
export function createRouteRegistry( boot ) {
	let routes = buildRoutes( boot );
	const subscribers = new Set();

	/**
	 * Notify every subscriber that the route list changed.
	 *
	 * @return {void}
	 */
	const notify = () => {
		subscribers.forEach( ( callback ) => callback() );
	};

	/**
	 * Register a route entry after boot, ignoring invalid or duplicate paths.
	 *
	 * @param {Object} entry The route entry to add.
	 * @return {void}
	 */
	const register = ( entry ) => {
		if ( ! isValidRoute( entry ) ) {
			return;
		}
		if ( routes.some( ( route ) => route.path === entry.path ) ) {
			return;
		}
		routes = [ ...routes, entry ];

		// Mirror into the boot namespace. The App renders from this registry, but
		// the document-title sync reads buildRoutes( boot ), so a route registered
		// after core booted (an extension bundle loading late) must land in the boot
		// routes too or its screen would render with the wrong tab title.
		if ( boot && boot.admin ) {
			if ( ! Array.isArray( boot.admin.routes ) ) {
				boot.admin.routes = [];
			}
			boot.admin.routes.push( entry );
		}

		notify();
	};

	// Let late bundles use the boot namespace.
	if ( boot && boot.admin ) {
		boot.admin.registerRoute = register;
	}

	return {
		/**
		 * Get the current route list.
		 *
		 * @return {Array} The live route list.
		 */
		getRoutes: () => routes,
		register,
		/**
		 * Subscribe to route-list changes.
		 *
		 * @param {Function} callback Invoked after every change.
		 * @return {Function} An unsubscribe function.
		 */
		subscribe: ( callback ) => {
			subscribers.add( callback );
			return () => subscribers.delete( callback );
		},
	};
}
