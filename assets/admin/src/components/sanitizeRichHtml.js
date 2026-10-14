/**
 * Sanitizes rich-text HTML to a subset of `wp_kses_post`.
 *
 * Rebuild the output tree so unapproved nodes and attributes cannot pass through.
 */

/**
 * Tags emitted verbatim (as a clean element with no attributes, except `a`,
 * which keeps only a validated `href`).
 *
 * @type {Set<string>}
 */
const ALLOWED = new Set( [
	'STRONG',
	'EM',
	'A',
	'UL',
	'OL',
	'LI',
	'P',
	'BR',
	'H1',
	'H2',
	'H3',
	'H4',
	'H5',
	'H6',
] );

/**
 * Presentational tags mapped to their semantic allowed equivalent.
 *
 * @type {Object<string, string>}
 */
const TAG_MAP = { B: 'STRONG', I: 'EM' };

/**
 * Elements removed with their children, including raw-text and form elements.
 *
 * @type {Set<string>}
 */
const DROP = new Set( [
	'SCRIPT',
	'STYLE',
	'IFRAME',
	'OBJECT',
	'EMBED',
	'APPLET',
	'TEXTAREA',
	'TITLE',
	'XMP',
	'PLAINTEXT',
	'NOEMBED',
	'NOFRAMES',
	'NOSCRIPT',
	'TEMPLATE',
	'LINK',
	'META',
	'BASE',
	'SVG',
	'MATH',
	'IMG',
	'PICTURE',
	'AUDIO',
	'VIDEO',
	'SOURCE',
	'TRACK',
	'CANVAS',
	'FORM',
	'INPUT',
	'SELECT',
	'OPTION',
	'OPTGROUP',
	'BUTTON',
	'MAP',
	'AREA',
	'FRAME',
	'FRAMESET',
	'PARAM',
	'MARQUEE',
	'DIALOG',
	'SLOT',
] );

/**
 * Benign wrapper elements: dropped, but their sanitized children are kept, so
 * pasting a blockquote or a styled span keeps the text without the wrapper.
 *
 * @type {Set<string>}
 */
const UNWRAP = new Set( [
	'DIV',
	'SPAN',
	'BLOCKQUOTE',
	'SECTION',
	'ARTICLE',
	'MAIN',
	'ASIDE',
	'HEADER',
	'FOOTER',
	'NAV',
	'FIGURE',
	'FIGCAPTION',
	'HGROUP',
	'ADDRESS',
	'U',
	'S',
	'STRIKE',
	'DEL',
	'INS',
	'SUB',
	'SUP',
	'SMALL',
	'BIG',
	'FONT',
	'TT',
	'CODE',
	'PRE',
	'SAMP',
	'KBD',
	'VAR',
	'DFN',
	'CITE',
	'ABBR',
	'MARK',
	'TIME',
	'Q',
	'CENTER',
	'LABEL',
	'CAPTION',
	'DL',
	'DT',
	'DD',
	'WBR',
	'BDI',
	'BDO',
	'RUBY',
] );

/**
 * The href schemes an `<a>` may keep, matching what `wp_kses_post` allows.
 *
 * @type {Set<string>}
 */
const SAFE_SCHEMES = new Set( [ 'http', 'https', 'mailto' ] );

/**
 * Remove the control characters that `wp_kses_no_null` removes.
 *
 * @param {string} value The string to clean.
 * @return {string} The string without kses-stripped control characters.
 */
function stripControls( value ) {
	let out = '';
	for ( let i = 0; i < value.length; i += 1 ) {
		const code = value.charCodeAt( i );
		if ( code === 0x09 || code === 0x0a || code === 0x0d || code > 0x1f ) {
			out += value[ i ];
		}
	}
	return out;
}

/**
 * Strip leading and trailing whitespace and control characters.
 *
 * @param {string} value The string to trim.
 * @return {string} The trimmed string.
 */
function trimControl( value ) {
	let start = 0;
	while ( start < value.length && value.charCodeAt( start ) <= 0x20 ) {
		start += 1;
	}
	let end = value.length;
	while ( end > start && value.charCodeAt( end - 1 ) <= 0x20 ) {
		end -= 1;
	}
	return value.slice( start, end );
}

/**
 * Validate an `href` with the `wp_kses_bad_protocol` rules.
 *
 * Keep only safe schemes and values that `wp_kses_post` will not rewrite.
 *
 * @param {?string} raw The raw attribute value (already entity-decoded by the DOM).
 * @return {?string} The href to keep, or null to drop it.
 */
