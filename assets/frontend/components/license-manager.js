/**
 * TierMint license manager enhancement.
 *
 * Add license activation controls to the server-rendered dashboard.
 */

/**
 * The shared frontend bootstrap config.
 *
 * @type {{apiRoot: string, nonce?: string}}
 */
const config = window.tierMint || { apiRoot: '/wp-json/tiermint/v1/' };

/**
 * Build authenticated REST request headers.
 *
 * @return {Object} Fetch headers.
 */
function apiHeaders() {
	const headers = { 'Content-Type': 'application/json' };
	if ( config.nonce ) {
		headers[ 'X-WP-Nonce' ] = config.nonce;
	}
	return headers;
}

/**
 * Announce a message in a component root's live region.
 *
 * @param {HTMLElement} root    The component root element.
 * @param {string}      message Text to announce; empty clears it.
 * @return {void}
 */
function announce( root, message ) {
	const region = root.querySelector( '[data-tiermint-feedback]' );
	if ( region ) {
		region.textContent = message;
	}
}

/**
 * The generic error message the root carries for network or unexpected faults.
 *
 * @param {HTMLElement} root The component root element.
 * @return {string} The fallback message.
 */
function genericError( root ) {
	return root.getAttribute( 'data-msg-error' ) || '';
}

/**
 * Deactivate a site and update its card.
 *
 * @param {HTMLElement}       root   The component root element.
 * @param {HTMLButtonElement} button The deactivate button that was clicked.
 * @return {void}
 */
function deactivate( root, button ) {
	const key = button.getAttribute( 'data-license-key' );
	const site = button.getAttribute( 'data-site-url' );
	if ( ! key || ! site ) {
		return;
	}

	button.disabled = true;
	announce( root, '' );

	fetch( config.apiRoot + 'license/deactivate', {
		method: 'POST',
		headers: apiHeaders(),
		body: JSON.stringify( { license_key: key, site_url: site } ),
	} )
		.then( ( response ) =>
			response.json().then( ( body ) => ( { ok: response.ok, body } ) )
		)
		.then( ( { ok, body } ) => {
			if ( ! ok || ! body || true !== body.success ) {
				announce(
					root,
					body && body.message ? body.message : genericError( root )
				);
				button.disabled = false;
				return;
			}

			const card = button.closest( '[data-tiermint-license]' );
			const row = button.closest( '[data-tiermint-activation]' );
			if ( row ) {
				row.remove();
			}

			if ( card && 'number' === typeof body.activation_count ) {
				const count = card.querySelector(
					'[data-tiermint-usage-count]'
				);
				if ( count ) {
					count.textContent = String( body.activation_count );
				}
			}
		} )
		.catch( () => {
			announce( root, genericError( root ) );
			button.disabled = false;
		} );
}

/**
 * Activate a site from its license form.
 *
 * @param {HTMLElement}     root The component root element.
 * @param {HTMLFormElement} form The activate form that was submitted.
 * @return {void}
 */
function activate( root, form ) {
	const key = form.getAttribute( 'data-license-key' );
	const input = form.querySelector( '[data-tiermint-site-input]' );
	if ( ! key || ! input ) {
		return;
	}

	if ( ! input.reportValidity() ) {
		return;
	}

	const site = input.value.trim();
	if ( ! site ) {
		return;
	}

	const submit = form.querySelector( '[type="submit"]' );
	if ( submit ) {
		submit.disabled = true;
	}
	announce( root, '' );

	fetch( config.apiRoot + 'license/activate', {
		method: 'POST',
		headers: apiHeaders(),
		body: JSON.stringify( { license_key: key, site_url: site } ),
	} )
		.then( ( response ) =>
			response.json().then( ( body ) => ( {
				status: response.status,
				ok: response.ok,
				body,
			} ) )
		)
		.then( ( { status, ok, body } ) => {
			if ( ok && body && true === body.success ) {
				// The server rerender is authoritative for a new activation.
				window.location.reload();
				return;
			}

			// An activation limit response gives its own message.
			if (
				403 === status &&
				body &&
				'tiermint_activation_limit_reached' === body.code
			) {
				announce( root, body.message || genericError( root ) );
			} else {
				announce(
					root,
					body && body.message ? body.message : genericError( root )
				);
			}

			if ( submit ) {
				submit.disabled = false;
			}
		} )
		.catch( () => {
			announce( root, genericError( root ) );
			if ( submit ) {
				submit.disabled = false;
			}
		} );
}

const roots = document.querySelectorAll( '.tiermint-license-manager' );

roots.forEach( ( root ) => {
	root.addEventListener( 'click', ( event ) => {
		const button = event.target.closest( '[data-tiermint-deactivate]' );
		if ( button && root.contains( button ) ) {
			event.preventDefault();
			deactivate( root, button );
		}
	} );

	root.addEventListener( 'submit', ( event ) => {
		const form = event.target.closest( '[data-tiermint-activate-form]' );
		if ( form && root.contains( form ) ) {
			event.preventDefault();
			activate( root, form );
		}
	} );
} );
