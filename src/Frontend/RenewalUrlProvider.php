<?php
/**
 * The storefront renewal-URL filter target.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend;

use DrPlugin\WP\TierMint\Core\Hookable;

defined( 'ABSPATH' ) || exit;

/**
 * Adds checkout renewal URLs to license responses.
 *
 * Keeps an earlier filtered URL unchanged.
 *
 * @since 1.0.0
 */
class RenewalUrlProvider implements Hookable {

	/**
	 * The storefront page resolver.
	 *
	 * @var PageResolver
	 */
	private PageResolver $pages;

	/**
	 * Wire the provider with the page resolver.
	 *
	 * @param PageResolver $pages The storefront page resolver.
	 */
	public function __construct( PageResolver $pages ) {
		$this->pages = $pages;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_filter( 'tiermint_license_renewal_url', array( $this, 'renewalUrl' ), 10, 2 );
	}

	/**
	 * Resolve the renewal URL for a license key.
	 *
	 * @param string|null $url        Value from earlier filters (null default).
	 * @param string      $licenseKey The license key being validated.
	 * @return string|null The renewal URL, or the incoming value.
	 */
	public function renewalUrl( ?string $url, string $licenseKey ): ?string {
		// Never clobber a URL an earlier filter already provided.
		if ( null !== $url ) {
			return $url;
		}

		$checkout = $this->pages->url( PageResolver::CHECKOUT );

		if ( null === $checkout ) {
			return $url;
		}

		return add_query_arg( 'tiermint_renew', $licenseKey, $checkout );
	}
}
