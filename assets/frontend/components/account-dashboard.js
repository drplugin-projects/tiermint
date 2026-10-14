/**
 * TierMint account dashboard tabs enhancement.
 *
 * Add accessible tabs to the server-rendered dashboard.
 */

/**
 * Enhance one dashboard root into an interactive tab set.
 *
 * @param {HTMLElement} root The dashboard tabs container.
 * @return {void}
 */
function enhance( root ) {
	const tablist = root.querySelector( '[role="tablist"]' );
	if ( ! tablist ) {
		return;
	}

	/**
	 * The tab controls, in document order.
	 *
	 * @type {HTMLElement[]}
	 */
	const tabs = Array.prototype.slice.call(
		tablist.querySelectorAll( '[role="tab"]' )
	);
	if ( 0 === tabs.length ) {
		return;
	}

	/**
	 * Panels controlled by each tab.
	 *
	 * @type {Array<HTMLElement|null>}
	 */
	const panels = tabs.map( ( tab ) =>
		document.getElementById( tab.getAttribute( 'aria-controls' ) )
	);

	/**
	 * Select a tab and its panel.
	 *
	 * @param {number}  index The tab index to select.
	 * @param {boolean} focus Whether to move focus to the newly selected tab.
	 * @return {void}
	 */
	function select( index, focus ) {
		tabs.forEach( ( tab, i ) => {
			const active = i === index;
			tab.setAttribute( 'aria-selected', active ? 'true' : 'false' );
			tab.setAttribute( 'tabindex', active ? '0' : '-1' );

			const panel = panels[ i ];
			if ( panel ) {
				if ( active ) {
					panel.removeAttribute( 'hidden' );
				} else {
					panel.setAttribute( 'hidden', '' );
				}
			}

			if ( active && focus ) {
				tab.focus();
			}
		} );
	}

	/**
	 * Current tab-list orientation.
	 *
	 * @type {string}
	 */
	let orientation = 'horizontal';

	/**
	 * Read the CSS orientation for keyboard navigation.
	 *
	 * @return {void}
	 */
	function applyOrientation() {
		const value = window
			.getComputedStyle( tablist )
			.getPropertyValue( '--tiermint-account-orientation' )
			.trim();

		orientation = 'vertical' === value ? 'vertical' : 'horizontal';
		tablist.setAttribute( 'aria-orientation', orientation );
	}

	tabs.forEach( ( tab, i ) => {
		tab.addEventListener( 'click', ( event ) => {
			// Prevent the fragment link from scrolling after selection.
			event.preventDefault();
			select( i, false );
		} );

		tab.addEventListener( 'keydown', ( event ) => {
			// Match arrow keys to the visual tab layout.
			const previousKey =
				'vertical' === orientation ? 'ArrowUp' : 'ArrowLeft';
			const nextKey =
				'vertical' === orientation ? 'ArrowDown' : 'ArrowRight';

			let next = null;

			switch ( event.key ) {
				case previousKey:
					next = ( i - 1 + tabs.length ) % tabs.length;
					break;
				case nextKey:
					next = ( i + 1 ) % tabs.length;
					break;
				case 'Home':
					next = 0;
					break;
				case 'End':
					next = tabs.length - 1;
					break;
				default:
					return;
			}

			event.preventDefault();
			select( next, true );
		} );
	} );

	// Keep keyboard navigation aligned with the responsive layout.
	applyOrientation();

	if ( 'function' === typeof window.ResizeObserver ) {
		const observer = new window.ResizeObserver( () => applyOrientation() );
		observer.observe( root );
	}

	// Show the first panel after enhancement.
	select( 0, false );
}

document
	.querySelectorAll( '[data-tiermint-account-dashboard]' )
	.forEach( enhance );
