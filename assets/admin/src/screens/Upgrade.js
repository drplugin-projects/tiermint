/**
 * The #/pro upgrade screen: what the separate Pro add-on adds.
 *
 * Static content from the boot object. No REST read, no server data. The route
 * is hidden while Pro is active, so a stale deep link falls through to
 * not-found.
 */

/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import UpsellFeatures from '../upsell/UpsellFeatures';

/**
 * The upgrade screen.
 *
 * @param {Object} props      Component props.
 * @param {Object} props.boot The normalized boot object.
 * @return {Element} The screen element.
 */
function Upgrade( { boot } ) {
	const upsell =
		boot && boot.upsell
			? boot.upsell
			: {
					url: '',
					intro: '',
					linkLabel: '',
					ctaLabel: '',
					features: [],
			  };
	const linkLabel = upsell.linkLabel || __( 'See TierMint Pro', 'tiermint' );

	return (
		<section className="tiermint-admin-screen tiermint-upsell">
			<div className="tiermint-upsell__header">
				<h1>{ __( 'TierMint Pro', 'tiermint' ) }</h1>
				{ upsell.url && (
					<a
						className="button button-primary tiermint-upsell__cta"
						href={ upsell.url }
						target="_blank"
						rel="noopener noreferrer"
					>
						{ upsell.ctaLabel || linkLabel }
					</a>
				) }
			</div>

			{ upsell.intro && (
				<p className="tiermint-upsell__intro">{ upsell.intro }</p>
			) }

			<UpsellFeatures
				features={ upsell.features }
				url={ upsell.url }
				linkLabel={ linkLabel }
			/>
		</section>
	);
}

/**
 * Route registry entry for the upgrade screen. Hidden while Pro is active.
 *
 * @type {{path: string, title: string, capability: string, component: Function, hideWhenPro: boolean}}
 */
export const route = {
	path: '#/pro',
	title: __( 'TierMint Pro', 'tiermint' ),
	capability: 'manage_tiermint',
	component: Upgrade,
	hideWhenPro: true,
};

export default Upgrade;
