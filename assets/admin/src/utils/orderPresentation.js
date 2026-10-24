/**
 * Shared presentation helpers for order rows.
 *
 * Keep order presentation consistent across admin screens.
 */

/**
 * External dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { joinList } from './list';

/**
 * Human label for an order type, falling back to the raw value.
 *
 * @param {string} type The order type slug.
 * @return {string} A display label.
 */
export function orderTypeLabel( type ) {
	const map = {
		purchase: __( 'Purchase', 'tiermint' ),
		renewal: __( 'Renewal', 'tiermint' ),
		refund: __( 'Refund', 'tiermint' ),
	};
	return map[ type ] || type;
}

/**
 * Format the purchased products in one line.
 *
 * @param {Array} items The order's line items.
 * @return {string} The products written out, or an empty string.
 */
export function orderProductSummary( items ) {
	return joinList(
		( Array.isArray( items ) ? items : [] ).map( ( item ) =>
			item.price_name
				? sprintf(
						/* translators: 1: a primary label, 2: a secondary detail shown in parentheses. */
						__( '%1$s (%2$s)', 'tiermint' ),
						item.product_name,
						item.price_name
				  )
				: item.product_name
		)
	);
}

/**
 * Format an ISO timestamp as a locale date, leaving an empty cell for a
 * missing value.
 *
 * @param {?string} iso The ISO 8601 timestamp, or a falsy value.
 * @return {string} A locale date string, or an empty string.
 */
export function formatOrderDate( iso ) {
	if ( ! iso ) {
		return '';
	}
	const date = new Date( iso );
	return Number.isNaN( date.getTime() ) ? iso : date.toLocaleDateString();
}
