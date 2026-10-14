/**
 * Shared empty state for list screens.
 *
 * The status role announces a list that becomes empty after filtering.
 */

/**
 * Render an empty-list state.
 *
 * @param {Object}  props             Component props.
 * @param {string}  props.message     One plain sentence saying what will appear here.
 * @param {Element} [props.action]    The screen's primary action, when it has one.
 * @param {string}  [props.className] A variant class, for an empty state that
 *                                    sits inside a panel and must not draw a
 *                                    second surface inside the first.
 * @return {Element} The empty-state element.
 */
export default function EmptyState( { message, action, className } ) {
	const classes = [ 'tiermint-empty-state', className || '' ]
		.filter( Boolean )
		.join( ' ' );

	return (
		<div className={ classes } role="status">
			<p className="tiermint-empty-state__message">{ message }</p>
			{ action && (
				<div className="tiermint-empty-state__action">{ action }</div>
			) }
		</div>
	);
}
