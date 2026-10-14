/**
 * Reads the PHP boot object as a safe, complete shape.
 *
 * Keep the original `admin` object so later route registration uses the same
 * server namespace.
 */

/**
 * The default settings object used when the server prints none.
 *
 * @type {{currency: string}}
 */
const DEFAULT_SETTINGS = { currency: 'USD' };

/**
 * Read and normalize the TierMint admin boot data.
 *
 * @param {Object} [source] Explicit boot object, mainly for tests. Defaults to
 *                          `window.tierMint` when omitted.
 * @return {{apiRoot: string, nonce: string, caps: Object, settings: Object, features: Object, productFields: Object, catalog: Object, listSeparator: string, pro: boolean, admin: Object}}
 *         A fully populated, safe boot object.
 */
export function readBootData( source ) {
	const raw =
		source ||
		( typeof window !== 'undefined' ? window.tierMint : undefined ) ||
		{};

	// Keep the server's admin object by reference when it is one, so anything
	// assigned back onto it (for example a late `registerRoute`) is visible to
	// other bundles reading `window.tierMint.admin`.
	const admin = raw.admin && typeof raw.admin === 'object' ? raw.admin : {};
	if ( ! Array.isArray( admin.routes ) ) {
		admin.routes = [];
	}

	return {
		apiRoot: typeof raw.apiRoot === 'string' ? raw.apiRoot : '',
		nonce: typeof raw.nonce === 'string' ? raw.nonce : '',
		caps: raw.caps && typeof raw.caps === 'object' ? raw.caps : {},
		settings:
			raw.settings && typeof raw.settings === 'object'
				? raw.settings
				: { ...DEFAULT_SETTINGS },
		// The server prints a `features` map (recurring_tiers, release_channels)
		// the screens gate their Pro controls on; surface it so they never read
		// the global directly.
		features:
			raw.features && typeof raw.features === 'object'
				? raw.features
				: {},
		// The product editor reads the field/panel definitions from here; a
		// dropped key would silently blank the fields, so keep a safe shape.
		productFields:
			raw.productFields && typeof raw.productFields === 'object'
				? raw.productFields
				: { fields: [], panels: [] },
		// The settings dropdowns (currency, country, page) read the reference
		// catalogs from here so the screen needs no second fetch. Each list is
		// normalized on its own, so a partial catalog blanks one picker rather
		// than crashing the screen.
		catalog: normalizeCatalog( raw.catalog ),
		// What to put between the items of a list printed in a sentence or a
		// cell, localized by `wp_get_list_item_separator()`. `utils/list.js`
		// reads the global directly, because three of the places that print one
		// are handed props and never see this object; it is shaped here too so a
		// caller that does have it is not sent to the global.
		listSeparator:
			typeof raw.listSeparator === 'string' ? raw.listSeparator : ', ',
		pro: Boolean( raw.pro ),
		// The Pro upgrade copy and this user's dismissals. The server prints it
		// only when Pro is absent, so the surfaces read empty defaults otherwise.
		upsell: normalizeUpsell( raw.upsell ),
		admin,
	};
}

/**
 * Normalize the upsell block to a safe shape with defaulted fields.
 *
 * @param {*} raw The raw `upsell` value from the boot object.
 * @return {{url: string, intro: string, notice: string, linkLabel: string, ctaLabel: string, features: Array, dismissed: Array}}
 *         The safe upsell block.
 */
function normalizeUpsell( raw ) {
	const source = raw && typeof raw === 'object' ? raw : {};
	const text = ( value ) => ( typeof value === 'string' ? value : '' );

	const features = Array.isArray( source.features )
		? source.features.filter(
				( row ) =>
					row &&
					typeof row === 'object' &&
					typeof row.key === 'string' &&
					typeof row.title === 'string' &&
					typeof row.blurb === 'string'
		  )
		: [];

	const dismissed = Array.isArray( source.dismissed )
		? source.dismissed.filter( ( topic ) => typeof topic === 'string' )
		: [];

	return {
		url: text( source.url ),
		intro: text( source.intro ),
		notice: text( source.notice ),
		linkLabel: text( source.linkLabel ),
		ctaLabel: text( source.ctaLabel ),
		features,
		dismissed,
	};
}

/**
 * Normalize the reference-catalog block to three arrays, defaulting each list
 * independently.
 *
 * @param {*} raw The raw `catalog` value from the boot object.
 * @return {{countries: Array, currencies: Array, pages: Array}} The safe catalog.
 */
function normalizeCatalog( raw ) {
	const source = raw && typeof raw === 'object' ? raw : {};

	return {
		countries: Array.isArray( source.countries ) ? source.countries : [],
		currencies: Array.isArray( source.currencies ) ? source.currencies : [],
		pages: Array.isArray( source.pages ) ? source.pages : [],
	};
}
