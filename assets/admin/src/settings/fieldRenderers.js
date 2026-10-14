/**
 * Field-renderer registry for extensions. Boot data seeds early registrations.
 * Late changes notify subscribers. Built-in types and duplicates are rejected.
 */

/**
 * External dependencies
 */
import { createContext } from '@wordpress/element';

/**
 * Field types rendered by core.
 *
 * @type {Array<string>}
 */
export const BUILT_IN_FIELD_TYPES = [
	'secret',
	'toggle',
	'select',
	'page',
	'email',
	'text',
	'currency',
	'country',
	'color',
	'font_stack',
	// The Pro upsell teaser. Built-in so no extension bundle can register a
	// renderer for it and hijack the settings tab.
	'pro_upsell',
];

/**
 * Built-in types for fast rejection.
 *
 * @type {Set<string>}
 */
const BUILT_IN = new Set( BUILT_IN_FIELD_TYPES );

/**
 * Checks whether a value can be a React component.
 *
 * @param {*} component The candidate component.
 * @return {boolean} True when the value looks like a renderable component.
 */
function isValidComponent( component ) {
	return (
		typeof component === 'function' ||
		( !! component && typeof component === 'object' )
	);
}

/**
 * Checks whether a custom field type can register.
 *
 * @param {*} type The candidate type key.
 * @return {boolean} True when the type may carry a custom renderer.
 */
function isRegisterableType( type ) {
	return typeof type === 'string' && type !== '' && ! BUILT_IN.has( type );
}

/**
 * Reads valid early renderers from boot data.
 *
 * @param {Object} [boot] The normalized boot object.
 * @return {Object<string, *>} The valid early renderers (possibly empty).
 */
function readEarlyRenderers( boot ) {
	const pushed = boot && boot.admin ? boot.admin.fieldRenderers : undefined;
	// Use a null prototype so `constructor` cannot resolve as a renderer.
	if ( ! pushed || typeof pushed !== 'object' ) {
		return Object.create( null );
	}

	const out = Object.create( null );
	Object.keys( pushed ).forEach( ( type ) => {
		const component = pushed[ type ];
		if ( isRegisterableType( type ) && isValidComponent( component ) ) {
			out[ type ] = component;
		}
	} );
	return out;
}

/**
 * Creates a field-renderer registry for boot data.
 *
 * @param {Object} [boot] The normalized boot object.
 * @return {{getRenderer: Function, getSnapshot: Function, subscribe: Function, register: Function}}
 *         The registry API.
 */
export function createFieldRendererRegistry( boot ) {
	// Replace snapshots so useSyncExternalStore sees accepted changes.
	let renderers = readEarlyRenderers( boot );
	const subscribers = new Set();

	/**
	 * Notify every subscriber that the renderer map changed.
	 *
	 * @return {void}
	 */
	const notify = () => {
		subscribers.forEach( ( callback ) => callback() );
	};

	/**
	 * Registers a valid custom renderer. The first registration wins.
	 *
	 * @param {string}   type      The custom field type key.
	 * @param {Function} component The React component to render for that type.
	 * @return {void}
	 */
	const register = ( type, component ) => {
		if ( ! isRegisterableType( type ) || ! isValidComponent( component ) ) {
			return;
		}
		if ( type in renderers ) {
			return;
		}
		// Keep the null prototype in each new snapshot.
		renderers = Object.assign( Object.create( null ), renderers, {
			[ type ]: component,
		} );
		notify();
	};

	// Expose late registration for extension bundles.
	if ( boot && boot.admin ) {
		boot.admin.registerFieldRenderer = register;
	}

	return {
		/**
		 * Get the registered renderer for a field type, if any.
		 *
		 * @param {string} type The field type key.
		 * @return {(Function|undefined)} The renderer, or undefined when none is
		 *         registered.
		 */
		getRenderer: ( type ) => renderers[ type ],
		/**
		 * Gets the current renderer map for useSyncExternalStore.
		 *
		 * @return {Object<string, *>} The live renderer map.
		 */
		getSnapshot: () => renderers,
		register,
		/**
		 * Subscribe to renderer-map changes.
		 *
		 * @param {Function} callback Invoked after every accepted registration.
		 * @return {Function} An unsubscribe function.
		 */
		subscribe: ( callback ) => {
			subscribers.add( callback );
			return () => subscribers.delete( callback );
		},
	};
}

/**
 * Field-renderer registry context.
 *
 * @type {Object}
 */
export const FieldRendererContext = createContext(
	createFieldRendererRegistry()
);
