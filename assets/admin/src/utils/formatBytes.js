/**
 * Format a byte count as a short human-readable size.
 *
 * Invalid values display as `0 B`.
 *
 * @param {number|string} bytes The byte count.
 * @return {string} The formatted size, e.g. `1.5 KB`.
 */
export function formatBytes( bytes ) {
	const value = Number( bytes );

	if ( ! Number.isFinite( value ) || value <= 0 ) {
		return '0 B';
	}

	const units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
	let size = value;
	let unit = 0;

	while ( size >= 1024 && unit < units.length - 1 ) {
		size /= 1024;
		unit += 1;
	}

	// Bytes are whole. Larger units use one decimal place.
	const rounded =
		unit === 0 ? Math.round( size ) : Math.round( size * 10 ) / 10;

	return `${ rounded } ${ units[ unit ] }`;
}
