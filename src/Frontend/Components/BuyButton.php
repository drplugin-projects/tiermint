<?php
/**
 * The single-product buy button component.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend\Components;

use DrPlugin\WP\TierMint\Discounts\AutomaticDiscountResolver;
use DrPlugin\WP\TierMint\Discounts\DiscountPersistenceException;
use DrPlugin\WP\TierMint\Discounts\DiscountSettings;
use DrPlugin\WP\TierMint\Frontend\AbstractComponent;
use DrPlugin\WP\TierMint\Frontend\CartActionListener;
use DrPlugin\WP\TierMint\Frontend\Components\Support\PriceDisplay;
use DrPlugin\WP\TierMint\Frontend\RenderContext;
use DrPlugin\WP\TierMint\Products\Product;
use DrPlugin\WP\TierMint\Products\ProductPrice;
use DrPlugin\WP\TierMint\Products\ProductService;
use DrPlugin\WP\TierMint\Products\ProductStatus;
use DrPlugin\WP\TierMint\Util\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a no-JavaScript add-to-cart form for one product tier.
 *
 * It renders only a published product with a valid purchasable tier.
 *
 * @since 1.0.0
 */
final class BuyButton extends AbstractComponent {

	/**
	 * The catalog service used to load the product and its price tiers.
	 *
	 * @var ProductService
	 */
	private ProductService $products;

	/**
	 * The money formatter for the displayed price.
	 *
	 * @var Money
	 */
	private Money $money;

	/**
	 * Resolves an automatic discount, when the Discounts domain is available.
	 *
	 * @var AutomaticDiscountResolver|null
	 */
	private ?AutomaticDiscountResolver $discounts;

	/**
	 * Reads the strikethrough setting when available.
	 *
	 * @var DiscountSettings|null
	 */
	private ?DiscountSettings $discountSettings;

	/**
	 * Wire the component with its catalog and money collaborators.
	 *
	 * @param ProductService                 $products The catalog service.
	 * @param Money                          $money    The money formatter.
	 * @param AutomaticDiscountResolver|null $discounts        The automatic-discount resolver for the strikethrough.
	 * @param DiscountSettings|null          $discountSettings The strikethrough display-toggle reader.
	 */
	public function __construct( ProductService $products, Money $money, ?AutomaticDiscountResolver $discounts = null, ?DiscountSettings $discountSettings = null ) {
		$this->products         = $products;
		$this->money            = $money;
		$this->discounts        = $discounts;
		$this->discountSettings = $discountSettings;
	}

	/**
	 * The component slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'buy-button';
	}

	/**
	 * The attribute schema adapters map their controls onto.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function schema(): array {
		return array(
			'product_id' => array(
				'type'        => 'integer',
				'default'     => 0,
				'required'    => true,
				'control'     => 'product_select',
				'label'       => __( 'Product', 'tiermint' ),
				'description' => __( 'The product to sell.', 'tiermint' ),
			),
			'price_id'   => array(
				'type'        => 'integer',
				'default'     => 0,
				'control'     => 'number',
				'label'       => __( 'Price tier', 'tiermint' ),
				'description' => __( 'A specific price tier id, or 0 for the product default tier.', 'tiermint' ),
			),
			'label'      => array(
				'type'    => 'string',
				'default' => __( 'Buy now', 'tiermint' ),
				'control' => 'text',
				'label'   => __( 'Button label', 'tiermint' ),
			),
			'show_price' => array(
				'type'    => 'boolean',
				'default' => true,
				'control' => 'toggle',
				'label'   => __( 'Show price', 'tiermint' ),
			),
		);
	}

	/**
	 * Render the buy button, or an empty string when the product is not buyable.
	 *
	 * @param array<string, mixed> $attrs Resolved attribute values.
	 * @param RenderContext        $ctx   Per-request front-end state (unused here).
	 * @return string Escaped HTML, or '' when nothing should render.
	 */
	public function render( array $attrs, RenderContext $ctx ): string {
		$productId = (int) $attrs['product_id'];
		$price     = $this->resolveBuyable( $productId, (int) $attrs['price_id'] );

		// Show a sample button only in an editor preview.
		if ( null === $price ) {
			return $ctx->isEditorPreview() ? $this->renderSample( $this->sampleData( $attrs ) ) : '';
		}

		$showPrice = (bool) $attrs['show_price'];

		return $this->renderTemplate(
			array(
				'action_url'   => remove_query_arg( 'tiermint_cart_error' ),
				'product_id'   => $productId,
				'price_id'     => (int) $price->id(),
				'quantity'     => $price->minQuantity(),
				'label'        => (string) $attrs['label'],
				'price_html'   => $showPrice ? $this->priceHtml( $productId, $price->amount() ) : '',
				'nonce_action' => CartActionListener::NONCE_ACTION,
				'nonce_field'  => CartActionListener::NONCE_FIELD,
			)
		);
	}

