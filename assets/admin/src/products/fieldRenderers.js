/**
 * Registry for extension product-field renderers.
 *
 * Extensions can add custom field types but cannot replace built-in types.
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
export const BUILT_IN_PRODUCT_FIELD_TYPES = [
	'text',
	'textarea',
	'rich_text',
	'media',
	'media_gallery',
];

/**
 * The built-in types as a set, for O(1) rejection checks.
 *
 * @type {Set<string>}
 */
const BUILT_IN = new Set( BUILT_IN_PRODUCT_FIELD_TYPES );

/**
 * Check whether a value can render as a React component.
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
 * Check whether a custom field type can be registered.
 *
 * @param {*} type The candidate type key.
 * @return {boolean} True when the type may carry a custom renderer.
 */
function isRegisterableType( type ) {
	return typeof type === 'string' && type !== '' && ! BUILT_IN.has( type );
}

/**
 * Read valid early renderer registrations from boot data.
 *
 * @param {Object} [boot] The normalized boot object.
 * @return {Object<string, *>} The valid early renderers (possibly empty).
 */
function readEarlyRenderers( boot ) {
	const pushed =
		boot && boot.admin ? boot.admin.productFieldRenderers : undefined;
	// Use a null-prototype map so field names cannot resolve Object.prototype.
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
 * Create a live product field-renderer registry.
 *
 * @param {Object} [boot] The normalized boot object.
 * @return {{getRenderer: Function, getSnapshot: Function, subscribe: Function, register: Function}}
 *         The registry API.
 */
export function createProductFieldRendererRegistry( boot ) {
	// Replace the snapshot only after an accepted registration.
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
	 * Register a renderer for a custom field type.
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
		// Preserve the null prototype in each new snapshot.
		renderers = Object.assign( Object.create( null ), renderers, {
			[ type ]: component,
		} );
		notify();
	};

	// Expose late registration on the server-provided namespace.
	if ( boot && boot.admin ) {
		boot.admin.registerProductFieldRenderer = register;
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
		 * Get the current renderer map.
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
 * Context for the active product field-renderer registry.
 *
 * @type {Object}
 */
export const ProductFieldRendererContext = createContext(
	createProductFieldRendererRegistry()
);
