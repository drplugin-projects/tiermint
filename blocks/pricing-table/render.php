<?php
/**
 * Renders the tiermint/pricing-table block through the shared adapter.
 *
 * @package DrPlugin\WP\TierMint
 */

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- component renderers return fully escaped HTML.
echo tiermint()->container()->get( DrPlugin\WP\TierMint\Frontend\Adapters\Blocks\Registrar::class )->renderBlock( 'pricing-table', isset( $attributes ) && is_array( $attributes ) ? $attributes : array() );
