<?php
/**
 * Renders the tiermint/buy-button block through the shared adapter.
 *
 * @package DrPlugin\WP\TierMint
 */

defined( 'ABSPATH' ) || exit;

echo wp_kses(
	tiermint()->container()->get( DrPlugin\WP\TierMint\Frontend\Adapters\Blocks\Registrar::class )->renderBlock( 'buy-button', isset( $attributes ) && is_array( $attributes ) ? $attributes : array() ),
	DrPlugin\WP\TierMint\Frontend\Kses::allowed()
);
