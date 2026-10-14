/**
 * Which nav entry the current hash belongs to.
 *
 * Shared by the SPA and wp-admin menu. The longest segment prefix wins. The root
 * path matches only the root hash.
 */

/**
 * Internal dependencies
 */
import { splitHashQuery } from '../router/hashQuery';

/**
 * Split a hash into its non-empty path segments, query dropped.
 *
 * Ignore empty segments and query values before matching.
 *
 * @param {string} [hash] A raw or normalized hash.
 * @return {string[]} The path segments.
 */
export function hashSegments( hash ) {
	if ( ! hash ) {
		return [];
	}

	return splitHashQuery( hash )
		.path.replace( /^#/, '' )
		.split( '/' )
		.filter( ( segment ) => segment !== '' );
}

/**
 * Whether every segment of `candidate` opens `target`, in order.
 *
 * @param {string[]} candidate The candidate path's segments.
 * @param {string[]} target    The current hash's segments.
 * @return {boolean} True when candidate is a segment prefix of target.
 */
function isSegmentPrefix( candidate, target ) {
	if ( candidate.length > target.length ) {
		return false;
	}

	return candidate.every( ( segment, index ) => segment === target[ index ] );
}

/**
 * The path from `paths` that owns the screen at `hash`, or null.
 *
 * @param {string[]} paths The candidate paths (nav entries, submenu hashes).
 * @param {string}   hash  The current hash.
 * @return {string|null} The winning path, or null when none owns this hash.
 */
export function findActivePath( paths, hash ) {
	const target = hashSegments( hash );

	let best = null;
	let bestLength = -1;

	for ( const path of paths ) {
		const candidate = hashSegments( path );

		// The root path owns the root hash only. Without this it would own
		// every hash, including ones no screen answers for at all.
		if ( candidate.length === 0 ) {
			if ( target.length === 0 ) {
				return path;
			}
			continue;
		}

		if (
			isSegmentPrefix( candidate, target ) &&
			candidate.length > bestLength
		) {
			best = path;
			bestLength = candidate.length;
		}
	}

	return best;
}
