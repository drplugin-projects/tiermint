<?php
/**
 * Renders the tiermint/license-manager block through the shared adapter.
 *
 * @package DrPlugin\WP\TierMint
 */

defined( 'ABSPATH' ) || exit;

echo wp_kses(
	tiermint()->container()->get( DrPlugin\WP\TierMint\Frontend\Adapters\Blocks\Registrar::class )->renderBlock( 'license-manager', isset( $attributes ) && is_array( $attributes ) ? $attributes : array() ),
	DrPlugin\WP\TierMint\Frontend\Kses::allowed()
);
