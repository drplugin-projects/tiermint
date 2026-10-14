<?php
/**
 * The TierMint Product Grid Elementor widget.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend\Adapters\Elementor\Widgets;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor widget for the product-grid component.
 *
 * @since 1.0.0
 */
final class ProductGridWidget extends ComponentWidget {

	/**
	 * The component slug this widget renders.
	 *
	 * @return string
	 */
	protected function componentSlug(): string {
		return 'product-grid';
	}

	/**
	 * The widget's editor title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'TierMint Product Grid', 'tiermint' );
	}

	/**
	 * The widget's editor icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-products';
	}
}
