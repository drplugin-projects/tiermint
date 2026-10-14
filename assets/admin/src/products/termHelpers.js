/**
 * Shared helpers for the category tree and tag field.
 */

/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Prefer validation errors to the API summary.
 *
 * @param {{message?: string, errors?: (Array|Object|null)}} error The error.
 * @return {string} The message to show inline.
 */
export function errorDetail( error ) {
	if ( error && error.errors ) {
		const messages = Array.isArray( error.errors )
			? error.errors
			: Object.values( error.errors ).flat();
		if ( messages.length > 0 ) {
			return messages.join( ' ' );
		}
	}
	return (
		( error && error.message ) ||
		__( 'The term could not be saved. Please try again.', 'tiermint' )
	);
}

/**
 * Normalize a term name for a case-insensitive, whitespace-trimmed comparison.
 *
 * @param {string} value The raw name.
 * @return {string} The comparison key.
 */
export function nameKey( value ) {
	return String( value ).trim().toLowerCase();
}
