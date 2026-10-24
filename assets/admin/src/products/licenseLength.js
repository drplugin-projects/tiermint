/**
 * License-length helpers for the pricing panel.
 *
 * Preserve unrecognised legacy values as read-only custom values.
 */

/**
 * Storage grammar for a duration.
 *
 * @type {RegExp}
 */
const DURATION = /^\+(\d+) (day|week|month|year)s?$/;

/**
 * Parses a stored license length.
 *
 * @param {?string} raw Stored license length.
 * @return {{mode: string, count?: number, unit?: string, raw?: string}} Editor mode and values.
 */
export function parseLicenseLength( raw ) {
	if ( raw === null || raw === undefined ) {
		return { mode: 'lifetime' };
	}
	const match = DURATION.exec( raw );
	if ( match ) {
		return {
			mode: 'duration',
			count: Number( match[ 1 ] ),
			unit: match[ 2 ],
		};
	}
	return { mode: 'custom', raw };
}

/**
 * Build the storage grammar from a count and unit, always with a singular unit.
 *
 * @param {number} count The number of units.
 * @param {string} unit  One of `day`, `week`, `month`, `year`.
 * @return {string} The `+{n} {unit}` string.
 */
export function formatLicenseLength( count, unit ) {
	return `+${ count } ${ unit }`;
}
