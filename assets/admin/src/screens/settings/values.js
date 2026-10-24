/**
 * Pure helpers for the Settings screen.
 *
 * Keep settings payload and display rules outside React.
 */

/**
 * External dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { currencySymbol } from '../../utils/currency';

/**
 * Narrow a value to an array, defaulting a non-array to an empty one.
 *
 * @param {*} value The candidate.
 * @return {Array} The value when it is an array, otherwise `[]`.
 */
function asArray( value ) {
	return Array.isArray( value ) ? value : [];
}

/**
 * Build country options and retain a stored unavailable country.
 *
 * @param {Array<{code: string, name: string}>} countries The catalog countries.
 * @param {string}                              current   The stored country code, if any.
 * @return {Array<{value: string, label: string}>} The options.
 */
export function countryOptions( countries, current ) {
	const options = asArray( countries ).map( ( entry ) => ( {
		value: String( entry.code || '' ),
		label: String( entry.name || entry.code || '' ),
	} ) );

	const currentCode = String( current || '' ).toUpperCase();
	if (
		currentCode !== '' &&
		! options.some( ( option ) => option.value === currentCode )
	) {
		options.push( { value: currentCode, label: currentCode } );
	}

	return options;
}

/**
 * Build currency options and retain a stored unavailable currency.
 *
 * @param {Array<{code: string, label: string, symbol: string}>} currencies The catalog currencies.
 * @param {string}                                               current    The stored currency code, if any.
 * @return {Array<{value: string, label: string}>} The options.
 */
export function currencyOptions( currencies, current ) {
	const list = asArray( currencies );

	const options = list.map( ( entry ) => {
		const code = String( entry.code || '' ).toUpperCase();
		const name = String( entry.label || code );
		const symbol = currencySymbol( code, list ).trim();

		return {
			value: code,
			label: symbol && symbol !== code ? `${ name } (${ symbol })` : name,
		};
	} );

	const currentCode = String( current || '' ).toUpperCase();
	if (
		currentCode !== '' &&
		! options.some( ( option ) => option.value === currentCode )
	) {
		options.push( { value: currentCode, label: currentCode } );
	}

	return options;
}

/**
 * Build page options and retain a stored unavailable page.
 *
 * @param {Array<{id: number, title: string}>} pages   The catalog pages.
 * @param {number}                             current The stored page id (0 when unset).
 * @return {Array<{value: string, label: string}>} The options.
 */
export function pageOptions( pages, current ) {
	const options = [
		{ value: '0', label: __( 'Not set', 'tiermint' ) },
		...asArray( pages ).map( ( entry ) => ( {
			value: String( entry.id ),
			label: String( entry.title || entry.id ),
		} ) ),
	];

	const currentId = Number( current );
	if (
		Number.isInteger( currentId ) &&
		currentId > 0 &&
		! options.some( ( option ) => option.value === String( currentId ) )
	) {
		options.push( {
			value: String( currentId ),
			label: sprintf(
				/* translators: %d: the page's numeric id. */
				__( 'Page #%d', 'tiermint' ),
				currentId
			),
		} );
	}

	return options;
}

/**
 * Group fields by section without changing served order.
 *
 * @param {Array<{id: string, title: string}>}   sections Sections in served order.
 * @param {Array<{id: string, section: string}>} fields   Fields in served order.
 * @return {Array<{id: string, title: string, fields: Array}>} Each section with
 *         its fields attached, in served order.
 */
export function groupFieldsBySection( sections, fields ) {
	const list = Array.isArray( sections ) ? sections : [];
	const all = Array.isArray( fields ) ? fields : [];

	return list.map( ( section ) => ( {
		...section,
		fields: all.filter( ( field ) => field.section === section.id ),
	} ) );
}

/**
 * The sub-tab bucket a payment field belongs to: its `gateway` key, or `general`
 * when it has none.
 *
 * @param {{gateway?: string}} field A field definition.
 * @return {string} The bucket id.
 */
function gatewayOf( field ) {
	return field && field.gateway ? String( field.gateway ) : 'general';
}

/**
 * Group fields by gateway in first-appearance order.
 *
 * A group's label is the `gateway_label` of the first of its fields that has one.
 *
 * @param {Array<{gateway?: string, gateway_label?: string}>} fields The section's fields, in served order.
 * @return {Array<{id: string, label: ?string, fields: Array}>} The gateway groups, in order.
 */
export function partitionByGateway( fields ) {
	const all = Array.isArray( fields ) ? fields : [];
	const order = [];
	const buckets = {};
	const labels = {};

	all.forEach( ( field ) => {
		const id = gatewayOf( field );
		if ( ! buckets[ id ] ) {
			buckets[ id ] = [];
			labels[ id ] = null;
			order.push( id );
		}
		if (
			null === labels[ id ] &&
			typeof field.gateway_label === 'string' &&
			'' !== field.gateway_label
		) {
			labels[ id ] = field.gateway_label;
		}
		buckets[ id ].push( field );
	} );

	return order.map( ( id ) => ( {
		id,
		label: labels[ id ],
		fields: buckets[ id ],
	} ) );
}

