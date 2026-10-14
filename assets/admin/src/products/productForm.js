/**
 * Shared product-editor form helpers.
 */

/**
 * Parse a value to a non-negative integer, defaulting to 0.
 *
 * @param {string} value The value as typed.
 * @return {number} The parsed integer, or 0.
 */
export function toInt( value ) {
	const number = parseInt( value, 10 );
	return Number.isFinite( number ) ? number : 0;
}

/**
 * Get the initial state for a new price row.
 *
 * @return {Object} A fresh price row.
 */
export function blankPrice() {
	return {
		name: '',
		amount: '',
		activation_limit: 0,
		// Null represents a lifetime license.
		license_length: null,
		is_subscription: false,
		billing_period: 'month',
		billing_interval: 1,
		trial_days: 0,
		min_quantity: 1,
		max_quantity: 0,
		status: 'active',
	};
}

/**
 * Add newly minted server IDs to aligned price rows.
 *
 * Do not assign IDs when the current and loaded lists no longer align.
 *
 * @param {Array} prices The tiers on screen.
 * @param {Array} loaded The tiers the server just sent back.
 * @param {Array} saved  The tiers as they stood at the last save or load.
 * @return {Array} The tiers, with ids where they were missing.
 */
function withServerPriceIds( prices, loaded, saved ) {
	if (
		! Array.isArray( prices ) ||
		! Array.isArray( loaded ) ||
		prices.length !== loaded.length
	) {
		return prices;
	}

	const aligned = prices.every(
		( price, index ) => ! price.id || price.id === loaded[ index ].id
	);

	if ( ! aligned ) {
		return prices;
	}

	const known = new Set(
		( Array.isArray( saved ) ? saved : [] )
			.map( ( price ) => price.id )
			.filter( Boolean )
	);

	return prices.map( ( price, index ) =>
		price.id || ! loaded[ index ].id || known.has( loaded[ index ].id )
			? price
			: { ...price, id: loaded[ index ].id }
	);
}

/**
 * Merge loaded product data with unsaved form changes.
 *
 * Preserve locally changed fields and apply server-created price IDs.
 *
 * @param {Object} loaded  The form built from the server's copy.
 * @param {Object} current The form on screen.
 * @param {Object} saved   The form as it stood at the last save or load.
 * @return {{form: Object, snapshot: Object}} The form to render, and what
 *         counts as saved once it is on screen.
 */
export function reconcileLoadedProduct( loaded, current, saved ) {
	const form = {};
	const snapshot = {};

	Object.keys( loaded ).forEach( ( key ) => {
		const typedSince =
			JSON.stringify( current[ key ] ) !== JSON.stringify( saved[ key ] );

		form[ key ] = typedSince ? current[ key ] : loaded[ key ];
		snapshot[ key ] = typedSince ? saved[ key ] : loaded[ key ];
	} );

	form.prices = withServerPriceIds(
		form.prices,
		loaded.prices,
		saved.prices
	);
	snapshot.prices = withServerPriceIds(
		snapshot.prices,
		loaded.prices,
		saved.prices
	);

	return { form, snapshot };
}
