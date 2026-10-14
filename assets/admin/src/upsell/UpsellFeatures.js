/**
 * The Pro feature list, shared by the upgrade screen and the settings teaser.
 *
 * Presentational only. Every link is the bare product page, in a new tab, with
 * no referrer so it tracks nothing.
 */

/**
 * Render the feature cards.
 *
 * @param {Object}                                             props           Component props.
 * @param {Array<{key: string, title: string, blurb: string}>} props.features  The feature rows.
 * @param {string}                                             props.url       The product page URL.
 * @param {string}                                             props.linkLabel The link label.
 * @return {Element|null} The list, or null when there are no features.
 */
export default function UpsellFeatures( { features, url, linkLabel } ) {
	if ( ! Array.isArray( features ) || features.length === 0 ) {
		return null;
	}

	return (
		<ul className="tiermint-upsell__features">
			{ features.map( ( feature ) => (
				<li key={ feature.key } className="tiermint-upsell__feature">
					<h3 className="tiermint-upsell__feature-title">
						{ feature.title }
					</h3>
					<p className="tiermint-upsell__feature-blurb">
						{ feature.blurb }
					</p>
					<a
						className="tiermint-upsell__feature-link"
						href={ url }
						target="_blank"
						rel="noopener noreferrer"
					>
						{ linkLabel }
					</a>
				</li>
			) ) }
		</ul>
	);
}
