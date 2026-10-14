/**
 * Shows a clear page when a hash has no route.
 */

/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Render the not-found panel.
 *
 * @return {Element} The not-found panel element.
 */
export default function NotFound() {
	return (
		<section className="tiermint-admin-screen tiermint-admin-notfound">
			<h1>{ __( 'Page not found', 'tiermint' ) }</h1>
			<p>
				{ __(
					'The screen you requested could not be found.',
					'tiermint'
				) }
			</p>
		</section>
	);
}
