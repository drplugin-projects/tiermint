<?php
/**
 * Starter pricing section pattern.
 *
 * Select a product for the pricing table after inserting this pattern.
 *
 * @package DrPlugin\WP\TierMint
 */

defined( 'ABSPATH' ) || exit;

return array(
	'title'       => __( 'Pricing section', 'tiermint' ),
	'description' => __( 'A heading, supporting copy, and a product pricing table. Select the product in the block settings after inserting.', 'tiermint' ),
	'blockTypes'  => array( 'tiermint/pricing-table' ),
	'content'     => '<!-- wp:heading {"textAlign":"center"} -->
<h2 class="wp-block-heading has-text-align-center">' . esc_html__( 'Simple pricing', 'tiermint' ) . '</h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"align":"center"} -->
<p class="has-text-align-center">' . esc_html__( 'Every plan includes updates and support for one year.', 'tiermint' ) . '</p>
<!-- /wp:paragraph -->

<!-- wp:tiermint/pricing-table /-->',
);
