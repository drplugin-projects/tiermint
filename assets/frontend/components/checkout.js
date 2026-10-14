/**
 * Enhance the server-rendered checkout.
 */

/**
 * The shared frontend bootstrap config.
 *
 * @type {{apiRoot: string, nonce?: string}}
 */
const config = window.tierMint || { apiRoot: '/wp-json/tiermint/v1/' };

/**
 * Builds headers for tiermint/v1 calls.
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
 * Informational message-region class.
 *
 * @type {string}
 */
const NOTICE_CLASS = 'tiermint-checkout__errors--notice';

/**
 * Delay before a totals quote.
 *
 * @type {number}
 */
const QUOTE_DEBOUNCE_MS = 400;

/**
 * Redirect marker for a refused discount change.
 *
 * @type {string}
 */
const ERROR_MARKER = 'tiermint_cart_error';

/**
 * Currencies stored as whole units in the minor field.
 *
 * @type {string[]}
 */
const ZERO_DECIMAL_CURRENCIES = [
	'BIF',
	'CLP',
	'DJF',
	'GNF',
	'JPY',
	'KMF',
	'KRW',
	'MGA',
	'PYG',
	'RWF',
	'UGX',
	'VND',
	'VUV',
	'XAF',
	'XOF',
	'XPF',
];

/**
 * Formats a minor-unit amount for the buyer locale.
 *
 * @param {number} minor    Amount in minor units.
 * @param {string} currency ISO 4217 currency code.
 * @return {string} Localized amount, e.g. "$49.00".
 */
function formatMoney( minor, currency ) {
	const divisor = ZERO_DECIMAL_CURRENCIES.includes( currency.toUpperCase() )
		? 1
		: 100;

	return new Intl.NumberFormat( undefined, {
		style: 'currency',
		currency,
		// Match server-rendered line prices.
		currencyDisplay: 'narrowSymbol',
	} ).format( minor / divisor );
}

/**
 * Show a message in the form's live error region.
 *
 * @param {HTMLFormElement} form    The checkout form.
 * @param {string}          message Text to announce; empty clears it.
 * @return {void}
 */
function showError( form, message ) {
	const region = form.querySelector( '.tiermint-checkout__errors' );
	if ( region ) {
		region.textContent = message;
		region.classList.remove( NOTICE_CLASS );
	}
}

/**
 * Collects extension checkout fields by ID.
 *
 * Empty fields are omitted. Checked boxes send '1'.
 *
 * @param {HTMLFormElement} form The checkout form.
 * @return {Object} Field id to string value.
 */
function collectFields( form ) {
	const values = {};

	form.querySelectorAll( '[data-tiermint-field]' ).forEach( ( control ) => {
		const id = control.getAttribute( 'data-tiermint-field' );

		if ( ! id ) {
			return;
		}

		if ( 'checkbox' === control.type ) {
			if ( control.checked ) {
				values[ id ] = '1';
			}
			return;
		}

		const value =
			'string' === typeof control.value ? control.value.trim() : '';

		if ( value ) {
			values[ id ] = value;
		}
	} );

	return values;
}

/**
 * Gets the trimmed discount code, if present.
 *
 * @param {HTMLFormElement} form The checkout form.
 * @return {string} The code.
 */
function discountCode( form ) {
	const root = form.closest( '[data-tiermint-checkout]' ) || form;
	const input = root.querySelector( '#tiermint-checkout-discount' );

	return input ? input.value.trim() : '';
}

/**
 * Poll the order status and update the confirmation panel.
 *
 * @param {HTMLElement} panel The confirmation panel with data-order-uuid.
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
			{ headers: apiHeaders() }
		)
			.then( ( response ) => ( response.ok ? response.json() : null ) )
			.then( ( body ) => {
				if ( body && true === body.completed ) {
					const status = panel.querySelector(
						'.tiermint-checkout__confirmation-status'
					);
					if ( status ) {
						status.textContent =
							panel.getAttribute( 'data-msg-confirmed' ) ||
							status.textContent;
					}
					return;
				}
				if ( attempts < 30 ) {
					window.setTimeout( tick, 2000 );
				}
			} )
			.catch( () => {
				if ( attempts < 30 ) {
					window.setTimeout( tick, 2000 );
				}
			} );
	}

	tick();
}

/**
 * Build the Stripe return URL with the order UUID.
 *
 * @param {string} uuid The order uuid.
 * @return {string} Absolute return URL.
 */
