<?php
/**
 * Starter checkout page pattern.
 *
 * The receipt block renders only after a checkout return.
 *
 * @package DrPlugin\WP\TierMint
 */

defined( 'ABSPATH' ) || exit;

return array(
	'title'       => __( 'Checkout page', 'tiermint' ),
	'description' => __( 'A complete checkout page: the TierMint payment form with its editable order summary, and the post-payment receipt.', 'tiermint' ),
	'blockTypes'  => array( 'tiermint/purchase-confirmation', 'tiermint/checkout' ),
	'content'     => \DrPlugin\WP\TierMint\Frontend\StorefrontPageContent::checkout(),
);
