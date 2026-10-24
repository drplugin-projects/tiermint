<?php
/**
 * Renders the tiermint/product-grid block.
 *
 * @package DrPlugin\WP\TierMint
 */

defined( 'ABSPATH' ) || exit;

echo wp_kses(
	tiermint()->container()->get( DrPlugin\WP\TierMint\Frontend\Adapters\Blocks\Registrar::class )->renderBlock( 'product-grid', isset( $attributes ) && is_array( $attributes ) ? $attributes : array() ),
	DrPlugin\WP\TierMint\Frontend\Kses::allowed()
);