function returnUrl( uuid ) {
	const url = new URL( window.location.href );
	url.searchParams.delete( 'tiermint_cart_error' );
	url.searchParams.set( 'tiermint_order', uuid );
	return url.toString();
}

/**
 * Write an amount into the summary's total row.
 *
 * @param {HTMLElement} root     The checkout root.
 * @param {number}      minor    Grand total in minor units.
 * @param {string}      currency ISO 4217 currency code.
 * @return {boolean} True when this actually moved the displayed figure.
 */
function writeTotal( root, minor, currency ) {
	const total = root.querySelector( '[data-total-minor]' );

	if ( ! total ) {
		return false;
	}

	const moved = readMinor( total, 'data-total-minor' ) !== minor;

	total.textContent = formatMoney( minor, currency );
	total.setAttribute( 'data-total-minor', String( minor ) );

	return moved;
}

/**
 * Read the rendered minor-unit amount for later comparison.
 *
 * @param {?HTMLElement} element   The element carrying the attribute.
 * @param {string}       attribute The attribute name.
 * @return {?number} The amount, or null when absent or unparseable.
 */
function readMinor( element, attribute ) {
	if ( ! element ) {
		return null;
	}

	const value = parseInt( element.getAttribute( attribute ), 10 );

	return Number.isNaN( value ) ? null : value;
}

/**
 * Update the tax row and hide it when tax is zero.
 *
 * @param {HTMLElement} root     The checkout root.
 * @param {number}      minor    Tax in minor units.
 * @param {string}      currency ISO 4217 currency code.
 * @return {boolean} True when this actually moved the displayed figure.
 */
function writeTax( root, minor, currency ) {
	const row = root.querySelector( '[data-tiermint-tax-line]' );
	const amount = row && row.querySelector( '[data-tiermint-tax-amount]' );

	if ( ! row || ! amount ) {
		return false;
	}

	const moved = readMinor( row, 'data-tax-minor' ) !== minor;

	amount.textContent = formatMoney( minor, currency );
	row.setAttribute( 'data-tax-minor', String( minor ) );
	row.hidden = 0 === minor;

	return moved;
}

/**
 * Update the code discount row from a quote.
 *
 * @param {HTMLElement} root     The checkout root.
 * @param {*}           discount The `discount` field of a quote response.
 * @param {string}      currency ISO 4217 currency code.
 * @return {void}
 */
function writeDiscount( root, discount, currency ) {
	const summary = root.querySelector( '.tiermint-checkout__summary' );

	if ( ! summary ) {
		return;
	}

	let line = summary.querySelector( '[data-tiermint-discount-line]' );

	const valid =
		discount &&
		true === discount.valid &&
		'number' === typeof discount.amount &&
		discount.amount > 0;

	if ( ! valid ) {
		if ( line ) {
			line.remove();
		}
		return;
	}

	if ( ! line ) {
		line = document.createElement( 'p' );
		line.className = 'tiermint-checkout__discount-line';
		line.setAttribute( 'data-tiermint-discount-line', '' );
		line.appendChild( document.createElement( 'span' ) );
		const amountEl = document.createElement( 'span' );
		amountEl.setAttribute( 'data-tiermint-discount-amount', '' );
		line.appendChild( amountEl );

		// Keep code discounts before automatic discounts and tax.
		const anchor =
			summary.querySelector( '[data-tiermint-auto-discount-line]' ) ||
			summary.querySelector( '[data-tiermint-tax-line]' ) ||
			summary.querySelector( '.tiermint-checkout__total' );
		if ( anchor ) {
			summary.insertBefore( line, anchor );
		} else {
			summary.appendChild( line );
		}
	}

	line.querySelector( 'span' ).textContent =
		'string' === typeof discount.label ? discount.label : '';
	line.querySelector( '[data-tiermint-discount-amount]' ).textContent =
		'-' + formatMoney( discount.amount, currency );
	// Keep the rendered code discount for intent reconciliation.
	line.setAttribute( 'data-discount-minor', String( discount.amount ) );
}

/**
 * Update the automatic discount row from a server response.
 *
 * @param {HTMLElement} root     The checkout root.
 * @param {*}           auto     The `auto_discount` field of a quote or intent response.
 * @param {string}      currency ISO 4217 currency code.
 * @return {void}
 */
