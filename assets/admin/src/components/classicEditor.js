/**
 * Adapts the WordPress classic editor for the rich-text control.
 *
 * This keeps WordPress globals out of the SPA and lets tests mock the editor.
 */

/**
 * Resolve the classic editor API object, or null when it is not loaded.
 *
 * Prefer `wp.oldEditor` when the block editor owns `wp.editor`.
 *
 * @return {?Object} The classic editor object, or null.
 */
function classicEditor() {
	const wp = typeof window !== 'undefined' ? window.wp : undefined;
	if ( ! wp ) {
		return null;
	}
	return wp.oldEditor || wp.editor || null;
}

/**
 * Core default settings mark the classic editor as ready.
 *
 * @return {boolean} True once the API is usable.
 */
function apiReady() {
	const editor = classicEditor();
	return !! editor && typeof editor.getDefaultSettings === 'function';
}

/**
 * A promise that resolves when the classic editor is ready to initialize.
 *
 * Wait for footer scripts. If the API is missing, let the control use its
 * textarea fallback.
 *
 * @return {Promise<void>} Resolves when it is safe to initialize (or to give up).
 */
export function editorReady() {
	return new Promise( ( resolve ) => {
		if ( apiReady() ) {
			resolve();
			return;
		}

		if (
			typeof document === 'undefined' ||
			document.readyState !== 'loading'
		) {
			resolve();
			return;
		}

		const onReady = () => {
			document.removeEventListener( 'DOMContentLoaded', onReady );
			resolve();
		};
		document.addEventListener( 'DOMContentLoaded', onReady );
	} );
}

/**
 * Initialize the classic editor on a textarea by id.
 *
 * @param {string} id       The textarea element id.
 * @param {Object} settings The `{ tinymce, quicktags }` init settings.
 * @return {void}
 */
export function initializeEditor( id, settings ) {
	const editor = classicEditor();
	if ( editor && typeof editor.initialize === 'function' ) {
		editor.initialize( id, settings );
	}
}

/**
 * Read content from either classic-editor tab.
 *
 * @param {string} id The textarea element id.
 * @return {?string} The content, or null when the classic API is absent (so the
 *         caller can tell "no editor" from an empty one and read the textarea).
 */
export function getEditorContent( id ) {
	const editor = classicEditor();
	if ( editor && typeof editor.getContent === 'function' ) {
		return editor.getContent( id );
	}
	return null;
}

/**
 * Remove editor instances so the id can be reused.
 *
 * @param {string} id The textarea element id.
 * @return {void}
 */
export function removeEditor( id ) {
	const editor = classicEditor();
	if ( editor && typeof editor.remove === 'function' ) {
		editor.remove( id );
	}
}

/**
 * Set content on a live TinyMCE instance. The caller resets the textarea when
 * there is no instance.
 *
 * @param {string} id   The textarea element id.
 * @param {string} html The HTML to load into the editor.
 * @return {void}
 */
export function setEditorContent( id, html ) {
	const tinymce = typeof window !== 'undefined' ? window.tinymce : undefined;
	const instance =
		tinymce && typeof tinymce.get === 'function' ? tinymce.get( id ) : null;
	if ( instance && typeof instance.setContent === 'function' ) {
		instance.setContent( html );
	}
}
