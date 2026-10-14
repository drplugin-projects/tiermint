<?php
/**
 * Starter two-column product page pattern.
 *
 * Uses the combined product-details block beside its purchase panel.
 *
 * @package DrPlugin\WP\TierMint
 */

defined( 'ABSPATH' ) || exit;

return array(
	'title'       => __( 'Product page (two-column)', 'tiermint' ),
	'description' => __( 'A product details page built from the all-in-one block: a wide two-column hero (media beside the buying panel) with the product meta below.', 'tiermint' ),
	'blockTypes'  => array( 'tiermint/product-details', 'tiermint/product-meta' ),
	'content'     => \DrPlugin\WP\TierMint\Frontend\StorefrontPageContent::productDetailsTwoColumn(),
);
