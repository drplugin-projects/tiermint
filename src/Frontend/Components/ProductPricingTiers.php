<?php
/**
 * The product pricing tiers (purchase form) component.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend\Components;

use DrPlugin\WP\TierMint\Discounts\AutomaticDiscountResolver;
use DrPlugin\WP\TierMint\Discounts\DiscountSettings;
use DrPlugin\WP\TierMint\Frontend\Components\Support\ProductTierSelector;
use DrPlugin\WP\TierMint\Frontend\Components\Support\ResolvesTierDiscounts;
use DrPlugin\WP\TierMint\Frontend\Components\Support\SamplePreview;
use DrPlugin\WP\TierMint\Frontend\Components\Support\TierMetaPresenter;
use DrPlugin\WP\TierMint\Products\Product;
use DrPlugin\WP\TierMint\Products\ProductService;
use DrPlugin\WP\TierMint\Util\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the product tier selector and cart form.
 *
 * Uses the shared tier selector and cart contract.
 *
 * @since 1.0.0
 */
final class ProductPricingTiers extends AbstractProductComponent {

	use ResolvesTierDiscounts;

	/**
	 * The money formatter for each tier's price.
	 *
	 * @var Money
	 */
	private Money $money;

	/**
	 * Wire the catalog and money collaborators.
	 *
	 * @param ProductService                 $products The catalog service.
	 * @param Money                          $money    The money formatter.
	 * @param AutomaticDiscountResolver|null $discounts        The automatic-discount resolver for the strikethrough.
	 * @param DiscountSettings|null          $discountSettings The strikethrough display-toggle reader.
	 */
	public function __construct( ProductService $products, Money $money, ?AutomaticDiscountResolver $discounts = null, ?DiscountSettings $discountSettings = null ) {
		parent::__construct( $products );
		$this->money = $money;
		$this->setTierDiscountDeps( $discounts, $discountSettings );
	}

	/**
	 * The component slug (template name, asset-handle suffix, shortcode base).
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'product-pricing-tiers';
	}

	/**
	 * The purchase-form data for the template, under this block's class prefix.
	 *
	 * @param Product              $product The product to render.
	 * @param array<string, mixed> $attrs   Resolved attribute values (the tier-meta toggles).
	 * @return array<string, mixed>
	 */
	protected function viewData( Product $product, array $attrs ): array {
		return array( 'block' => 'tiermint-product-pricing-tiers' ) + ProductTierSelector::formData(
			$product,
			$this->money,
			TierMetaPresenter::flagsFrom( $attrs ),
			$this->tierDiscountMap( $product ),
			$this->tierStrikeEnabled()
		);
	}

	/**
	 * The block is degenerate when the product has no purchasable tier.
	 *
	 * @param array<string, mixed> $data The reduced view data.
	 * @return bool
	 */
	protected function viewDataIsEmpty( array $data ): bool {
		return empty( $data['has_tiers'] );
	}

	/**
	 * Sample purchase form for the editor.
	 *
	 * @param Product              $product The resolved product (unused; the sample supplies tiers).
	 * @param array<string, mixed> $attrs   Resolved attribute values (the tier-meta toggles).
	 * @return array<string, mixed>
	 */
	protected function sampleViewData( Product $product, array $attrs ): array {
		unset( $product );

		return array( 'block' => 'tiermint-product-pricing-tiers' )
			+ ProductTierSelector::formData( SamplePreview::tierProduct(), $this->money, TierMetaPresenter::flagsFrom( $attrs ) );
	}

	/**
	 * The attribute schema: the routed product plus the tier-meta display toggles.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function schema(): array {
		return parent::schema() + TierMetaPresenter::toggleSchema();
	}
}
