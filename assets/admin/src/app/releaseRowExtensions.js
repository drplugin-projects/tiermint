/**
 * Release row extension registry.
 *
 * Extensions can register a component before or after the core bundle loads.
 * The releases panel renders each one in every release row.
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
 * Stable empty state for a panel rendered without the app provider.
 *
 * @type {Array}
 */
const EMPTY_EXTENSIONS = [];

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
 * @return {Array} No extensions.
 */
function emptySnapshot() {
	return EMPTY_EXTENSIONS;
}

/**
 * Whether a value is a usable entry: a non-empty string id and a component.
 *
 * @param {*} entry The candidate entry.
 * @return {boolean} True when the entry is usable.
 */
export function isValidExtension( entry ) {
	return Boolean(
		entry &&
			typeof entry === 'object' &&
			typeof entry.id === 'string' &&
			entry.id !== '' &&
			entry.component
	);
}

/**
 * Read entries registered before the core app booted.
 *
 * @param {Object} boot The normalized boot object.
 * @return {Array<Object>} Valid early entries.
 */
function readEarlyExtensions( boot ) {
	const entries =
		boot && boot.admin ? boot.admin.releaseRowExtensions : undefined;
	return Array.isArray( entries ) ? entries.filter( isValidExtension ) : [];
}

/**
 * Create a live release row extension registry bound to the boot namespace.
 *
 * @param {Object} boot The normalized boot object.
 * @return {{getSnapshot: Function, register: Function, subscribe: Function}} The registry API.
 */
export function createReleaseRowExtensionRegistry( boot ) {
	let extensions = [];
	const subscribers = new Set();

	readEarlyExtensions( boot ).forEach( ( entry ) => {
		if ( ! extensions.some( ( existing ) => existing.id === entry.id ) ) {
			extensions.push( entry );
		}
	} );

	/**
	 * Register an extension and notify mounted subscribers.
	 *
	 * @param {Object} entry The extension entry.
	 * @return {void}
	 */
	const register = ( entry ) => {
		if (
			! isValidExtension( entry ) ||
			extensions.some( ( existing ) => existing.id === entry.id )
		) {
			return;
		}

		extensions = [ ...extensions, entry ];
		subscribers.forEach( ( callback ) => callback() );
	};

	if ( boot && boot.admin ) {
		boot.admin.registerReleaseRowExtension = register;
	}

	return {
		/**
		 * Get the current store snapshot.
		 *
		 * @return {Array<Object>} The current entries.
		 */
		getSnapshot: () => extensions,
		register,
		/**
		 * Subscribe to registry changes.
		 *
		 * @param {Function} callback Invoked after an entry is added.
		 * @return {Function} An unsubscribe function.
		 */
		subscribe: ( callback ) => {
			subscribers.add( callback );
			return () => subscribers.delete( callback );
		},
	};
}

/**
 * The app-level release row extension registry.
 *
 * @type {Object}
 */
export const ReleaseRowExtensionContext = createContext( null );

/**
 * Subscribe to the current registry and return its entries.
 *
 * @return {Array<Object>} The registered entries.
 */
export function useReleaseRowExtensions() {
	const registry = useContext( ReleaseRowExtensionContext );

	return useSyncExternalStore(
		registry ? registry.subscribe : emptySubscribe,
		registry ? registry.getSnapshot : emptySnapshot,
		registry ? registry.getSnapshot : emptySnapshot
	);
}
