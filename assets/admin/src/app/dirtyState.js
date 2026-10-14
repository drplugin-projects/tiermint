/**
 * Who currently holds unsaved edits.
 *
 * Tracks unsaved edits for the navigation guard. Extensions register predicates;
 * core screens register only while dirty.
 */

/**
 * External dependencies
 */
import { useEffect } from '@wordpress/element';

/**
 * Build an independent dirty-state store.
 *
 * A factory keeps test stores isolated from the shared singleton.
 *
 * @return {{register: Function, isDirty: Function, hasRegistrants: Function, subscribe: Function}}
 *         The store API.
 */
export function createDirtyState() {
	// Entries are wrapper objects, not the predicates themselves: a Set of
	// functions would collapse two screens that happened to register the same
	// reference, and one unregister would then silently free both.
	const registrants = new Set();
	const subscribers = new Set();

	/**
	 * Tell every subscriber the registrant set changed.
	 *
	 * @return {void}
	 */
	const notify = () => {
		subscribers.forEach( ( listener ) => listener() );
	};

	return {
		/**
		 * Registers an unsaved-change check.
		 *
		 * @param {Function} [isDirtyFn] Checks whether edits remain.
		 * @return {Function} Idempotent unregister function.
		 */
		register( isDirtyFn ) {
			const entry = {
				isDirty:
					typeof isDirtyFn === 'function' ? isDirtyFn : () => true,
			};

			registrants.add( entry );
			notify();

			return () => {
				if ( registrants.delete( entry ) ) {
					notify();
				}
			};
		},

		/**
		 * Whether anything on screen would lose edits if the user left now.
		 *
		 * @return {boolean} True when at least one registrant says so.
		 */
		isDirty() {
			for ( const entry of registrants ) {
				if ( entry.isDirty() ) {
					return true;
				}
			}

			return false;
		},

		/**
		 * Whether anything is registered at all, dirty or not.
		 *
		 * @return {boolean} True when the set is non-empty.
		 */
		hasRegistrants() {
			return registrants.size > 0;
		},

		/**
		 * Watch for registrations and unregistrations.
		 *
		 * @param {Function} listener Called after every change.
		 * @return {Function} An unsubscribe function.
		 */
		subscribe( listener ) {
			subscribers.add( listener );

			return () => subscribers.delete( listener );
		},
	};
}

/**
 * The store the app and its extensions share.
 *
 * @type {{register: Function, isDirty: Function, hasRegistrants: Function, subscribe: Function}}
 */
export const dirtyState = createDirtyState();

/**
 * Declare this screen's unsaved edits for as long as `dirty` stays true.
 *
 * Register only while dirty so unmounted screens cannot block later navigation.
 *
 * @param {boolean} dirty   Whether this screen currently holds unsaved edits.
 * @param {Object}  [store] The store to register with; injectable for tests.
 * @return {void}
 */
export function useUnsavedChanges( dirty, store = dirtyState ) {
	useEffect( () => {
		if ( ! dirty ) {
			return undefined;
		}

		return store.register();
	}, [ dirty, store ] );
}
