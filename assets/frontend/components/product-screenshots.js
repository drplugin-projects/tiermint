/**
 * TierMint product screenshots modal gallery.
 *
 * Add an accessible modal gallery to screenshot links.
 */

const FALLBACK = {
	close: 'Close',
	prev: 'Previous',
	next: 'Next',
	label: 'Gallery',
	counter: '%1$s of %2$s',
};

/**
 * Read a gallery's translated labels off its container's data attributes.
 *
 * @param {HTMLElement} container The gallery root.
 * @return {{close: string, prev: string, next: string, label: string, counter: string}} The labels.
 */
function readLabels( container ) {
	return {
		close:
			container.getAttribute( 'data-tiermint-lightbox-close' ) ||
			FALLBACK.close,
		prev:
			container.getAttribute( 'data-tiermint-gallery-prev' ) ||
			FALLBACK.prev,
		next:
			container.getAttribute( 'data-tiermint-gallery-next' ) ||
			FALLBACK.next,
		label:
			container.getAttribute( 'data-tiermint-gallery-label' ) ||
			FALLBACK.label,
		counter:
			container.getAttribute( 'data-tiermint-gallery-counter' ) ||
			FALLBACK.counter,
	};
}

/**
 * Build gallery data, or return null when there are no slides.
 *
 * @param {HTMLElement} container The gallery root.
 * @return {{slides: Array<object>, labels: object, index: number}|null} The gallery.
 */
function buildGallery( container ) {
	const linkEls = Array.prototype.slice.call(
		container.querySelectorAll(
			'.tiermint-product-screenshots__link[data-tiermint-lightbox]'
		)
	);

	if ( 0 === linkEls.length ) {
		return null;
	}

	const slides = linkEls.map( ( link ) => {
		const img = link.querySelector( 'img' );

		return {
			link,
			src: link.getAttribute( 'href' ),
			alt: img ? img.getAttribute( 'alt' ) || '' : '',
			caption: link.getAttribute( 'data-tiermint-caption' ) || '',
		};
	} );

	return { slides, labels: readLabels( container ), index: 0 };
}

/**
 * Create a button with the given class list.
 *
 * @param {string} className The class attribute.
 * @return {HTMLButtonElement} The button.
 */
function makeButton( className ) {
	const button = document.createElement( 'button' );
	button.type = 'button';
	button.className = className;

	return button;
}

/**
 * Shared modal controller, created on first use.
 *
 * @type {{open: Function}|null}
 */
let modalApi = null;

/**
 * Build the shared modal controller.
 *
 * @return {{open: Function}} The controller.
 */
