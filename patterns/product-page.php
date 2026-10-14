<?php
/**
 * Starter product page pattern.
 *
 * Uses granular product blocks in the default reading order.
 *
 * @package DrPlugin\WP\TierMint
 */

defined( 'ABSPATH' ) || exit;

return array(
	'title'       => __( 'Product page', 'tiermint' ),
	'description' => __( 'A product details page built from the granular product blocks: image, overview, plans, description, screenshots, and meta.', 'tiermint' ),
	'blockTypes'  => array( 'tiermint/product-images', 'tiermint/product-overview', 'tiermint/product-pricing-tiers', 'tiermint/product-description', 'tiermint/product-screenshots', 'tiermint/product-meta' ),
	'content'     => \DrPlugin\WP\TierMint\Frontend\StorefrontPageContent::productDetails(),
);
