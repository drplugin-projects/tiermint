/**
 * Shared hierarchy helpers for product categories.
 */

/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Collect the ids of every descendant of a category.
 *
 * @param {Array}  list The full category list.
 * @param {number} id   The category whose descendants to collect.
 * @return {number[]} The descendant category ids.
 */
export function descendantIds( list, id ) {
	let ids = [];
	list.filter( ( term ) => term.parent === id ).forEach( ( child ) => {
		ids.push( child.id );
		ids = ids.concat( descendantIds( list, child.id ) );
	} );
	return ids;
}

/**
 * Build parent options without a category or its descendants.
 *
 * @param {Array}       list      The full category list.
 * @param {number|null} excludeId The category to exclude along with its
 *                                descendants, or null to include all.
 * @return {Array<{label: string, value: string}>} The select options.
 */
export function parentOptions( list, excludeId ) {
	const excluded = new Set(
		excludeId ? [ excludeId, ...descendantIds( list, excludeId ) ] : []
	);
	return [
		{ label: __( 'None (top level)', 'tiermint' ), value: '0' },
		...list
			.filter( ( term ) => ! excluded.has( term.id ) )
			.map( ( term ) => ( {
				label: term.name,
				value: String( term.id ),
			} ) ),
	];
}

/**
 * Build depth-first rows for a checkbox tree.
 *
 * Orphaned and cyclic terms stay visible at the top level.
 *
 * @param {Array} list The category list, each carrying `id` and `parent`.
 * @return {Array<{term: Object, depth: number}>} The rows, in display order.
 */
export function flattenTermTree( list ) {
	const known = new Set( list.map( ( term ) => term.id ) );
	const childrenOf = new Map();

	list.forEach( ( term ) => {
		const parent = Number( term.parent ) || 0;
		const key = known.has( parent ) ? parent : 0;
		const siblings = childrenOf.get( key );
		if ( siblings ) {
			siblings.push( term );
		} else {
			childrenOf.set( key, [ term ] );
		}
	} );

	const emitted = new Set();
	const rows = [];

	const walk = ( parentId, depth ) => {
		( childrenOf.get( parentId ) || [] ).forEach( ( term ) => {
			if ( emitted.has( term.id ) ) {
				return;
			}
			emitted.add( term.id );
			rows.push( { term, depth } );
			walk( term.id, depth + 1 );
		} );
	};
	walk( 0, 0 );

	list.forEach( ( term ) => {
		if ( ! emitted.has( term.id ) ) {
			emitted.add( term.id );
			rows.push( { term, depth: 0 } );
		}
	} );

	return rows;
}
