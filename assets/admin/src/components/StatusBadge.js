/**
 * A status badge.
 *
 * Normalize status values for consistent CSS modifiers.
 */

/**
 * Reduce a status value to a CSS-safe modifier suffix.
 *
 * @param {string} status The raw status value.
 * @return {string} The normalized suffix, or '' when there is nothing to add.
 */
function modifierFor( status ) {
	return String( status || '' )
		.toLowerCase()
		.replace( /[^a-z0-9]+/g, '-' )
		.replace( /^-+|-+$/g, '' );
}

/**
 * Render a status pill.
 *
 * @param {Object} props             Component props.
 * @param {string} props.status      The status value driving the modifier class.
 * @param {string} [props.label]     The visible text; defaults to the status.
 * @param {string} [props.className] An extra class the screen needs to keep.
 * @return {Element} The badge element.
 */
export default function StatusBadge( { status, label, className } ) {
	const modifier = modifierFor( status );
	const classes = [
		'tiermint-badge',
		modifier ? `tiermint-badge--${ modifier }` : '',
		className || '',
	]
		.filter( Boolean )
		.join( ' ' );

	return <span className={ classes }>{ label ?? status }</span>;
}
