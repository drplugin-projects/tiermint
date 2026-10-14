/**
 * The one dismissible Pro notice, rendered inside the TierMint SPA.
 *
 * It lives in the SPA shell, not a wp-admin `admin_notices` banner, so it is
 * structurally incapable of appearing outside TierMint's own screens. One
 * topic, per user, dismissed once and gone.
 */

/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { post } from '../api/client';

/**
 * The only dismissal topic today.
 *
 * @type {string}
 */
const TOPIC = 'intro';

/**
 * The upsell banner.
 *
 * @param {Object} props      Component props.
 * @param {Object} props.boot The normalized boot object.
 * @return {Element|null} The banner, or null when it must not show.
 */
export default function UpsellBanner( { boot } ) {
	const upsell = boot && boot.upsell ? boot.upsell : {};
	const caps = boot && boot.caps ? boot.caps : {};
	const alreadyDismissed =
		Array.isArray( upsell.dismissed ) && upsell.dismissed.includes( TOPIC );

	const [ dismissed, setDismissed ] = useState( alreadyDismissed );

	// The #/pro screen is the full pitch, so the one-line banner above it is just
	// noise. Drop the query before comparing.
	const onUpgradeScreen = window.location.hash.split( '?' )[ 0 ] === '#/pro';

	// Only while Pro is absent, only for a user who can dismiss it (the dismiss
	// route is manage_tiermint-gated), and only until dismissed.
	if (
		( boot && boot.pro ) ||
		! caps.manage_tiermint ||
		dismissed ||
		! upsell.notice ||
		onUpgradeScreen
	) {
		return null;
	}

	/**
	 * Hide the banner and record the dismissal for this user.
	 *
	 * @return {void}
	 */
	const onDismiss = () => {
		setDismissed( true );
		// A failed write only means the banner returns on the next load.
		post( '/upsell/dismiss', { topic: TOPIC } ).catch( () => {} );
	};

	return (
		<div className="tiermint-upsell-banner" role="note">
			<p className="tiermint-upsell-banner__text">
				{ upsell.notice }{ ' ' }
				<a
					className="tiermint-upsell-banner__link"
					href={ upsell.url }
					target="_blank"
					rel="noopener noreferrer"
				>
					{ upsell.linkLabel || __( 'See TierMint Pro', 'tiermint' ) }
				</a>
			</p>
			<button
				type="button"
				className="button-link tiermint-upsell-banner__dismiss"
				onClick={ onDismiss }
			>
				{ __( 'Dismiss', 'tiermint' ) }
			</button>
		</div>
	);
}
