<?php
/**
 * Shared sample content for editor previews.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend\Components\Support;

use DrPlugin\WP\TierMint\Orders\Cart;
use DrPlugin\WP\TierMint\Orders\CartLine;
use DrPlugin\WP\TierMint\Products\Product;
use DrPlugin\WP\TierMint\Products\ProductPrice;

defined( 'ABSPATH' ) || exit;

/**
 * Builds in-memory sample content for editor previews.
 *
 * @since 1.0.0
 */
final class SamplePreview {

	/**
	 * A two-tier product for purchase-form previews.
	 *
	 * @return Product
	 */
	public static function tierProduct(): Product {
		$prices = array(
			ProductPrice::fromArray(
				array(
					'id'               => 1,
					'name'             => __( 'Single site', 'tiermint' ),
					'amount'           => 4900,
					'activation_limit' => 1,
					'license_length'   => '+1 year',
					'sort_order'       => 0,
					'min_quantity'     => 1,
					'max_quantity'     => 1,
				)
			),
			ProductPrice::fromArray(
				array(
					'id'               => 2,
					'name'             => __( 'Five sites', 'tiermint' ),
					'amount'           => 9900,
					'activation_limit' => 5,
					'license_length'   => '+1 year',
					'sort_order'       => 1,
					'min_quantity'     => 1,
					'max_quantity'     => 1,
				)
			),
		);

		return Product::fromArray(
			array(
				'id'     => 0,
				'name'   => __( 'Sample product', 'tiermint' ),
				'slug'   => 'sample-product',
				'status' => 'published',
			),
			$prices
		);
	}

	/**
	 * A one-line cart for checkout previews.
	 *
	 * @return Cart
	 */
	public static function cart(): Cart {
		return new Cart(
			array(
				new CartLine( self::SAMPLE_PRODUCT_ID, 1, 1, 4900, __( 'Sample product', 'tiermint' ), __( 'Single site', 'tiermint' ) ),
			)
		);
	}

	/**
	 * An accessible SVG placeholder tile.
	 *
	 * @param string $css_class The CSS class the tile carries (matches the real image markup).
	 * @param string $label     The accessible label.
	 * @return string Inline SVG markup.
	 */
	public static function placeholderTile( string $css_class, string $label ): string {
		return sprintf(
			'<svg class="%1$s" role="img" aria-label="%2$s" viewBox="0 0 640 480" width="640" height="480" preserveAspectRatio="xMidYMid slice" xmlns="http://www.w3.org/2000/svg">'
			. '<rect width="640" height="480" fill="#eef1f5"/>'
			. '<circle cx="215" cy="175" r="52" fill="#d3d9e2"/>'
			. '<path d="M96 400l150-170 120 120 80-70 98 120z" fill="#d3d9e2"/>'
			. '</svg>',
			esc_attr( $css_class ),
			esc_attr( $label )
		);
	}

	/**
	 * Sentinel product ID for sample cart lines.
	 *
	 * @var int
	 */
	private const SAMPLE_PRODUCT_ID = 2147483647;
}