function writeAutoDiscount( root, auto, currency ) {
	const summary = root.querySelector( '.tiermint-checkout__summary' );

	if ( ! summary ) {
		return;
	}

	let line = summary.querySelector( '[data-tiermint-auto-discount-line]' );

	const valid = auto && 'number' === typeof auto.amount && auto.amount > 0;

	if ( ! valid ) {
		if ( line ) {
			line.remove();
		}
		return;
	}

	if ( ! line ) {
		line = document.createElement( 'p' );
		line.className = 'tiermint-checkout__discount-line';
		line.setAttribute( 'data-tiermint-auto-discount-line', '' );
		line.appendChild( document.createElement( 'span' ) );
		const amountEl = document.createElement( 'span' );
		amountEl.setAttribute( 'data-tiermint-auto-discount-amount', '' );
		line.appendChild( amountEl );

		// Keep automatic discounts before tax.
		const anchor =
			summary.querySelector( '[data-tiermint-tax-line]' ) ||
			summary.querySelector( '.tiermint-checkout__total' );
		if ( anchor ) {
			summary.insertBefore( line, anchor );
		} else {
			summary.appendChild( line );
		}
	}

	line.querySelector( 'span' ).textContent =
		'string' === typeof auto.label ? auto.label : '';
	line.querySelector( '[data-tiermint-auto-discount-amount]' ).textContent =
		'-' + formatMoney( auto.amount, currency );
	// Keep the rendered automatic discount for reconciliation.
	line.setAttribute( 'data-discount-minor', String( auto.amount ) );
}

/**
 * Join valid server notices for the shared message region.
 *
 * @param {*} notices The `notices` value from a quote or an intent response.
 * @return {string} The joined text, or '' when there is nothing to say.
 */
function noticeText( notices ) {
	return Array.isArray( notices )
		? notices
				.filter(
					( notice ) => 'string' === typeof notice && '' !== notice
				)
				.join( ' ' )
		: '';
}

/**
 * Show current notices without clearing an error from an unchanged total.
 *
 * @param {HTMLFormElement} form       The checkout form.
 * @param {Array}           notices    Notice strings from the response.
 * @param {boolean}         superseded Whether this response moved a figure the
 *                                     buyer can see. Consulted only when there are no notices to show.
 * @return {void}
 */
function showNotices( form, notices, superseded ) {
	const region = form.querySelector( '.tiermint-checkout__errors' );

	if ( ! region ) {
		return;
	}

	const text = noticeText( notices );

	if ( '' !== text ) {
		region.textContent = text;
		region.classList.add( NOTICE_CLASS );
		return;
	}

	if ( region.classList.contains( NOTICE_CLASS ) || superseded ) {
		region.textContent = '';
		region.classList.remove( NOTICE_CLASS );
	}
}

/**
 * Provide a safe default quote-cancel hook.
 *
 * @return {void}
 */
function noop() {}

/**
 * Create per-form state shared by quotes and payment.
 *
 * @return {{stripe: ?Object, elements: ?Object, secret: ?string, uuid: ?string,
 *           busy: boolean, cancelQuote: Function, quoteSeq: number,
 *           requestQuote: ?Function}} The state object.
 */
function createSession() {
	return {
		stripe: null,
		elements: null,
		secret: null,
		uuid: null,
		busy: false,
		cancelQuote: noop,
		// Let newer quotes supersede older responses.
		quoteSeq: 0,
		// Let discount actions request a quote without field listeners.
		requestQuote: null,
	};
}

/**
 * Check whether payment owns the displayed total.
 *
 * @param {Object} session The shared per-form state.
 * @return {boolean} True when no quote may be issued or applied.
 */
function isCommitted( session ) {
	return session.busy || null !== session.elements;
}

/**
 * Re-price the summary after relevant field changes.
 * The payment intent remains authoritative after submission.
 *
 * @param {HTMLFormElement} form    The checkout form.
 * @param {Object}          session The shared per-form state.
 * @return {void}
 */
