/**
 * The render state of a paginated list read.
 *
 * Keep list loading state consistent across screens.
 */

/**
 * Check whether an enabled first read is pending.
 *
 * @param {Object} query The React Query result for any read.
 * @return {boolean} True while a first read is owed and unresolved.
 */
export function firstReadPending( query ) {
	return Boolean( query && query.isPending ) && query.fetchStatus !== 'idle';
}

/**
 * Check whether a list query has renderable rows.
 *
 * @param {Object} query The React Query result for a `getList` read.
 * @return {boolean} True when `data.items` is an array to render.
 */
export function listInHand( query ) {
	return Boolean( query && query.data && Array.isArray( query.data.items ) );
}

/**
 * Reduce a list query to its render state.
 *
 * @param {Object} query The React Query result for a `getList` read.
 * @return {{inHand: boolean, items: Array, total: ?number, totalPages: number,
 *          waiting: boolean}} The list's render state.
 */
export function listState( query ) {
	const inHand = listInHand( query );
	const total = inHand ? query.data.total : undefined;

	return {
		inHand,
		items: inHand ? query.data.items : [],
		// A missing count is not an empty store.
		total: typeof total === 'number' ? total : null,
		// Pagination needs at least one page before the response arrives.
		totalPages: inHand ? query.data.totalPages || 1 : 1,
		waiting: ! inHand && firstReadPending( query ),
	};
}