	/**
	 * Render the current price and any automatic discount.
	 *
	 * @param int $productId The product being sold.
	 * @param int $unit      The tier's unit price in minor units.
	 * @return string The escaped price markup.
	 */
	private function priceHtml( int $productId, int $unit ): string {
		return PriceDisplay::render( $this->money, $unit, $this->discountedAmount( $productId, $unit ), $this->strikeEnabled() );
	}

	/**
	 * Resolve an automatic discount, or keep the plain price on failure.
	 *
	 * @param int $productId The product id.
	 * @param int $unit      The tier's unit price in minor units.
	 * @return int|null The discounted unit price, or null for no discount.
	 */
	private function discountedAmount( int $productId, int $unit ): ?int {
		if ( null === $this->discounts || $productId <= 0 ) {
			return null;
		}

		try {
			$application = $this->discounts->resolveForCart(
				array(
					array(
						'product_id' => $productId,
						'subtotal'   => $unit,
					),
				),
				null
			);
		} catch ( DiscountPersistenceException $e ) {
			return null;
		}

		return null === $application ? null : $unit - $application->total();
	}

	/**
	 * Whether to show the original price.
	 *
	 * @return bool
	 */
	private function strikeEnabled(): bool {
		return null === $this->discountSettings || $this->discountSettings->strikethroughEnabled();
	}

	/**
	 * Resolve the purchasable tier for the block, or null when nothing is buyable
	 * (no product picked, product unpublished, or the tier is gone).
	 *
	 * @param int $productId The requested product id.
	 * @param int $priceId   The requested price tier id, or 0 for the default.
	 * @return ProductPrice|null
	 */
	private function resolveBuyable( int $productId, int $priceId ): ?ProductPrice {
		if ( $productId <= 0 ) {
			return null;
		}

		$product = $this->products->get( $productId );
		if ( null === $product || ProductStatus::Published !== $product->status() ) {
			return null;
		}

		return $this->resolvePrice( $product, $priceId );
	}

	/**
	 * Sample data for the editor preview.
	 *
	 * @param array<string, mixed> $attrs Resolved attribute values.
	 * @return array<string, mixed>
	 */
	private function sampleData( array $attrs ): array {
		return array(
			'action_url'   => remove_query_arg( 'tiermint_cart_error' ),
			'product_id'   => 0,
			'price_id'     => 0,
			'quantity'     => 1,
			'label'        => (string) $attrs['label'],
			'price_html'   => (bool) $attrs['show_price'] ? PriceDisplay::render( $this->money, 4900, null, $this->strikeEnabled() ) : '',
			'nonce_action' => CartActionListener::NONCE_ACTION,
			'nonce_field'  => CartActionListener::NONCE_FIELD,
		);
	}

	/**
	 * Resolve the price tier to sell.
	 *
	 * A selected tier must belong to the product and be purchasable.
	 *
	 * @param Product $product The product being sold.
	 * @param int     $priceId The requested price tier id, or 0 for the default.
	 * @return ProductPrice|null The resolved purchasable tier, or null when none applies.
	 */
	private function resolvePrice( Product $product, int $priceId ): ?ProductPrice {
		if ( $priceId <= 0 ) {
			return $product->defaultPrice();
		}

		foreach ( $product->prices() as $price ) {
			if ( $price->id() === $priceId && $price->isPurchasable() ) {
				return $price;
			}
		}

		return null;
	}
}
