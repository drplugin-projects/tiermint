/**
 * Shared admin screen header.
 *
 * Use plain markup so the Dashboard does not load `@wordpress/components`.
 */

/**
 * Render a screen header.
 *
 * @param {Object}  props               Component props.
 * @param {string}  props.title         The screen title, rendered as the h1.
 * @param {string}  [props.description] One sentence under the title.
 * @param {Object}  [props.backLink]    An `{ href, label }` link to the list
 *                                      this screen was opened from.
 * @param {Element} [props.actions]     The screen's primary actions.
 * @return {Element} The header element.
 */
export default function ScreenHeader( {
	title,
	description,
	backLink,
	actions,
} ) {
	return (
		<div className="tiermint-screen-header">
			<div className="tiermint-screen-header__text">
				{ backLink && (
					<a
						className="tiermint-screen-header__back"
						href={ backLink.href }
					>
						{ backLink.label }
					</a>
				) }
				<h1>{ title }</h1>
				{ description && (
					<p className="tiermint-screen-header__description">
						{ description }
					</p>
				) }
			</div>
			{ actions && (
				<div className="tiermint-screen-header__actions">
					{ actions }
				</div>
			) }
		</div>
	);
}
