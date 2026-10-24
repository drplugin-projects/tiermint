/**
 * Admin SPA entry point.
 *
 * Mount only when the admin root is present.
 */

/**
 * External dependencies
 */
import { createRoot } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { readBootData } from './bootData';
import { configureApi } from './api/client';
import { startMenuSync } from './app/menuSync';
import { startDocumentTitle } from './app/documentTitle';
import { buildRoutes } from './routes/registry';
import { dirtyState } from './app/dirtyState';
import { startUnsavedChangesGuard } from './app/unsavedChangesGuard';
import App from './app/App';
import './admin.css';

/**
 * The id of the DOM node the SPA mounts onto.
 *
 * @type {string}
 */
export const ROOT_ID = 'tiermint-admin-root';

/**
 * Mount the app onto a container with the given boot data.
 *
 * @param {Element} container The DOM node to render into.
 * @param {Object}  bootData  The normalized boot object.
 * @return {Object} The React root, so callers (and tests) can unmount.
 */
export function mount( container, bootData ) {
	const root = createRoot( container );
	root.render( <App boot={ bootData } /> );
	return root;
}

/**
 * Teardown from the previous boot.
 *
 * @type {Function|null}
 */
let previousBootTeardown = null;

/**
 * Boot the SPA when its mount node is present.
 *
 * @return {Object|null} The React root, or null when there is nothing to mount.
 */
export function boot() {
	const container = document.getElementById( ROOT_ID );
	if ( ! container ) {
		return null;
	}

	if ( previousBootTeardown ) {
		previousBootTeardown();
	}

	const bootData = readBootData();
	configureApi( bootData );

	// Start before mount so the sidebar is correct on first paint.
	const stopMenuSync = startMenuSync();

	// Read routes fresh each time so routes registered late are covered.
	const stopDocumentTitle = startDocumentTitle( () =>
		buildRoutes( bootData )
	);

	/* Start before mount so the guard receives hash changes first. */
	const stopGuard = startUnsavedChangesGuard();

	previousBootTeardown = () => {
		stopMenuSync();
		stopDocumentTitle();
		stopGuard();
	};

	/* Let extension panels register their own dirty-state checks. */
	if ( bootData.admin ) {
		bootData.admin.registerUnsavedChanges = dirtyState.register;
	}

	return mount( container, bootData );
}

// Self-boot when loaded in a browser with the mount node present. The guard
// keeps importing this module (in tests, or on admin pages without the node)
// side-effect free.
if ( typeof document !== 'undefined' && document.getElementById( ROOT_ID ) ) {
	boot();
}
