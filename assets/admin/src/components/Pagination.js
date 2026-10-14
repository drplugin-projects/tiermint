/**
 * Shared list pagination with page-jump buttons.
 */

/**
 * External dependencies
 */
import { useRef } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * How many pages fit before the range has to be collapsed.
 *
 * Below this count, no page range is hidden.
 *
 * @type {number}
 */
const MAX_INLINE = 7;

/**
 * How many pages either side of the current one stay visible.
 *
 * @type {number}
 */
const WINDOW = 2;

/**
 * The pages to offer, with nulls standing for the ranges left out.
 *
 * Keep a one-page gap visible instead of using an ellipsis.
 *
 * @param {number} page       The current page, 1-based.
 * @param {number} totalPages The total number of pages.
 * @return {Array<number|null>} Page numbers, with null for each elided range.
 */
export function pageWindow( page, totalPages ) {
	if ( totalPages <= MAX_INLINE ) {
		return Array.from( { length: totalPages }, ( _value, i ) => i + 1 );
	}

	const wanted = new Set( [ 1, totalPages ] );
	for ( let i = page - WINDOW; i <= page + WINDOW; i++ ) {
		if ( i >= 1 && i <= totalPages ) {
			wanted.add( i );
		}
	}

	const sorted = Array.from( wanted ).sort( ( a, b ) => a - b );
	const out = [];

	sorted.forEach( ( value, index ) => {
		const previous = sorted[ index - 1 ];

		if ( index > 0 && value - previous > 1 ) {
			out.push( value - previous === 2 ? previous + 1 : null );
		}

		out.push( value );
	} );

	return out;
}

/**
 * Render list pagination, or nothing when there is only one page.
 *
 * @param {Object}   props            Component props.
 * @param {number}   props.page       The current page, 1-based.
 * @param {number}   props.totalPages The total number of pages.
 * @param {?number}  [props.total]    The row count across all pages.
 * @param {boolean}  [props.pending]  Whether a list read is in flight.
 * @param {Function} props.onChange   Called with the page to move to.
 * @return {Element|null} The pagination element, or null.
 */
export default function Pagination( {
	page,
	totalPages,
	total,
	pending,
	onChange,
} ) {
	/* Keep answered counts while a page read is pending, so focus stays in the pager. */
	const settled = useRef( { totalPages: 0, total: undefined } );

	if ( ! pending ) {
		settled.current = { totalPages, total };
	}

	/* Use kept counts only for a page that they contain. */
	const fellBack =
		Boolean( pending ) &&
		( ! totalPages || totalPages <= 1 ) &&
		page <= settled.current.totalPages;
	const pages = fellBack ? settled.current.totalPages : totalPages;
	const count = fellBack ? settled.current.total : total;

	// One page needs no controls: two dead buttons and "Page 1 of 1" are noise
	// on exactly the screens that most need to look calm.
	if ( ! pages || pages <= 1 ) {
		return null;
	}

	const hasTotal = typeof count === 'number' && Number.isFinite( count );

	return (
		<div className="tiermint-pagination">
			{ hasTotal && (
				<span className="tiermint-pagination__count">
					{ sprintf(
						/* translators: %d: number of rows across every page. */
						_n( '%d item', '%d items', count, 'tiermint' ),
						count
					) }
				</span>
			) }

			{ /* The numbers say the position on screen; this says it to a screen
			     reader, which would otherwise have to count buttons to find out. */ }
			<span className="tiermint-pagination__status screen-reader-text">
				{ sprintf(
					/* translators: 1: current page number, 2: total number of pages. */
					__( 'Page %1$d of %2$d', 'tiermint' ),
					page,
					pages
				) }
			</span>

			<button
				type="button"
				className="button tiermint-pagination__button"
				disabled={ page <= 1 }
				onClick={ () => onChange( page - 1 ) }
			>
				{ __( 'Previous', 'tiermint' ) }
			</button>

			{ pageWindow( page, pages ).map( ( value, index ) => {
				if ( value === null ) {
					return (
						<span
							key={ `gap-${ index }` }
							className="tiermint-pagination__gap"
							aria-hidden="true"
						>
							…
						</span>
					);
				}

				const isCurrent = value === page;

				/* Keep the current page as a focusable, inert button. */
				return (
					<button
						key={ value }
						type="button"
						className={
							isCurrent
								? 'button tiermint-pagination__current'
								: 'button tiermint-pagination__page'
						}
						aria-current={ isCurrent ? 'page' : undefined }
						aria-disabled={ isCurrent || undefined }
						aria-label={ sprintf(
							/* translators: %d: page number. */
							__( 'Page %d', 'tiermint' ),
							value
						) }
						onClick={ () => {
							if ( ! isCurrent ) {
								onChange( value );
							}
						} }
					>
						{ value }
					</button>
				);
			} ) }

			<button
				type="button"
				className="button tiermint-pagination__button"
				disabled={ page >= pages }
				onClick={ () => onChange( page + 1 ) }
			>
				{ __( 'Next', 'tiermint' ) }
			</button>
		</div>
	);
}