/**
 * Build field groups in first-appearance order.
 *
 * @param {Array<{group?: string}>} fields The fields, in served order.
 * @return {Array<{group: ?string, fields: Array}>} The runs, in order.
 */
export function runsByGroup( fields ) {
	const all = Array.isArray( fields ) ? fields : [];
	const runs = [];
	// A bare object: a group named `constructor` or `toString` reads truthy off
	// Object.prototype and the run below is then never created.
	const byGroup = Object.create( null );

	all.forEach( ( field ) => {
		const group = field && field.group ? String( field.group ) : null;

		if ( null === group ) {
			runs.push( { group: null, fields: [ field ] } );
			return;
		}

		if ( ! byGroup[ group ] ) {
			byGroup[ group ] = { group, fields: [] };
			runs.push( byGroup[ group ] );
		}
		byGroup[ group ].fields.push( field );
	} );

	return runs;
}

/**
 * Get a field group label.
 *
 * @param {string} group The group key.
 * @return {string} The legend text.
 */
export function groupLabel( group ) {
	switch ( group ) {
		case 'test':
			return __( 'Test mode', 'tiermint' );
		case 'live':
			return __( 'Live mode', 'tiermint' );
		case 'store':
			return __( 'Store', 'tiermint' );
		case 'pages':
			return __( 'Pages', 'tiermint' );
		case 'frontend':
			return __( 'Front end', 'tiermint' );
		case 'checkout':
			return __( 'Checkout', 'tiermint' );
		case 'advanced':
			return __( 'Advanced', 'tiermint' );
		default:
			return String( group );
	}
}

/**
 * Get a gateway sub-tab label.
 *
 * @param {{id: string, label?: ?string}} group A group from partitionByGateway().
 * @return {string} The label to show on the sub-tab.
 */
export function gatewayLabel( group ) {
	if ( group.label ) {
		return group.label;
	}

	return 'general' === group.id
		? __( 'Other', 'tiermint' )
		: String( group.id );
}

/**
 * Build a settings payload from staged values.
 *
 * @param {Object<string, *>}              staged     Field id to its staged value.
 * @param {Object<string, {type: string}>} fieldsById Field definitions keyed by id.
 * @return {Object<string, *>} The minimal `values` payload for PUT /settings.
 */
export function buildPutValues( staged, fieldsById ) {
	const map = staged && typeof staged === 'object' ? staged : {};
	const defs = fieldsById && typeof fieldsById === 'object' ? fieldsById : {};
	const out = {};

	Object.keys( map ).forEach( ( id ) => {
		const value = map[ id ];
		const field = defs[ id ];

		if ( field && field.type === 'secret' ) {
			// `null` clears; a non-empty plaintext sets; empty string means the
			// user touched then emptied the input, which we treat as no change.
			if ( value === null ) {
				out[ id ] = null;
			} else if ( typeof value === 'string' && value !== '' ) {
				out[ id ] = value;
			}
			return;
		}

		out[ id ] = value;
	} );

	return out;
}

/**
 * Summarize a webhook endpoint's subscribed events for the table cell.
 *
 * @param {Array<string>} events The event names the endpoint subscribes to.
 * @return {string} A short human-readable summary.
 */
export function webhookEventsLabel( events ) {
	const count = Array.isArray( events ) ? events.length : 0;

	if ( count === 0 ) {
		return __( 'No events', 'tiermint' );
	}

	return sprintf(
		// translators: %d is the number of subscribed webhook events.
		_n( '%d event', '%d events', count, 'tiermint' ),
		count
	);
}

/**
 * Convert a REST error to display messages.
 *
 * @param {{message?: string, errors?: (Array|Object|null)}} error Normalized error.
 * @return {Array<string>} One or more messages to show the user.
 */
export function toMessages( error ) {
	const errors = error && error.errors;

	if ( Array.isArray( errors ) && errors.length > 0 ) {
		return errors.map( ( item ) => String( item ) );
	}

	if ( errors && typeof errors === 'object' ) {
		const flat = [];
		Object.keys( errors ).forEach( ( key ) => {
			const value = errors[ key ];
			if ( Array.isArray( value ) ) {
				value.forEach( ( item ) => flat.push( String( item ) ) );
			} else {
				flat.push( String( value ) );
			}
		} );
		if ( flat.length > 0 ) {
			return flat;
		}
	}

	if ( error && typeof error.message === 'string' && error.message !== '' ) {
		return [ error.message ];
	}

	return [ __( 'Something went wrong.', 'tiermint' ) ];
}