function wireLiveQuote( form, session ) {
	const root = form.closest( '[data-tiermint-checkout]' );

	if ( ! root ) {
		return;
	}

	// Do not quote discount codes until the buyer applies them.
	const controls = Array.prototype.slice.call(
		form.querySelectorAll( '[data-tiermint-field]' )
	);

	/**
	 * Debounce handle for the pending quote request.
	 *
	 * @type {?number}
	 */
	let timer = null;

	/**
	 * Quote request.
	 *
	 * @param {string} [codeOverride] Code.
	 * @return {Promise<?Object>} Quote or null.
	 */
	function requestQuote( codeOverride ) {
		session.quoteSeq += 1;
		const sequence = session.quoteSeq;

		const body = collectFields( form );
		const code = undefined !== codeOverride ? codeOverride : '';
		if ( code ) {
			body.discount_code = code;
		}

		return fetch( config.apiRoot + 'checkout/quote', {
			method: 'POST',
			headers: apiHeaders(),
			body: JSON.stringify( body ),
		} )
			.then( ( response ) => ( response.ok ? response.json() : null ) )
			.then( ( answer ) => {
				// Mark stale quotes as distinct from failed quotes.
				if ( sequence !== session.quoteSeq || isCommitted( session ) ) {
					return undefined;
				}

				// Leave totals unchanged when the quote fails.
				if ( ! answer || ! answer.currency ) {
					return answer || null;
				}

				// Clear errors only when this quote changed a figure.
				let moved = false;

				if ( 'number' === typeof answer.tax_total ) {
					moved = writeTax( root, answer.tax_total, answer.currency );
				}

				if ( 'number' === typeof answer.total ) {
					moved =
						writeTotal( root, answer.total, answer.currency ) ||
						moved;
				}

				writeDiscount( root, answer.discount, answer.currency );
				writeAutoDiscount(
					root,
					answer.auto_discount,
					answer.currency
				);
				showNotices( form, answer.notices, moved );

				// Remove an invalid stored code from the rendered cart.
				if (
					'' === code &&
					answer.discount &&
					false === answer.discount.valid &&
					'function' === typeof session.onDiscountInvalidated
				) {
					session.onDiscountInvalidated( answer.discount.message );
				}

				return answer;
			} )
			.catch( () => null );
	}

	// Let discount actions quote without quote-relevant fields.
	session.requestQuote = requestQuote;

	/**
	 * Cancel pending quotes before the payment intent sets totals.
	 *
	 * @return {void}
	 */
	function cancelQuote() {
		window.clearTimeout( timer );
		timer = null;
		session.quoteSeq += 1;
	}

	session.cancelQuote = cancelQuote;

	// No fields need quotes, but discount actions can still quote.
	if ( ! controls.length ) {
		return;
	}

	/**
	 * Debounce rapid field changes into one quote.
	 *
	 * @return {void}
	 */
	function schedule() {
		window.clearTimeout( timer );
		timer = null;

		// Ignore field changes after payment starts.
		if ( isCommitted( session ) ) {
			return;
		}

		timer = window.setTimeout( requestQuote, QUOTE_DEBOUNCE_MS );
	}

	controls.forEach( ( control ) => {
		control.addEventListener( 'change', schedule );
	} );

	// Quote prefilled fields, but not an empty form.
	if ( controls.some( hasQuotableValue ) ) {
		schedule();
	}
}

/**
 * Check whether a control can affect a quote.
 *
 * @param {HTMLElement} control A [data-tiermint-field] control.
 * @return {boolean} True when the control already has a value.
 */
function hasQuotableValue( control ) {
	if ( 'checkbox' === control.type || 'radio' === control.type ) {
		return true === control.checked;
	}

	return 'string' === typeof control.value && '' !== control.value.trim();
}

/**
 * Reconcile the rendered total with the payment intent.
 * Show a warning unless the server notice explains the change.
 *
 * @param {HTMLFormElement} form    The checkout form.
 * @param {Object}          body    The intent response (amount, tax_total, currency, notices).
 * @param {Object}          session The shared per-form state, for reverting the discount chip.
 * @return {void}
 */
