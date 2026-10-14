/**
 * The human labels for an order status.
 *
 * Shared labels for order statuses.
 */

/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Map an order status slug to its human label.
 *
 * Unknown statuses use their raw slug.
 *
 * @param {string} status The order status slug.
 * @return {string} The translated label, or the slug itself.
 */
export function orderStatusLabel( status ) {
	const map = {
		pending: __( 'Pending', 'tiermint' ),
		completed: __( 'Completed', 'tiermint' ),
		failed: __( 'Failed', 'tiermint' ),
		abandoned: __( 'Abandoned', 'tiermint' ),
		refunded: __( 'Refunded', 'tiermint' ),
		partially_refunded: __( 'Partially refunded', 'tiermint' ),
	};
	return map[ status ] || status;
}