function ensureModal() {
	if ( null !== modalApi ) {
		return modalApi;
	}

	const overlay = document.createElement( 'div' );
	// The overlay mounts on the body, outside the gallery root, so it carries the
	// component class itself to pick up the shared reset (font, box-sizing, links).
	overlay.className =
		'tiermint-component tiermint-product-screenshots__modal';
	overlay.setAttribute( 'role', 'dialog' );
	overlay.setAttribute( 'aria-modal', 'true' );
	overlay.hidden = true;

	const closeButton = makeButton(
		'tiermint-product-screenshots__modal-close'
	);
	const prevButton = makeButton(
		'tiermint-product-screenshots__modal-nav tiermint-product-screenshots__modal-prev'
	);
	const nextButton = makeButton(
		'tiermint-product-screenshots__modal-nav tiermint-product-screenshots__modal-next'
	);

	const figure = document.createElement( 'figure' );
	figure.className = 'tiermint-product-screenshots__modal-figure';

	const image = document.createElement( 'img' );
	image.className = 'tiermint-product-screenshots__modal-image';
	image.alt = '';

	const meta = document.createElement( 'figcaption' );
	meta.className = 'tiermint-product-screenshots__modal-meta';

	const counter = document.createElement( 'span' );
	counter.className = 'tiermint-product-screenshots__modal-counter';

	const caption = document.createElement( 'span' );
	caption.className = 'tiermint-product-screenshots__modal-caption';

	meta.appendChild( counter );
	meta.appendChild( caption );
	figure.appendChild( image );
	figure.appendChild( meta );

	overlay.appendChild( closeButton );
	overlay.appendChild( prevButton );
	overlay.appendChild( figure );
	overlay.appendChild( nextButton );
	document.body.appendChild( overlay );

	// The gallery currently shown, or null while the modal is closed.
	let current = null;
	let lastTrigger = null;

	/**
	 * Paint the current slide into the shell.
	 *
	 * @return {void}
	 */
	function render() {
		const slide = current.slides[ current.index ];

		image.setAttribute( 'src', slide.src );
		image.alt = slide.alt || '';
		counter.textContent = current.labels.counter
			.replace( '%1$s', String( current.index + 1 ) )
			.replace( '%2$s', String( current.slides.length ) );
		caption.textContent = slide.caption || '';
	}

	/**
	 * Move by delta slides, wrapping around either end.
	 *
	 * @param {number} delta The step (+1 next, -1 previous).
	 * @return {void}
	 */
	function step( delta ) {
		const total = current.slides.length;
		current.index = ( current.index + delta + total ) % total;
		render();
	}

	/**
	 * The focusable controls currently in the shell (hidden nav buttons excluded).
	 *
	 * @return {HTMLElement[]} The focusable buttons in DOM order.
	 */
	function focusables() {
		return Array.prototype.slice
			.call( overlay.querySelectorAll( 'button' ) )
			.filter( ( button ) => ! button.hidden );
	}

	/**
	 * Keep Tab focus inside the dialog by wrapping at the ends.
	 *
	 * @param {KeyboardEvent} event The Tab keydown.
	 * @return {void}
	 */
	function trapTab( event ) {
		const items = focusables();

		if ( 0 === items.length ) {
			return;
		}

		const first = items[ 0 ];
		const last = items[ items.length - 1 ];
		const active = overlay.ownerDocument.activeElement;

		// Focus can leave the dialog without a Tab wrap: clicking the image (or
		// any non-focusable spot inside the overlay) blurs to the body. Pull it
		// back to the first control so the next Tab stays inside the dialog.
		if ( ! overlay.contains( active ) ) {
			first.focus();
			event.preventDefault();
			return;
		}

		if ( event.shiftKey ) {
			if ( active === first ) {
				last.focus();
				event.preventDefault();
			}
		} else if ( active === last ) {
			first.focus();
			event.preventDefault();
		}
	}

	/**
	 * Close the modal and return focus to the thumbnail that opened it.
	 *
	 * @return {void}
	 */
	function close() {
		overlay.classList.remove( 'is-open' );
		overlay.hidden = true;
		image.removeAttribute( 'src' );
		current = null;

		if ( lastTrigger ) {
			lastTrigger.focus();
			lastTrigger = null;
		}
	}

	/**
	 * Open the modal on one gallery at a given slide.
	 *
	 * @param {Object} gallery The gallery to show.
	 * @param {number} index   The slide index to open on.
	 * @return {void}
	 */
	function open( gallery, index ) {
		current = gallery;
		current.index = index;
		lastTrigger = gallery.slides[ index ].link;

		overlay.setAttribute( 'aria-label', gallery.labels.label );
		closeButton.setAttribute( 'aria-label', gallery.labels.close );
		prevButton.setAttribute( 'aria-label', gallery.labels.prev );
		nextButton.setAttribute( 'aria-label', gallery.labels.next );

		// A single-image gallery needs no navigation controls.
		const single = gallery.slides.length <= 1;
		prevButton.hidden = single;
		nextButton.hidden = single;

		render();
		overlay.hidden = false;
		closeButton.focus();

		// Fade in on the next frame: the display flip must land before the opacity
		// transition can run. Falls back to an immediate show where rAF is absent.
		if ( 'function' === typeof window.requestAnimationFrame ) {
			window.requestAnimationFrame( () =>
				overlay.classList.add( 'is-open' )
			);
		} else {
			overlay.classList.add( 'is-open' );
		}
	}

	closeButton.addEventListener( 'click', close );
	prevButton.addEventListener( 'click', () => step( -1 ) );
	nextButton.addEventListener( 'click', () => step( 1 ) );

	overlay.addEventListener( 'click', ( event ) => {
		if ( event.target === overlay ) {
			close();
		}
	} );

	document.addEventListener( 'keydown', ( event ) => {
		if ( overlay.hidden ) {
			return;
		}

		switch ( event.key ) {
			case 'Escape':
				close();
				break;
			case 'ArrowRight':
				event.preventDefault();
				step( 1 );
				break;
			case 'ArrowLeft':
				event.preventDefault();
				step( -1 );
				break;
			case 'Tab':
				trapTab( event );
				break;
			default:
				break;
		}
	} );

	modalApi = { open };

	return modalApi;
}

/**
 * Wire every gallery on the page to the shared modal.
 *
 * @return {void}
 */
function init() {
	const containers = Array.prototype.slice.call(
		document.querySelectorAll( '.tiermint-product-screenshots' )
	);

	containers.forEach( ( container ) => {
		const gallery = buildGallery( container );

		if ( null === gallery ) {
			return;
		}

		gallery.slides.forEach( ( slide, index ) => {
			slide.link.addEventListener( 'click', ( event ) => {
				event.preventDefault();
				ensureModal().open( gallery, index );
			} );
		} );
	} );
}

init();