function reconcileTotal( form, body, session ) {
	if ( 'number' !== typeof body.amount || ! body.currency ) {
		return;
	}

	const root = form.closest( '[data-tiermint-checkout]' );
	const total = root && root.querySelector( '[data-total-minor]' );

	if ( ! total ) {
		return;
	}

	// Read displayed values before replacing either one.
	const rendered = readMinor( total, 'data-total-minor' );
	const moved = rendered !== body.amount;
	const explained = ! moved || taxExplainsTheChange( root, body, rendered );

	if ( 'number' === typeof body.tax_total ) {
		writeTax( root, body.tax_total, body.currency );
	}

	if ( moved ) {
		writeTotal( root, body.amount, body.currency );
	}

	// Match the automatic discount line to the payment intent.
	writeAutoDiscount( root, body.auto_discount, body.currency );

	// Remove the code row when the payment intent drops that code.
	const codeAmount =
		body.discount &&
		true === body.discount.valid &&
		'number' === typeof body.discount.amount
			? body.discount.amount
			: 0;
	if ( 0 === codeAmount ) {
		const staleLine = root.querySelector( '[data-tiermint-discount-line]' );
		if ( staleLine ) {
			staleLine.remove();

			if ( 'function' === typeof session.showDiscountInput ) {
				session.showDiscountInput();
			}
		}
	}

	if ( explained ) {
		showNotices( form, body.notices, moved );
		return;
	}

	showError( form, form.getAttribute( 'data-msg-total-changed' ) || '' );
}

/**
 * Check whether tax and discounts fully explain a total change.
 *
 * @param {HTMLElement} root     The checkout root.
 * @param {Object}      body     The intent response.
 * @param {?number}     rendered The total in minor units the buyer was shown.
 * @return {boolean} True when the movement is fully explained.
 */
function taxExplainsTheChange( root, body, rendered ) {
	if (
		'' === noticeText( body.notices ) ||
		null === rendered ||
		'number' !== typeof body.tax_total
	) {
		return false;
	}

	const renderedTax = readMinor(
		root.querySelector( '[data-tiermint-tax-line]' ),
		'data-tax-minor'
	);

	if ( null === renderedTax ) {
		return false;
	}

	// Combine rendered code and automatic discounts for comparison.
	const renderedDiscount =
		( readMinor(
			root.querySelector( '[data-tiermint-discount-line]' ),
			'data-discount-minor'
		) || 0 ) +
		( readMinor(
			root.querySelector( '[data-tiermint-auto-discount-line]' ),
			'data-discount-minor'
		) || 0 );
	const discountTotal =
		'number' === typeof body.discount_total ? body.discount_total : 0;

	return (
		body.amount - rendered ===
		body.tax_total - renderedTax + ( renderedDiscount - discountTotal )
	);
}

/**
 * Replace a stale checkout form with the login-required message.
 *
 * @param {HTMLFormElement} form The checkout form.
 * @param {Object}          body The error response ({ code, message }).
 * @return {void}
 */
function handleLoginRequired( form, body ) {
	form.querySelectorAll( 'button[type="submit"]' ).forEach( ( button ) => {
		button.hidden = true;
		button.disabled = true;
	} );

	const region = form.querySelector( '.tiermint-checkout__errors' );

	if ( ! region ) {
		return;
	}

	region.classList.remove( NOTICE_CLASS );
	region.textContent =
		( body && body.message ) || form.getAttribute( 'data-msg-error' ) || '';

	const reloadText = form.getAttribute( 'data-msg-reload' );

	if ( ! reloadText ) {
		return;
	}

	region.appendChild( document.createTextNode( ' ' ) );

	// Reload to render the server-side login form.
	const link = document.createElement( 'a' );
	link.href = window.location.href;
	link.className = 'tiermint-checkout__login-reload';
	link.textContent = reloadText;
	region.appendChild( link );
}

/**
 * Wire intent creation and Stripe payment confirmation.
 *
 * @param {HTMLFormElement} form  The checkout form.
 * @param {Object}          state The shared per-form state, see createSession().
 * @return {void}
 */
