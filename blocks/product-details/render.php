<?php
/**
 * Renders the tiermint/product-details block.
 *
 * @package DrPlugin\WP\TierMint
 */

defined( 'ABSPATH' ) || exit;

$tiermint_details_html = tiermint()->container()->get( DrPlugin\WP\TierMint\Frontend\Adapters\Blocks\Registrar::class )->renderBlock( 'product-details', isset( $attributes ) && is_array( $attributes ) ? $attributes : array() );

if ( '' !== $tiermint_details_html ) {
	/* Keep the aligned block wrapper for wide and full layouts. */
	printf(
		'<div %1$s>%2$s</div>',
		get_block_wrapper_attributes(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() returns escaped attributes.
		wp_kses( $tiermint_details_html, DrPlugin\WP\TierMint\Frontend\Kses::allowed() )
	);
}
