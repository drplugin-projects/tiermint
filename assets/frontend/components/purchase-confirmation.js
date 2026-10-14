/**
 * TierMint purchase-confirmation enhancement.
 *
 * Poll pending orders until the server can render the receipt.
 */

/**
 * The shared frontend bootstrap config.
 *
 * @type {{apiRoot: string, nonce?: string}}
 */
const config = window.tierMint || { apiRoot: '/wp-json/tiermint/v1/' };

/**
 * Maximum status requests before polling stops.
 *
 * @type {number}
 */
const MAX_ATTEMPTS = 30;

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
 * Poll a pending order and reload when it completes.
 *
 * @param {HTMLElement} panel The processing panel with data-order-uuid.
 * @return {void}
 */
function pollStatus( panel ) {
	const uuid = panel.getAttribute( 'data-order-uuid' );
	if ( ! uuid ) {
		return;
	}

	let attempts = 0;

	/**
	 * One polling tick.
	 *
	 * @return {void}
	 */
	function tick() {
		attempts += 1;
		fetch(
			config.apiRoot + 'checkout/status/' + encodeURIComponent( uuid ),
			{
				headers: apiHeaders(),
			}
		)
			.then( ( response ) => ( response.ok ? response.json() : null ) )
			.then( ( body ) => {
				if ( body && true === body.completed ) {
					const confirmed =
						panel.getAttribute( 'data-msg-confirmed' );
					const message = panel.querySelector(
						'.tiermint-purchase-confirmation__processing-message'
					);
					if ( confirmed && message ) {
						message.textContent = confirmed;
					}
					// The server rerender is authoritative for the finished receipt.
					window.location.reload();
					return;
				}
				if ( attempts < MAX_ATTEMPTS ) {
					window.setTimeout( tick, 2000 );
				}
			} )
			.catch( () => {
				if ( attempts < MAX_ATTEMPTS ) {
					window.setTimeout( tick, 2000 );
				}
			} );
	}

	tick();
}

const panels = document.querySelectorAll( '[data-tiermint-order-status]' );

panels.forEach( ( panel ) => pollStatus( panel ) );