function wireForm( form, state ) {
	form.addEventListener( 'submit', ( event ) => {
		event.preventDefault();

		if ( state.busy ) {
			return;
		}

		// Prevent an advisory quote from replacing intent totals.
		state.cancelQuote();

		showError( form, '' );

		const gateway = submittedGateway( event, form );

		// Confirm Stripe after Elements mounts; other gateways redirect.
		if ( 'stripe' === gateway && state.elements ) {
			confirmPayment();
			return;
		}

		createIntent( gateway );
	} );

	/**
	 * Create an intent, then mount Stripe or redirect to the gateway.
	 *
	 * @param {string} gateway The selected gateway id.
	 * @return {void}
	 */
	function createIntent( gateway ) {
		const email = form.querySelector( '#tiermint-checkout-email' );

		if ( email && ! email.reportValidity() ) {
			return;
		}

		const firstName = form.querySelector( '#tiermint-checkout-first-name' );
		const lastName = form.querySelector( '#tiermint-checkout-last-name' );
		const company = form.querySelector( '#tiermint-checkout-company' );
		const code = discountCode( form );

		state.busy = true;
		form.classList.add( 'tiermint-checkout__form--busy' );

		// Core details override extension fields with matching IDs.
		const payload = Object.assign( collectFields( form ), {
			gateway,
			email: email ? email.value : '',
			first_name: firstName ? firstName.value : '',
			last_name: lastName ? lastName.value : '',
			company_name: company ? company.value : '',
		} );

		// Send only a typed code to avoid a rejected empty value.
		if ( code ) {
			payload.discount_code = code;
		}

		fetch( config.apiRoot + 'checkout/intent', {
			method: 'POST',
			headers: apiHeaders(),
			body: JSON.stringify( payload ),
		} )
			.then( ( response ) =>
				response
					.json()
					.then( ( body ) => ( { ok: response.ok, body } ) )
			)
			.then( ( { ok, body } ) => {
				if ( ! ok ) {
					// Show the login-required state instead of a generic error.
					if ( body && 'tiermint_login_required' === body.code ) {
						handleLoginRequired( form, body );
						return;
					}

					const message =
						body && body.message
							? body.message
							: form.getAttribute( 'data-msg-error' );
					showError( form, message || '' );
					return;
				}

				// Redirect gateways continue on the gateway approval page.
				if ( body.redirect_url ) {
					window.location.assign( body.redirect_url );
					return;
				}

				if ( ! body.client_secret || ! body.publishable_key ) {
					showError(
						form,
						form.getAttribute( 'data-msg-error' ) || ''
					);
					return;
				}

				if ( 'undefined' === typeof window.Stripe ) {
					showError(
						form,
						form.getAttribute( 'data-msg-error' ) || ''
					);
					return;
				}

				state.uuid = body.order_uuid;
				state.secret = body.client_secret;
				state.stripe = window.Stripe( body.publishable_key );
				state.elements = state.stripe.elements( {
					clientSecret: body.client_secret,
				} );
				state.elements
					.create( 'payment' )
					.mount( '#tiermint-checkout-payment' );

				showError( form, '' );
				const hint = form.getAttribute( 'data-msg-continue' );
				if ( hint ) {
					const region = form.querySelector(
						'.tiermint-checkout__errors'
					);
					if ( region ) {
						region.textContent = hint;
					}
				}

				// Let total notices replace the general payment hint.
				reconcileTotal( form, body, state );
			} )
			.catch( () =>
				showError( form, form.getAttribute( 'data-msg-error' ) || '' )
			)
			.finally( () => {
				state.busy = false;
				form.classList.remove( 'tiermint-checkout__form--busy' );
			} );
	}

	/**
	 * Confirm the Stripe intent and follow its return URL.
	 *
	 * @return {void}
	 */
	function confirmPayment() {
		state.busy = true;
		form.classList.add( 'tiermint-checkout__form--busy' );

		const confirmArgs = {
			elements: state.elements,
			confirmParams: { return_url: returnUrl( state.uuid ) },
		};
		const confirm =
			state.secret && 0 === state.secret.indexOf( 'seti_' )
				? state.stripe.confirmSetup( confirmArgs )
				: state.stripe.confirmPayment( confirmArgs );

		confirm
			.then( ( result ) => {
				// Successful Stripe confirmation redirects to the return URL.
				if ( result && result.error && result.error.message ) {
					showError( form, result.error.message );
				}
			} )
			.finally( () => {
				state.busy = false;
				form.classList.remove( 'tiermint-checkout__form--busy' );
			} );
	}
}

/**
 * Resolve the selected gateway from the submit control or page state.
 *
 * @param {SubmitEvent}     event The submit event.
 * @param {HTMLFormElement} form  The checkout form.
 * @return {string} The gateway id.
 */
