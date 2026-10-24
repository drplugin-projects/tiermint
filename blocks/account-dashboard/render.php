<?php
/**
 * Renders the tiermint/account-dashboard block through the shared adapter.
 *
 * @package DrPlugin\WP\TierMint
 */

defined( 'ABSPATH' ) || exit;

$tiermint_account_html = tiermint()->container()->get( DrPlugin\WP\TierMint\Frontend\Adapters\Blocks\Registrar::class )->renderBlock( 'account-dashboard', isset( $attributes ) && is_array( $attributes ) ? $attributes : array() );

if ( '' !== $tiermint_account_html ) {
	/* Keeps the aligned layout outside the theme content width. */
	printf(
		'<div %1$s>%2$s</div>',
		get_block_wrapper_attributes(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() returns escaped attributes.
		wp_kses( $tiermint_account_html, DrPlugin\WP\TierMint\Frontend\Kses::allowed() )
	);
}
