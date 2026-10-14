/**
 * Order-detail action registry.
 *
 * Extensions can register actions before or after the core bundle loads. Each
 * action receives the loaded order.
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
 * Stable empty state for a screen rendered without the app provider.
 *
 * @type {Array}
 */
const EMPTY_ACTIONS = [];

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
 * @return {Array} No actions.
 */
function emptySnapshot() {
	return EMPTY_ACTIONS;
}

/**
 * Whether a value is a usable order-detail action entry: a non-empty string id
 * and a component to render.
 *
 * @param {*} entry The candidate entry.
 * @return {boolean} True when the entry is usable.
 */
export function isValidAction( entry ) {
	return Boolean(
		entry &&
			typeof entry === 'object' &&
			typeof entry.id === 'string' &&
			entry.id !== '' &&
			entry.component
	);
}

/**
 * Read extension actions registered before the core app booted.
 *
 * @param {Object} boot The normalized boot object.
 * @return {Array<Object>} Valid early action entries.
 */
function readEarlyActions( boot ) {
	const entries =
		boot && boot.admin ? boot.admin.orderDetailActions : undefined;
	return Array.isArray( entries ) ? entries.filter( isValidAction ) : [];
}

/**
 * Create a live order-detail action registry bound to the boot namespace.
 *
 * @param {Object} boot The normalized boot object.
 * @return {{getSnapshot: Function, register: Function, subscribe: Function}} The registry API.
 */
export function createOrderDetailActionRegistry( boot ) {
	let actions = [];
	const subscribers = new Set();

	readEarlyActions( boot ).forEach( ( entry ) => {
		if ( ! actions.some( ( action ) => action.id === entry.id ) ) {
			actions.push( entry );
		}
	} );

	/**
	 * Register an action and notify mounted subscribers.
	 *
	 * @param {Object} entry The action entry.
	 * @return {void}
	 */
	const register = ( entry ) => {
		if (
			! isValidAction( entry ) ||
			actions.some( ( action ) => action.id === entry.id )
		) {
			return;
		}

		actions = [ ...actions, entry ];
		subscribers.forEach( ( callback ) => callback() );
	};

	if ( boot && boot.admin ) {
		boot.admin.registerOrderDetailAction = register;
	}

	return {
		/**
		 * Get the current store snapshot.
		 *
		 * @return {Array<Object>} The current action entries.
		 */
		getSnapshot: () => actions,
		register,
		/**
		 * Subscribe to registry changes.
		 *
		 * @param {Function} callback Invoked after an action is added.
		 * @return {Function} An unsubscribe function.
		 */
		subscribe: ( callback ) => {
			subscribers.add( callback );
			return () => subscribers.delete( callback );
		},
	};
}

/**
 * The app-level order-detail action registry.
 *
 * @type {Object}
 */
export const OrderDetailActionContext = createContext( null );

/**
 * Keep only the actions the current user's capabilities allow.
 *
 * @param {Array<Object>} actions The registered action entries.
 * @param {Object}        caps    The boot capability map.
 * @return {Array<Object>} Capability-allowed actions.
 */
export function filterActions( actions, caps ) {
	return actions.filter(
		( entry ) =>
			! entry.capability || Boolean( caps && caps[ entry.capability ] )
	);
}

/**
 * Subscribe to the current registry and return allowed actions.
 *
 * @param {Object} caps The boot capability map.
 * @return {Array<Object>} Actions the current user may see.
 */
export function useOrderDetailActions( caps ) {
	const registry = useContext( OrderDetailActionContext );
	const actions = useSyncExternalStore(
		registry ? registry.subscribe : emptySubscribe,
		registry ? registry.getSnapshot : emptySnapshot,
		registry ? registry.getSnapshot : emptySnapshot
	);

	return filterActions( actions, caps );
}
