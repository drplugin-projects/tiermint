<?php
/**
 * Shared builder for the purchasable-tier selector and cart form data.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend\Components\Support;

use DrPlugin\WP\TierMint\Frontend\CartActionListener;
use DrPlugin\WP\TierMint\Products\Product;
use DrPlugin\WP\TierMint\Products\ProductPrice;
use DrPlugin\WP\TierMint\Util\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Builds tier and cart form data for product components.
 *
 * @since 1.0.0
 */
final class ProductTierSelector {

	/**
	 * Build purchase-form data for a product.
	 *
	 * @param Product                  $product             The product whose tiers to render.
	 * @param Money                    $money               The store-currency formatter.
	 * @param array<string, bool>|null $flags               The tier-meta display flags keyed by
	 *                                                      attribute name, or null to show every line.
	 * @param array<int, int>|null     $discountedByPriceId The discounted unit price per tier (price id => minor), or null.
	 * @param bool                     $strike              Whether the strikethrough display toggle is on.
	 * @return array{tiers: array<int, array{id: int, name: string, price_html: string, is_default: bool, min: int, max: int|null, fixed_single: bool, meta: array<int, string>}>, has_tiers: bool, quantity: array{show: bool, min: int, max: int|null, value: int}, product_id: int, action_url: string, nonce_action: string, nonce_field: string, add_label: string}
	 */
	public static function formData( Product $product, Money $money, ?array $flags = null, ?array $discountedByPriceId = null, bool $strike = true ): array {
		$tiers = self::purchasableTiers( $product, $money, $flags ?? TierMetaPresenter::flagsFrom( array() ), $discountedByPriceId, $strike );

		return array(
			'tiers'        => $tiers,
			'has_tiers'    => array() !== $tiers,
			'quantity'     => self::quantityData( $tiers[0] ?? null ),
			'product_id'   => (int) $product->id(),
			'action_url'   => remove_query_arg( 'tiermint_cart_error' ),
			'nonce_action' => CartActionListener::NONCE_ACTION,
			'nonce_field'  => CartActionListener::NONCE_FIELD,
			'add_label'    => __( 'Add to cart', 'tiermint' ),
		);
	}

	/**
	 * Build ordered rows for purchasable tiers.
	 *
	 * @param Product              $product             The product whose tiers to reduce.
	 * @param Money                $money               The store-currency formatter.
	 * @param array<string, bool>  $flags               The tier-meta display flags keyed by attribute name.
	 * @param array<int, int>|null $discountedByPriceId The discounted unit price per tier (price id => minor), or null.
	 * @param bool                 $strike              Whether the strikethrough display toggle is on.
	 * @return array<int, array{id: int, name: string, price_html: string, is_default: bool, min: int, max: int|null, fixed_single: bool, meta: array<int, string>}>
	 */
	private static function purchasableTiers( Product $product, Money $money, array $flags, ?array $discountedByPriceId, bool $strike ): array {
		$purchasable = array_values(
			array_filter(
				$product->prices(),
				static fn ( ProductPrice $price ): bool => $price->isPurchasable()
			)
		);

		usort(
			$purchasable,
			static function ( ProductPrice $a, ProductPrice $b ): int {
				return array( $a->sortOrder(), $a->id() ?? PHP_INT_MAX )
					<=> array( $b->sortOrder(), $b->id() ?? PHP_INT_MAX );
			}
		);

		$rows  = array();
		$first = true;

		foreach ( $purchasable as $price ) {
			$priceId = (int) $price->id();
			$rows[]  = array(
				'id'           => $priceId,
				'name'         => $price->name(),
				'price_html'   => PriceDisplay::render( $money, $price->amount(), $discountedByPriceId[ $priceId ] ?? null, $strike ),
				'is_default'   => $first,
				'min'          => $price->minQuantity(),
				'max'          => $price->maxQuantity(),
				'fixed_single' => $price->isFixedSingle(),
				'meta'         => TierMetaPresenter::lines( $price, $flags, $product->isLicensed() ),
			);
			$first   = false;
		}

		return $rows;
	}

	/**
	 * Build the quantity state for the default tier.
	 *
	 * @param array{min: int, max: int|null, fixed_single: bool}|null $defaultTier The default tier row, or null.
	 * @return array{show: bool, min: int, max: int|null, value: int}
	 */
	private static function quantityData( ?array $defaultTier ): array {
		if ( null === $defaultTier ) {
			return array(
				'show'  => false,
				'min'   => 1,
				'max'   => null,
				'value' => 1,
			);
		}

		return array(
			'show'  => ! $defaultTier['fixed_single'],
			'min'   => $defaultTier['min'],
			'max'   => $defaultTier['max'],
			'value' => $defaultTier['min'],
		);
	}
}
