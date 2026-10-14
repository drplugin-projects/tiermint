/**
 * Guards unsaved edits during SPA and page navigation.
 *
 * Hash navigation restores the prior path on cancel. `beforeunload` handles
 * navigation away from wp-admin.
 */

/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { dirtyState } from './dirtyState';
import { replaceHash, takeExpectedHash } from './navigate';
import { splitHashQuery } from '../router/hashQuery';

/**
 * The prompt shown when leaving a screen would discard edits.
 *
 * @return {string} The translated message.
 */
export function unsavedChangesMessage() {
	return __(
		'You have unsaved changes on this screen. Leave without saving?',
		'tiermint'
	);
}

/**
 * The screen a hash names, with its page and filters left off.
 *
 * @param {string} [hash] A raw hash.
 * @return {string} The normalized path half.
 */
function screenOf( hash ) {
	return splitHashQuery( hash ).path;
}

/**
 * Start guarding navigation away from unsaved edits.
 *
 * @param {Object} [store] The dirty-state store; injectable for tests.
 * @return {Function} A teardown that removes every listener it attached.
 */
export function startUnsavedChangesGuard( store = dirtyState ) {
	let previous = window.location.hash;

	/**
	 * Prompt on a hash change that would discard edits, and undo it on cancel.
	 *
	 * @return {void}
	 */
	const onHashChange = () => {
		const current = window.location.hash;

		// Always consumed, so an announcement can never linger and exempt a
		// later navigation the user made themselves.
		const announced = takeExpectedHash();

		// A navigation the app performed on the user's behalf, right after
		// their edits were saved. Nothing to warn about.
		if ( announced !== null && announced === current ) {
			previous = current;
			return;
		}

		/* Ignore restores, query changes and late events on the current screen. */
		if ( screenOf( current ) === screenOf( previous ) ) {
			previous = current;
			return;
		}

		// eslint-disable-next-line no-alert
		if ( ! store.isDirty() || window.confirm( unsavedChangesMessage() ) ) {
			previous = current;
			return;
		}

		replaceHash( previous || '#/' );
	};

	/**
	 * Let the browser show its own leave-site dialog while edits are pending.
	 *
	 * @param {Event} event The beforeunload event.
	 * @return {void}
	 */
	const onBeforeUnload = ( event ) => {
		if ( ! store.isDirty() ) {
			return;
		}

		event.preventDefault();
		// Still required by Safari and by older Chrome; harmless elsewhere.
		event.returnValue = '';
	};

	let unloadAttached = false;

	/**
	 * Attach `beforeunload` only when needed. A permanent listener can disable
	 * the back-forward cache.
	 *
	 * @return {void}
	 */
	const syncUnloadListener = () => {
		const wanted = store.hasRegistrants();

		if ( wanted === unloadAttached ) {
			return;
		}

		if ( wanted ) {
			window.addEventListener( 'beforeunload', onBeforeUnload );
		} else {
			window.removeEventListener( 'beforeunload', onBeforeUnload );
		}

		unloadAttached = wanted;
	};

	window.addEventListener( 'hashchange', onHashChange );
	const unsubscribe = store.subscribe( syncUnloadListener );
	syncUnloadListener();

	return () => {
		window.removeEventListener( 'hashchange', onHashChange );
		unsubscribe();
		if ( unloadAttached ) {
			window.removeEventListener( 'beforeunload', onBeforeUnload );
			unloadAttached = false;
		}
	};
}
