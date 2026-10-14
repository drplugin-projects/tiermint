/**
 * Thin REST client over @wordpress/api-fetch.
 *
 * Uses namespaced paths and normalized REST errors. `getList` also reads
 * pagination headers.
 */

/**
 * External dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

/**
 * The REST namespace every helper path is resolved under.
 *
 * @type {string}
 */
const NAMESPACE = '/tiermint/v1';

/**
 * Delay object URL cleanup until the browser starts the download.
 *
 * @type {number}
 */
const REVOKE_DELAY_MS = 60000;

/**
 * Prefix a helper path with the plugin namespace.
 *
 * @param {string} path The short path, e.g. `/products`.
 * @return {string} The namespaced path, e.g. `/tiermint/v1/products`.
 */
function namespaced( path ) {
	const normalized = path.startsWith( '/' ) ? path : `/${ path }`;
	return `${ NAMESPACE }${ normalized }`;
}

/**
 * Configures api-fetch.
 *
 * WordPress supplies the root URL. A missing nonce supports partial boot data.
 *
 * @param {{apiRoot?: string, nonce?: string}} boot The normalized boot object.
 * @return {void}
 */
export function configureApi( boot ) {
	if ( boot && boot.nonce ) {
		apiFetch.use( apiFetch.createNonceMiddleware( boot.nonce ) );
	}
}

/**
 * Append encoded query params to a REST path.
 *
 * Omit null values so callers can pass optional fields. Keep this dependency-free
 * for unit tests.
 *
 * @param {string} path    The REST path.
 * @param {Object} [query] A flat map of query params.
 * @return {string} The path with an encoded query string, when any.
 */
export function withQuery( path, query ) {
	if ( ! query || typeof query !== 'object' ) {
		return path;
	}

	const parts = Object.keys( query )
		.filter(
			( key ) => query[ key ] !== undefined && query[ key ] !== null
		)
		.map(
			( key ) =>
				`${ encodeURIComponent( key ) }=${ encodeURIComponent(
					query[ key ]
				) }`
		);

	if ( parts.length === 0 ) {
		return path;
	}

	const separator = path.includes( '?' ) ? '&' : '?';
	return `${ path }${ separator }${ parts.join( '&' ) }`;
}

/**
 * Normalize any thrown value into a flat, predictable error object.
 *
 * REST and network errors use the same shape.
 *
 * @param {*} error The raw rejected value.
 * @return {{code: string, message: string, status: number, errors: (Object|null)}}
 *         The normalized error.
 */
export function normalizeError( error ) {
	if ( ! error || typeof error !== 'object' ) {
		return {
			code: 'error',
			message: error
				? String( error )
				: __( 'An unknown error occurred.', 'tiermint' ),
			status: 0,
			errors: null,
		};
	}

	const data = error.data && typeof error.data === 'object' ? error.data : {};

	return {
		code: typeof error.code === 'string' ? error.code : 'error',
		message:
			typeof error.message === 'string' && error.message !== ''
				? error.message
				: __( 'Something went wrong.', 'tiermint' ),
		status: typeof data.status === 'number' ? data.status : 0,
		errors: data.errors || null,
	};
}

/**
 * Run an api-fetch request and normalize any rejection.
 *
 * @param {Object} options The api-fetch options object.
 * @return {Promise<*>} The parsed JSON body.
 */
async function request( options ) {
	try {
		return await apiFetch( options );
	} catch ( error ) {
		throw normalizeError( error );
	}
}

/**
 * Issue a GET request.
 *
 * @param {string} path    The REST path.
 * @param {Object} [query] Optional query params.
 * @return {Promise<*>} The parsed JSON body.
 */
export function get( path, query ) {
	return request( {
		path: withQuery( namespaced( path ), query ),
		method: 'GET',
	} );
}

/**
 * Issue a POST request.
 *
 * @param {string} path   The REST path.
 * @param {Object} [data] The request body.
 * @return {Promise<*>} The parsed JSON body.
 */
export function post( path, data ) {
	return request( { path: namespaced( path ), method: 'POST', data } );
}

/**
 * Issue a PUT request.
 *
 * @param {string} path   The REST path.
 * @param {Object} [data] The request body.
 * @return {Promise<*>} The parsed JSON body.
 */
