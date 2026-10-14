/**
 * Access-denied panel.
 *
 * Handles denied hash deep links that are not shown in navigation.
 */

/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Render the access-denied panel.
 *
 * @return {Element} The access-denied panel element.
 */
export default function AccessDenied() {
	return (
		<section className="tiermint-admin-screen tiermint-admin-denied">
			<h1>{ __( 'Access denied', 'tiermint' ) }</h1>
			<p>
				{ __(
					'You do not have permission to view this screen.',
					'tiermint'
				) }
			</p>
		</section>
	);
}
