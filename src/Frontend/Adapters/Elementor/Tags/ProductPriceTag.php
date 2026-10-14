<?php
/**
 * The product-price dynamic tag.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend\Adapters\Elementor\Tags;

defined( 'ABSPATH' ) || exit;

/**
 * Outputs the selected product's default price.
 *
 * @since 1.0.0
 */
final class ProductPriceTag extends AbstractProductTag {

	/**
	 * The stable dynamic-tag name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'tiermint-product-price';
	}

	/**
	 * The tag's editor title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'TierMint Product Price', 'tiermint' );
	}

	/**
	 * Render the product's default-tier price.
	 *
	 * @return void
	 */
	public function render(): void {
		echo esc_html( self::values()->productPrice( $this->productId() ) );
	}
}