function submittedGateway( event, form ) {
	if ( event.submitter && event.submitter.getAttribute( 'data-gateway' ) ) {
		return event.submitter.getAttribute( 'data-gateway' );
	}

	const root = form.closest( '[data-tiermint-checkout]' );
	const checked =
		root && root.querySelector( '[data-tiermint-gateway-radio]:checked' );
	if ( checked ) {
		return checked.value;
	}

	const region = root && root.querySelector( '.tiermint-checkout__gateway' );
	return region ? region.getAttribute( 'data-gateway' ) : 'stripe';
}

/**
 * Show the selected gateway region and hide the others.
 *
 * @param {HTMLElement} root The checkout root.
 * @return {void}
 */
function wireGatewayChooser( root ) {
	const radios = root.querySelectorAll( '[data-tiermint-gateway-radio]' );
	if ( ! radios.length ) {
		return;
	}

	radios.forEach( ( radio ) => {
		radio.addEventListener( 'change', () => {
			root.querySelectorAll( '.tiermint-checkout__gateway' ).forEach(
				( region ) => {
					region.hidden =
						region.getAttribute( 'data-gateway' ) !== radio.value;
				}
			);
		} );
	} );
}

/**
 * Focus company details when enabled and clear them when disabled.
 *
 * @param {HTMLElement} root The checkout root.
 * @return {void}
 */
function wireCompanyToggle( root ) {
	const toggle = root.querySelector( '#tiermint-checkout-company-toggle' );

	if ( ! toggle ) {
		return;
	}

	toggle.addEventListener( 'change', () => {
		if ( toggle.checked ) {
			const company = root.querySelector( '#tiermint-checkout-company' );
			if ( company ) {
				company.focus();
			}
			return;
		}

		[ '#tiermint-checkout-company', '#tiermint-checkout-vat' ].forEach(
			( selector ) => {
				const field = root.querySelector( selector );
				if ( field && field.value ) {
					field.value = '';
					// Re-price after removing the tax ID.
					field.dispatchEvent(
						new Event( 'change', { bubbles: true } )
					);
				}
			}
		);
	} );
}

/**
 * Enhance server-rendered discount apply and remove forms.
 * Persist each cart change before changing the applied state.
 *
 * @param {HTMLFormElement} form    The checkout form.
 * @param {Object}          session The shared per-form state.
 * @return {void}
 */
