<?php
/**
 * Value resolution behind the free Elementor dynamic tags.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend\Adapters\Elementor\Tags;

use DrPlugin\WP\TierMint\Products\ProductService;
use DrPlugin\WP\TierMint\Util\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves values for free Elementor product tags.
 *
 * The tag classes defer here because Elementor base classes are hard to unit test.
 *
 * @since 1.0.0
 */
class TagValues {

	/**
	 * The catalog service.
	 *
	 * @var ProductService
	 */
	private ProductService $products;

	/**
	 * The store-currency formatter.
	 *
	 * @var Money
	 */
	private Money $money;

	/**
	 * Wire the resolver's collaborators.
	 *
	 * @param ProductService $products The catalog service.
	 * @param Money          $money    The money formatter.
	 */
	public function __construct( ProductService $products, Money $money ) {
		$this->products = $products;
		$this->money    = $money;
	}

	/**
	 * The display name of a product, or '' when the id is empty or unknown.
	 *
	 * @param int $productId The product id.
	 * @return string
	 */
	public function productName( int $productId ): string {
		if ( $productId <= 0 ) {
			return '';
		}

		$product = $this->products->get( $productId );

		return null !== $product ? $product->name() : '';
	}

	/**
	 * Get the formatted default purchasable price, or an empty string.
	 *
	 * @param int $productId The product id.
	 * @return string
	 */
	public function productPrice( int $productId ): string {
		if ( $productId <= 0 ) {
			return '';
		}

		$product = $this->products->get( $productId );

		if ( null === $product ) {
			return '';
		}

		$price = $product->defaultPrice();

		return null !== $price ? $this->money->format( $price->amount() ) : '';
	}
}
