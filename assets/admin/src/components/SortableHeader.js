/**
 * A sortable list column header.
 *
 * Keep `aria-sort` on the column header for screen readers.
 */

/**
 * External dependencies
 */
import { useMemo } from '@wordpress/element';

/**
 * Bind a list screen's sort params to the headers that change them.
 *
 * @param {Object}   options            The current sort state and its writer.
 * @param {string}   options.orderby    The column the list is sorted by.
 * @param {string}   options.order      Either `asc` or `desc`.
 * @param {Function} options.setFilters The screen's multi-param writer, from
 *                                      `useHashQuery`.
 * @return {{state: Function, toggle: Function}} What a header reads and calls.
 */
export function useSortableColumns( { orderby, order, setFilters } ) {
	return useMemo(
		() => ( {
			/**
			 * The `aria-sort` value for one column.
			 *
			 * @param {string} column The `orderby` value for the column.
			 * @return {string} 'ascending', 'descending', or 'none'.
			 */
			state( column ) {
				if ( orderby !== column ) {
					return 'none';
				}

				return order === 'asc' ? 'ascending' : 'descending';
			},

			/**
			 * Sort by a column, flipping the direction on a repeat click.
			 *
			 * @param {string} column The `orderby` value for the column.
			 * @return {void}
			 */
			toggle( column ) {
				if ( orderby === column ) {
					setFilters( { order: order === 'asc' ? 'desc' : 'asc' } );
					return;
				}

				setFilters( { orderby: column, order: 'asc' } );
			},
		} ),
		[ orderby, order, setFilters ]
	);
}

/**
 * Render a sortable column header.
 *
 * @param {Object} props             Component props.
 * @param {string} props.column      The `orderby` value this column sorts by.
 * @param {string} props.label       The visible header text.
 * @param {Object} props.sort        The binding from {@link useSortableColumns}.
 * @param {string} [props.className] A class for the `th`, for a column that
 *                                   already carries one.
 * @return {Element} The header cell.
 */
export default function SortableHeader( { column, label, sort, className } ) {
	// The checkbox column can otherwise make browsers infer the wrong header scope.
	return (
		<th
			scope="col"
			aria-sort={ sort.state( column ) }
			className={ className }
		>
			<button
				type="button"
				className="tiermint-sort"
				onClick={ () => sort.toggle( column ) }
			>
				{ label }
			</button>
		</th>
	);
}
