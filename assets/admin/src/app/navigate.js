/**
 * The app's own navigations.
 *
 * Landing redirects and cancelled navigations replace history entries. This seam
 * also makes the read-only jsdom Location API testable.
 */

/**
 * Where the app has just sent itself, for the guard to recognize.
 *
 * @type {string|null}
 */
let expectedHash = null;

/**
 * Replace the current history entry with a hash path.
 *
 * @param {string} path A hash path, leading `#` included (`#/reports`).
 * @return {void}
 */
export function replaceHash( path ) {
	window.location.replace( path );
}

/**
 * Navigate to a hash path as a new history entry, not a replacement, announcing it first.
 *
 * Announce a user-selected navigation so the guard ignores a just-saved form.
 *
 * @param {string} path A hash path, leading `#` included.
 * @return {void}
 */
export function setHash( path ) {
	expectedHash = path;
	window.location.hash = path;
}

/**
 * Replaces the current history entry with a hash path.
 *
 * Use this for filters and tabs. Existing hashes do not emit hashchange.
 *
 * @param {string} path A hash path, leading `#` included.
 * @return {void}
 */
export function replaceHashAnnounced( path ) {
	if ( typeof window !== 'undefined' && window.location.hash === path ) {
		return;
	}
	expectedHash = path;
	window.location.replace( path );
}

/**
 * Take the destination the app announced, clearing it.
 *
 * Clear the announcement on each hash change.
 *
 * @return {string|null} The announced path, or null.
 */
export function takeExpectedHash() {
	const path = expectedHash;
	expectedHash = null;

	return path;
}
