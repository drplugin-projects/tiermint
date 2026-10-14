/**
 * Classic-editor control for product copy.
 *
 * Sanitize every value. Visual edits debounce, while Text-tab edits emit on
 * blur to avoid cleaning incomplete HTML.
 */

/**
 * External dependencies
 */
import { useRef, useEffect } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { sanitizeRichHtml } from './sanitizeRichHtml';
import {
	editorReady,
	initializeEditor,
	getEditorContent,
	removeEditor,
	setEditorContent,
} from './classicEditor';

/**
 * Wait after a Visual-tab edit before emitting.
 *
 * @type {number}
 */
const EMIT_DEBOUNCE_MS = 250;

/**
 * Visual toolbar formats that the sanitizer keeps.
 *
 * @type {string}
 */
const TINYMCE_TOOLBAR =
	'formatselect,bold,italic,bullist,numlist,link,unlink,undo,redo';

/**
 * Paragraph and heading formats available from the toolbar.
 *
 * @type {string}
 */
const TINYMCE_BLOCK_FORMATS =
	'Paragraph=p;Heading 2=h2;Heading 3=h3;Heading 4=h4';

/**
 * Elements kept in the Visual view, matching the sanitizer grammar.
 *
 * @type {string}
 */
const TINYMCE_VALID_ELEMENTS =
	'p,br,strong/b,em/i,a[href],ul,ol,li,h1,h2,h3,h4,h5,h6';

/**
 * The Text-tab (Quicktags) buttons: the same grammar, plus close-tag.
 *
 * @type {string}
 */
const QUICKTAGS_BUTTONS = 'strong,em,link,ul,ol,li,close';

/**
 * Convert a field id to a safe editor-element id.
 *
 * @param {string} id The field id.
 * @return {string} A `[A-Za-z0-9_-]` token.
 */
function slug( id ) {
	return String( id ).replace( /[^A-Za-z0-9_-]/g, '-' );
}

/**
 * Render the rich text control.
 *
 * @param {Object}                  props          Component props.
 * @param {Object}                  props.field    The field definition (id, label, help).
 * @param {string}                  props.value    The current HTML value.
 * @param {(value: string) => void} props.onChange Called with the sanitized HTML on every edit.
 * @return {Element} The control element.
 */
export default function RichTextField( { field, value, onChange } ) {
	const domId = 'tiermint-rte-' + slug( field.id );
	const textareaRef = useRef( null );
	// The value we last handed to onChange (or adopted as the baseline on open).
	// Seeded in the mount effect; the editor is only re-seeded, and onChange only
	// fired, when content differs from this, which is what keeps typing from
	// resetting the caret and keeps merely opening a product from marking it dirty.
	const lastEmitted = useRef( null );
	const timerRef = useRef( null );
	// True while we push a new external value into the editor, so the SetContent
	// it fires does not echo back as an edit.
	const seedingRef = useRef( false );
	// onChange is a fresh closure each render; read it through a ref so the
	// mount-once effect never calls a stale one.
	const onChangeRef = useRef( onChange );
	onChangeRef.current = onChange;
	// The value at first render, for the initial emit baseline.
	const initialValueRef = useRef( typeof value === 'string' ? value : '' );

	/**
	 * Read editor content, or the textarea when the API is unavailable.
	 *
	 * @return {string} The current HTML.
	 */
	const readContent = () => {
		const content = getEditorContent( domId );
		if ( content === null ) {
			return textareaRef.current ? textareaRef.current.value : '';
		}
		return content;
	};

	/**
	 * Emit changed content after sanitizing it.
	 *
	 * @return {void}
	 */
	const emit = () => {
		if ( seedingRef.current ) {
			return;
		}
		const clean = sanitizeRichHtml( readContent() );
		if ( clean === lastEmitted.current ) {
			return;
		}
		lastEmitted.current = clean;
		onChangeRef.current( clean );
	};

	/**
	 * Schedule a debounced emit (the Visual-tab cadence).
	 *
	 * @return {void}
	 */
	const scheduleEmit = () => {
		if ( seedingRef.current ) {
			return;
		}
		if ( timerRef.current ) {
			clearTimeout( timerRef.current );
		}
		timerRef.current = setTimeout( () => {
			timerRef.current = null;
			emit();
		}, EMIT_DEBOUNCE_MS );
	};

	/**
	 * Emit at once on blur so Save receives the latest edit.
	 *
	 * @return {void}
	 */
	const flushEmit = () => {
		if ( timerRef.current ) {
			clearTimeout( timerRef.current );
			timerRef.current = null;
		}
		emit();
	};

	useEffect( () => {
		lastEmitted.current = initialValueRef.current;

		// The Text tab is a plain textarea; a native blur listener covers it. No
		// input listener: sanitizing raw HTML per keystroke would eat a half-typed
		// tag under the cursor.
		const textarea = textareaRef.current;
		const onTextareaBlur = () => flushEmit();
		if ( textarea ) {
			textarea.addEventListener( 'blur', onTextareaBlur );
		}

		let cancelled = false;
		editorReady().then( () => {
			if ( cancelled ) {
				return;
			}
			initializeEditor( domId, {
				tinymce: {
					wpautop: false,
					toolbar1: TINYMCE_TOOLBAR,
					block_formats: TINYMCE_BLOCK_FORMATS,
					valid_elements: TINYMCE_VALID_ELEMENTS,
					setup: ( editor ) => {
						editor.on( 'init', () => {
							// Adopt TinyMCE's serialization of the stored value as
							// the baseline, silently, so opening a product whose
							// HTML it re-serializes is not read as an edit.
							lastEmitted.current = sanitizeRichHtml(
								readContent()
							);
						} );
						editor.on( 'input change SetContent Undo Redo', () =>
							scheduleEmit()
						);
						editor.on( 'blur', () => flushEmit() );
					},
				},
				quicktags: { buttons: QUICKTAGS_BUTTONS },
			} );
		} );

		return () => {
			cancelled = true;
			// No final read here. Every real navigation blurs the editor first,
			// which flushes any pending edit before this runs; and by teardown the
			// editor is detached and reads back empty, so a read would emit '' and
			// blank the stored value on the create-then-save remount.
			if ( timerRef.current ) {
				clearTimeout( timerRef.current );
				timerRef.current = null;
			}
			removeEditor( domId );
			if ( textarea ) {
				textarea.removeEventListener( 'blur', onTextareaBlur );
			}
		};
		// Mount-once: the editor is initialized a single time for the field's life
		// (the id is stable), and value re-seeding is the separate effect below.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	useEffect( () => {
		const incoming = typeof value === 'string' ? value : '';
		if ( incoming === lastEmitted.current ) {
			return;
		}
		// An external change (a reconciled server value): re-seed the live editor
		// and the textarea without letting the resulting SetContent echo emit.
		seedingRef.current = true;
		setEditorContent( domId, incoming );
		if ( textareaRef.current ) {
			textareaRef.current.value = incoming;
		}
		lastEmitted.current = incoming;
		seedingRef.current = false;
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ value ] );

	return (
		<div className="tiermint-rich-text-field">
			<label
				className="tiermint-rich-text-field__label"
				htmlFor={ domId }
			>
				{ field.label }
			</label>
			<textarea
				ref={ textareaRef }
				id={ domId }
				className="tiermint-rich-text-field__textarea"
				defaultValue={ typeof value === 'string' ? value : '' }
			/>
			{ field.help && (
				<p className="tiermint-rich-text-field__help">{ field.help }</p>
			) }
		</div>
	);
}
