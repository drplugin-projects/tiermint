<?php
/**
 * Starter account page pattern.
 *
 * Includes the account dashboard and a checkout return receipt.
 *
 * @package DrPlugin\WP\TierMint
 */

defined( 'ABSPATH' ) || exit;

return array(
	'title'       => __( 'Account page', 'tiermint' ),
	'description' => __( 'A complete customer account page: purchase receipt (after checkout), purchases, downloads, and license management.', 'tiermint' ),
	'blockTypes'  => array( 'tiermint/account-dashboard', 'tiermint/purchase-confirmation' ),
	'content'     => \DrPlugin\WP\TierMint\Frontend\StorefrontPageContent::account(),
);