function wireDiscount( form, session ) {
	const root = form.closest( '[data-tiermint-checkout]' );

	if ( ! root ) {
		return;
	}

	const section = root.querySelector( '.tiermint-checkout__discount' );

	if ( ! section ) {
		return;
	}

	const input = section.querySelector( '#tiermint-checkout-discount' );
	const applyButton = section.querySelector(
		'[data-tiermint-discount-apply]'
	);
	const row = section.querySelector( '.tiermint-checkout__discount-row' );
	const chip = section.querySelector( '[data-tiermint-discount-chip]' );
	const chipLabel = section.querySelector(
		'[data-tiermint-discount-chip-label]'
	);
	const error = section.querySelector( '[data-tiermint-discount-error]' );

	if (
		! input ||
		! applyButton ||
		! row ||
		! chip ||
		! chipLabel ||
		! error
	) {
		return;
	}

	const removeButton = section.querySelector(
		'[data-tiermint-discount-remove]'
	);
	const applyForm = applyButton.closest( 'form' );
	const removeForm = removeButton ? removeButton.closest( 'form' ) : null;
	const appliedTemplate = section.getAttribute( 'data-msg-applied' ) || '';
	const failedText = section.getAttribute( 'data-msg-error' ) || '';

	/**
	 * Show or clear the inline error under the input.
	 *
	 * @param {string} message Text to show; empty clears and hides the node.
	 * @return {void}
	 */
	function setError( message ) {
		error.textContent = message || '';
		error.hidden = ! message;
	}

	/**
	 * Collapse the input row into the applied chip.
	 *
	 * @param {string} label The code to name in the chip.
	 * @return {void}
	 */
	function showApplied( label ) {
		chipLabel.textContent = appliedTemplate
			? appliedTemplate.replace( '%s', label )
			: label;
		row.hidden = true;
		chip.hidden = false;
		setError( '' );
	}

	/**
	 * Restore the input row and drop the applied state.
	 *
	 * @return {void}
	 */
	function showInput() {
		row.hidden = false;
		chip.hidden = true;
	}

	// Let intent reconciliation restore the discount input.
	session.showDiscountInput = showInput;

	/**
	 * Mark a discount request as busy for users and assistive tools.
	 *
	 * @param {HTMLButtonElement} button The button that triggered the request.
	 * @param {boolean}           on     Whether the request is in progress.
	 * @return {void}
	 */
	function setBusy( button, on ) {
		button.disabled = on;
		button.classList.toggle( 'is-busy', on );

		if ( on ) {
			section.setAttribute( 'aria-busy', 'true' );
		} else {
			section.removeAttribute( 'aria-busy' );
		}
	}

	/**
	 * Persist a discount form and detect its redirect result.
	 *
	 * @param {HTMLFormElement} target The apply or remove form.
	 * @return {Promise<boolean>} True when the cart was mutated.
	 */
	function persistForm( target ) {
		return fetch( target.action, {
			method: 'POST',
			// Send the cart cookie with the form request.
			credentials: 'same-origin',
			body: new FormData( target ),
		} )
			.then(
				( response ) =>
					! ( response.url || '' ).includes( ERROR_MARKER )
			)
			.catch( () => false );
	}

	// Restore input and persist removal for an invalid stored code.
	session.onDiscountInvalidated = ( message ) => {
		showInput();
		setError( message || failedText );

		if ( removeForm ) {
			persistForm( removeForm );
		}
	};

	/**
	 * Validate and persist a code before showing it as applied.
	 *
	 * @param {string} code The code to apply.
	 * @return {Promise<void>} Resolves once the outcome is rendered.
	 */
	function applyCode( code ) {
		// Prevent a field quote from superseding this code quote.
		if ( 'function' === typeof session.cancelQuote ) {
			session.cancelQuote();
		}

		setBusy( applyButton, true );

		return session.requestQuote( code ).then( ( answer ) => {
			// Leave the UI to the newer quote.
			if ( undefined === answer ) {
				setBusy( applyButton, false );
				return undefined;
			}

			// Do not apply a code when its quote fails.
			if ( ! answer ) {
				setBusy( applyButton, false );
				setError( failedText );
				return undefined;
			}

			const discount = answer.discount;

			if ( ! ( discount && true === discount.valid ) ) {
				// Keep the input available after a refused code.
				setBusy( applyButton, false );
				showInput();
				setError(
					discount && discount.message ? discount.message : failedText
				);
				return undefined;
			}

			// Keep the busy state until the cart update finishes.
			return persistForm( applyForm ).then( ( ok ) => {
				setBusy( applyButton, false );

				if ( ! ok ) {
					// Keep the typed code when cart persistence fails.
					setError( failedText );
					return;
				}

				showApplied( discount.code || code );
				input.value = '';
			} );
		} );
	}

	if ( applyForm ) {
		applyForm.addEventListener( 'submit', ( event ) => {
			event.preventDefault();

			// Payment intents own totals after submission.
			if (
				isCommitted( session ) ||
				'function' !== typeof session.requestQuote
			) {
				return;
			}

			const code = input.value.trim();

			if ( ! code ) {
				input.focus();
				return;
			}

			applyCode( code );
		} );
	}

	if ( removeForm ) {
		removeForm.addEventListener( 'submit', ( event ) => {
			event.preventDefault();

			if (
				isCommitted( session ) ||
				'function' !== typeof session.requestQuote
			) {
				return;
			}

			// Clear the hidden code before a payment can submit it.
			input.value = '';
			setError( '' );

			if ( 'function' === typeof session.cancelQuote ) {
				session.cancelQuote();
			}

			setBusy( removeButton, true );

			// Persist removal before the no-code quote reads the cart.
			persistForm( removeForm )
				.then( () => session.requestQuote( '' ) )
				.then( () => {
					setBusy( removeButton, false );
					showInput();
				} );
		} );
	}
}

const roots = document.querySelectorAll( '[data-tiermint-checkout]' );

roots.forEach( ( root ) => {
	const panel = root.querySelector( '.tiermint-checkout__confirmation' );
	if ( panel ) {
		pollStatus( panel );
	}

	wireGatewayChooser( root );
	wireCompanyToggle( root );

	const form = root.querySelector( '.tiermint-checkout__form' );
	if ( form ) {
		// Share state across quote, discount and payment handlers.
		const session = createSession();

		wireLiveQuote( form, session );
		wireDiscount( form, session );
		wireForm( form, session );
	}
} );