export function put( path, data ) {
	return request( { path: namespaced( path ), method: 'PUT', data } );
}

/**
 * Issue a DELETE request.
 *
 * Named `del` because `delete` is a reserved word.
 *
 * @param {string} path   The REST path.
 * @param {Object} [data] Optional request body.
 * @return {Promise<*>} The parsed JSON body.
 */
export function del( path, data ) {
	return request( { path: namespaced( path ), method: 'DELETE', data } );
}

/**
 * Fetch a paginated list, exposing the REST pagination headers.
 *
 * Use the raw response to read pagination headers. Use safe defaults when they
 * are absent.
 *
 * @param {string} path    The REST path.
 * @param {Object} [query] Optional query params (page, per_page, filters).
 * @return {Promise<{items: Array, total: number, totalPages: number}>} List data and totals.
 */
export async function getList( path, query ) {
	let response;
	try {
		response = await apiFetch( {
			path: withQuery( namespaced( path ), query ),
			method: 'GET',
			parse: false,
		} );
	} catch ( error ) {
		throw normalizeError( error );
	}

	// With `parse: false` api-fetch does not check the status, so surface a
	// failed response as a normalized error the same way parsed calls do.
	if ( response && response.ok === false ) {
		let body = null;
		try {
			body = await response.json();
		} catch {
			body = null;
		}
		throw normalizeError( body );
	}

	const items = await response.json();
	const total = parseInt( response.headers.get( 'X-WP-Total' ), 10 );
	const totalPages = parseInt(
		response.headers.get( 'X-WP-TotalPages' ),
		10
	);

	// Fall back to the returned count when the total header is missing.
	const itemCount = Array.isArray( items ) ? items.length : 0;

	return {
		items,
		total: Number.isNaN( total ) ? itemCount : total,
		totalPages: Number.isNaN( totalPages ) ? 1 : totalPages,
	};
}

/**
 * Fetches a file and its headers.
 *
 * Uses api-fetch for nonce-protected downloads and short paths.
 *
 * @param {string} path    The short REST path, e.g. `/reports/sales/export`.
 * @param {Object} [query] Optional query params.
 * @return {Promise<{blob: Blob, header: Function}>} The body and a header reader.
 */
export async function getFile( path, query ) {
	let response;
	try {
		response = await apiFetch( {
			path: withQuery( namespaced( path ), query ),
			method: 'GET',
			parse: false,
		} );
	} catch ( error ) {
		// Read failed-export responses so the endpoint message reaches the user.
		throw new Error( await fileErrorMessage( error ) );
	}

	// Belt and braces for an api-fetch build that resolves a non-ok response
	// instead of rejecting it.
	if ( response && response.ok === false ) {
		throw new Error( await fileErrorMessage( response ) );
	}

	return {
		blob: await response.blob(),
		/**
		 * Read one response header.
		 *
		 * @param {string} name The header name.
		 * @return {?string} The header value, or null.
		 */
		header: ( name ) => response.headers.get( name ),
	};
}

/**
 * Read download errors from the response. Prefer validation details when present.
 *
 * @param {*} error The raw rejected value (usually a `Response`) or a non-ok response.
 * @return {Promise<string>} The message to show.
 */
async function fileErrorMessage( error ) {
	if ( error && typeof error.json === 'function' ) {
		let body = null;
		try {
			body = await error.json();
		} catch {
			body = null;
		}

		const normalized = normalizeError( body );
		const details = Array.isArray( normalized.errors )
			? normalized.errors.filter(
					( entry ) => typeof entry === 'string' && entry !== ''
			  )
			: [];

		return details.length > 0 ? details.join( ' ' ) : normalized.message;
	}

	return normalizeError( error ).message;
}

/**
 * Downloads a fetched file.
 *
 * Revokes the URL late so the browser can start the download.
 *
 * @param {Blob}   blob     The file body.
 * @param {string} filename The name to save it as.
 * @return {void}
 */
export function saveBlob( blob, filename ) {
	const url = URL.createObjectURL( blob );
	const anchor = document.createElement( 'a' );

	anchor.href = url;
	anchor.download = filename;
	anchor.style.display = 'none';
	anchor.click();

	setTimeout( () => URL.revokeObjectURL( url ), REVOKE_DELAY_MS );
}