function safeHref( raw ) {
	if ( typeof raw !== 'string' ) {
		return null;
	}

	// kses removes its control-character set from the whole string first, so do
	// that before anything else reads the value.
	const cleaned = stripControls( raw );

	// Leading whitespace and control characters are ignored when resolving a
	// protocol, so strip them before looking for the scheme, as kses does.
	let start = 0;
	while ( start < cleaned.length && cleaned.charCodeAt( start ) <= 0x20 ) {
		start += 1;
	}
	const value = cleaned.slice( start );
	if ( value === '' ) {
		return null;
	}

	const colon = value.indexOf( ':' );
	let result;
	if ( colon === -1 ) {
		// No colon at all: relative, anchor, or query. No scheme to police.
		result = value;
	} else if ( value.slice( 0, colon ).includes( '/?' ) ) {
		// kses does not treat a colon as a scheme separator when the text before
		// it contains "/?", so it leaves the value alone. Keep it verbatim.
		result = value;
	} else {
		const scheme = value.slice( 0, colon ).toLowerCase();
		if ( ! SAFE_SCHEMES.has( scheme ) ) {
			// kses would read the pre-colon text as an unknown scheme and rewrite
			// the link (stripping up to the colon). Drop the anchor instead so no
			// href kses would change is stored; the link text is kept.
			return null;
		}
		// Lowercase the scheme and trim the part after its colon, both of which
		// kses does, so the stored value never changes on a save.
		result = scheme + ':' + trimControl( value.slice( colon + 1 ) );
	}

	// Percent-encode any angle brackets so the serialized attribute carries no
	// raw `<`/`>` for kses's tokenizer to trip over. The link still resolves.
	return result.replace( /</g, '%3C' ).replace( />/g, '%3E' );
}

/**
 * Append a cleaned copy of every child of `source` into `target`, recursing.
 *
 * @param {Node}     source The source node whose children to clean.
 * @param {Element}  target The output element to append cleaned nodes to.
 * @param {Document} doc    The document the output nodes are created in.
 * @return {void}
 */
function appendClean( source, target, doc ) {
	source.childNodes.forEach( ( node ) => {
		// Text nodes carry through as text (the DOM escapes them on serialize),
		// with the control characters kses would strip removed so the text is a
		// fixed point of wp_kses_post.
		if ( node.nodeType === 3 ) {
			target.appendChild(
				doc.createTextNode( stripControls( node.data ) )
			);
			return;
		}

		// Everything that is not an element (comments, CDATA, ...) is discarded.
		if ( node.nodeType !== 1 ) {
			return;
		}

		const tag = node.tagName;

		if ( DROP.has( tag ) ) {
			return;
		}

		const mapped = TAG_MAP[ tag ] || ( ALLOWED.has( tag ) ? tag : null );

		if ( mapped === 'A' ) {
			const href = safeHref( node.getAttribute( 'href' ) );
			if ( href === null ) {
				// An anchor with no safe href is not a link: keep its text, drop
				// the wrapper, so the output never carries a dead or unsafe link.
				appendClean( node, target, doc );
				return;
			}
			const anchor = doc.createElement( 'a' );
			anchor.setAttribute( 'href', href );
			appendClean( node, anchor, doc );
			target.appendChild( anchor );
			return;
		}

		if ( mapped !== null ) {
			const clean = doc.createElement( mapped.toLowerCase() );
			appendClean( node, clean, doc );
			target.appendChild( clean );
			return;
		}

		if ( UNWRAP.has( tag ) ) {
			appendClean( node, target, doc );
		}

		// Default-deny: an element that is neither allowed, mapped, nor a known
		// benign wrapper is dropped whole, so an unknown or future element can
		// never get a free pass through the sanitizer.
	} );
}

/**
 * Detect empty editor markup left after content is cleared.
 *
 * @param {Element} container The rebuilt output container.
 * @return {boolean} True when nothing visible remains.
 */
function isEmpty( container ) {
	// This control never emits media, so the only visible content is text; empty
	// text content means only structural husks remain.
	return container.textContent.trim() === '';
}

/**
 * Sanitize an HTML string down to the rich text control's allowed grammar.
 *
 * @param {string} html The raw HTML (from the editor, a paste, or stored data).
 * @return {string} The sanitized HTML, or '' when nothing visible remains.
 */
export function sanitizeRichHtml( html ) {
	if ( typeof html !== 'string' || html === '' ) {
		return '';
	}

	const doc = new window.DOMParser().parseFromString( html, 'text/html' );
	const container = doc.createElement( 'div' );
	appendClean( doc.body, container, doc );

	if ( isEmpty( container ) ) {
		return '';
	}

	return container.innerHTML;
}
