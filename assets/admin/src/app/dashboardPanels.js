/**
 * Dashboard-panel registry.
 *
 * Extensions can register panels before or after the core bundle loads.
 */

/**
 * External dependencies
 */
import {
	createContext,
	useContext,
	useSyncExternalStore,
} from '@wordpress/element';

/**
 * Stable empty state for a Dashboard rendered without the app provider.
 *
 * @type {Array}
 */
const EMPTY_PANELS = [];

/**
 * Subscribe to an absent registry.
 *
 * @return {Function} A no-op unsubscribe function.
 */
function emptySubscribe() {
	return () => {};
}

/**
 * Read the stable empty registry snapshot.
 *
 * @return {Array} No panels.
 */
function emptySnapshot() {
	return EMPTY_PANELS;
}

/**
 * Whether a value is a usable dashboard-panel entry.
 *
 * Metric panels need a component. Link panels need a path and label.
 *
 * @param {*} entry The candidate entry.
 * @return {boolean} True when the entry is usable for its slot.
 */
function isValidPanel( entry ) {
	if (
		! entry ||
		typeof entry !== 'object' ||
		typeof entry.id !== 'string' ||
		entry.id === ''
	) {
		return false;
	}

	if ( entry.slot === 'link' ) {
		return (
			typeof entry.path === 'string' &&
			entry.path !== '' &&
			typeof entry.label === 'string' &&
			entry.label !== ''
		);
	}

	return Boolean( entry.component );
}

/**
 * Read extension panels registered before the core app booted.
 *
 * @param {Object} boot The normalized boot object.
 * @return {Array<Object>} Valid early panel entries.
 */
function readEarlyPanels( boot ) {
	const entries = boot && boot.admin ? boot.admin.dashboardPanels : undefined;
	return Array.isArray( entries ) ? entries.filter( isValidPanel ) : [];
}

/**
 * Create a live dashboard-panel registry bound to the boot namespace.
 *
 * @param {Object} boot The normalized boot object.
 * @return {{getSnapshot: Function, register: Function, subscribe: Function}} The registry API.
 */
export function createDashboardPanelRegistry( boot ) {
	let panels = [];
	const subscribers = new Set();

	readEarlyPanels( boot ).forEach( ( entry ) => {
		if ( ! panels.some( ( panel ) => panel.id === entry.id ) ) {
			panels.push( entry );
		}
	} );

	/**
	 * Register a panel and notify mounted subscribers.
	 *
	 * @param {Object} entry The panel entry.
	 * @return {void}
	 */
	const register = ( entry ) => {
		if (
			! isValidPanel( entry ) ||
			panels.some( ( panel ) => panel.id === entry.id )
		) {
			return;
		}

		panels = [ ...panels, entry ];
		subscribers.forEach( ( callback ) => callback() );
	};

	if ( boot && boot.admin ) {
		boot.admin.registerDashboardPanel = register;
	}

	return {
		/**
		 * Get the current store snapshot.
		 *
		 * @return {Array<Object>} The current panel entries.
		 */
		getSnapshot: () => panels,
		register,
		/**
		 * Subscribe to registry changes.
		 *
		 * @param {Function} callback Invoked after a panel is added.
		 * @return {Function} An unsubscribe function.
		 */
		subscribe: ( callback ) => {
			subscribers.add( callback );
			return () => subscribers.delete( callback );
		},
	};
}

/**
 * The app-level dashboard-panel registry.
 *
 * @type {Object}
 */
export const DashboardPanelContext = createContext( null );

/**
 * Keep only the panels the current user's capabilities allow.
 *
 * @param {Array<Object>} panels The registered panel entries.
 * @param {Object}        caps   The boot capability map.
 * @return {Array<Object>} Capability-allowed panels.
 */
export function filterPanels( panels, caps ) {
	return panels.filter(
		( entry ) =>
			! entry.capability || Boolean( caps && caps[ entry.capability ] )
	);
}

/**
 * Subscribe to the current registry and return allowed panels.
 *
 * @param {Object} caps The boot capability map.
 * @return {Array<Object>} Panels the current user may see.
 */
export function useDashboardPanels( caps ) {
	const registry = useContext( DashboardPanelContext );
	const panels = useSyncExternalStore(
		registry ? registry.subscribe : emptySubscribe,
		registry ? registry.getSnapshot : emptySnapshot,
		registry ? registry.getSnapshot : emptySnapshot
	);

	return filterPanels( panels, caps );
}
