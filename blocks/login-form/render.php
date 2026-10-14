<?php
/**
 * Renders the tiermint/login-form block through the shared adapter.
 *
 * @package DrPlugin\WP\TierMint
 */

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- component renderers return fully escaped HTML.
echo tiermint()->container()->get( DrPlugin\WP\TierMint\Frontend\Adapters\Blocks\Registrar::class )->renderBlock( 'login-form', isset( $attributes ) && is_array( $attributes ) ? $attributes : array() );
