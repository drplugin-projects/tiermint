/**
 * How to write a list of things out in one line.
 *
 * Use WordPress's localized list separator.
 */

/**
 * The separator to put between the items of a written-out list.
 *
 * @return {string} The localized separator, or a comma and a space when the
 *                  page printed no boot payload.
 */
export function listSeparator() {
	const boot = typeof window !== 'undefined' ? window.tierMint : undefined;

	return boot && typeof boot.listSeparator === 'string'
		? boot.listSeparator
		: ', ';
}

/**
 * Join items into one line with the localized separator.
 *
 * @param {Array} items The items, already in display form.
 * @return {string} The joined line.
 */
export function joinList( items ) {
	return ( Array.isArray( items ) ? items : [] ).join( listSeparator() );
}
