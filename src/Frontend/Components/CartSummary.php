<?php
/**
 * The cart-summary front-end component.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend\Components;

use DrPlugin\WP\TierMint\Discounts\DiscountComposer;
use DrPlugin\WP\TierMint\Discounts\DiscountService;
use DrPlugin\WP\TierMint\Frontend\AbstractComponent;
use DrPlugin\WP\TierMint\Frontend\Components\Support\SamplePreview;
use DrPlugin\WP\TierMint\Frontend\Components\Support\StoredDiscountPreview;
use DrPlugin\WP\TierMint\Frontend\Components\Support\TierMetaPresenter;
use DrPlugin\WP\TierMint\Frontend\RenderContext;
use DrPlugin\WP\TierMint\Orders\Cart;
use DrPlugin\WP\TierMint\Orders\CartLine;
use DrPlugin\WP\TierMint\Orders\CartService;
use DrPlugin\WP\TierMint\Orders\OrderService;
use DrPlugin\WP\TierMint\Orders\PricingMode;
use DrPlugin\WP\TierMint\Products\ProductPrice;
use DrPlugin\WP\TierMint\Products\ProductService;
use DrPlugin\WP\TierMint\Util\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the cart, its actions and its checkout link.
 *
 * @since 1.0.0
 */
class CartSummary extends AbstractComponent {

	/**
	 * The cart domain service read at render time.
	 *
	 * @var CartService
	 */
	private CartService $cart;

	/**
	 * Resolves live tiers for quantity limits and plan details.
	 *
	 * @var ProductService
	 */
	private ProductService $products;

	/**
	 * The store's money formatter.
	 *
	 * @var Money
	 */
	private Money $money;

	/**
	 * Prices stored discount codes for display when available.
	 *
	 * @var DiscountService|null
	 */
	private ?DiscountService $discounts;

	/**
	 * Combines code and automatic discounts when available.
	 *
	 * @var DiscountComposer|null
	 */
	private ?DiscountComposer $discountComposer;

	/**
	 * Wire the component's collaborators.
	 *
	 * @param CartService           $cart             The cart domain service.
	 * @param ProductService        $products         Unused; see property docblock.
	 * @param Money                 $money            The store's money formatter.
	 * @param DiscountService|null  $discounts        Prices the cart's stored discount code for display.
	 * @param DiscountComposer|null $discountComposer Folds the automatic discount into the display.
	 */
	public function __construct( CartService $cart, ProductService $products, Money $money, ?DiscountService $discounts = null, ?DiscountComposer $discountComposer = null ) {
		$this->cart             = $cart;
		$this->products         = $products;
		$this->money            = $money;
		$this->discounts        = $discounts;
		$this->discountComposer = $discountComposer;
	}

	/**
	 * Unique component identifier.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'cart-summary';
	}

	/**
	 * Machine-readable attribute definitions.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function schema(): array {
		return array(
			'show_actions'      => array(
				'type'        => 'boolean',
				'default'     => true,
				'label'       => __( 'Show cart actions', 'tiermint' ),
				'description' => __( 'Show remove and quantity-update controls for each line.', 'tiermint' ),
				'control'     => 'toggle',
			),
			'checkout_page'     => array(
				'type'        => 'integer',
				'default'     => 0,
				'label'       => __( 'Checkout page', 'tiermint' ),
				'description' => __( 'The page the checkout link points to. Leave at the default to use the site-wide checkout page setting.', 'tiermint' ),
				'control'     => 'page_select',
				'zero_label'  => __( 'Default (site checkout page)', 'tiermint' ),
			),
			'title'             => array(
				'type'        => 'string',
				'default'     => __( 'Your cart', 'tiermint' ),
				'label'       => __( 'Title', 'tiermint' ),
				'description' => __( 'Heading shown above the cart. Leave empty to hide the heading.', 'tiermint' ),
				'control'     => 'text',
			),
			'show_plan_details' => array(
				'type'        => 'boolean',
				'default'     => true,
				'label'       => __( 'Show plan details', 'tiermint' ),
				'description' => __( "Show each line's license length, activation limit, and billing under the product name.", 'tiermint' ),
				'control'     => 'toggle',
			),
		);
	}

	/**
	 * Render the cart summary.
	 *
	 * @param array<string, mixed> $attrs Resolved attribute values.
	 * @param RenderContext        $ctx   Per-request customer/license state.
	 * @return string Escaped HTML.
	 */
	public function render( array $attrs, RenderContext $ctx ): string {
		$cartState = $this->cart->getCart();

		// Use an in-memory sample cart only in an editor preview.
		$isSample = $cartState->isEmpty() && $ctx->isEditorPreview();
		if ( $isSample ) {
			$cartState = SamplePreview::cart();
		}

		$showPlanDetails = (bool) ( $attrs['show_plan_details'] ?? true );

		$notices = array();
		$lines   = $this->lineData( $cartState, $showPlanDetails, $notices );
		$total   = 0;

		foreach ( $lines as $line ) {
			$total += (int) $line['subtotal_minor'];
		}

		// Price the stored code against snapshot amounts without changing the cart.
		$discountItems = array();
		foreach ( $lines as $line ) {
			$discountItems[] = array(
				'product_id' => (int) ( $line['product_id'] ?? 0 ),
				'subtotal'   => (int) ( $line['subtotal_minor'] ?? 0 ),
			);
		}

		$discount = StoredDiscountPreview::view( $this->discounts, $cartState->discountCode(), $discountItems, $this->money, $this->discountComposer );

		if ( 'invalid' === $discount['state'] && '' !== $discount['error'] ) {
			$notices[] = $discount['error'];
		}

		$data = array(
			'title'              => (string) $attrs['title'],
			'show_actions'       => (bool) $attrs['show_actions'],
			'show_plan_details'  => $showPlanDetails,
			'is_empty'           => $cartState->isEmpty(),
			'lines'              => $lines,
			'discount'           => $discount,
			// Use the same combined reduction as the order layer.
			'subtotal'           => $this->money->format( $total - $discount['total_minor'] ),
			'checkout_url'       => $this->resolveCheckoutUrl( (int) $attrs['checkout_page'] ),
			'notices'            => $notices,
			// Gross mode relabels the total row to say the figure already includes tax.
			'prices_include_tax' => PricingMode::includesTax(),
		);

		return $isSample ? $this->renderSample( $data ) : $this->renderTemplate( $data );
	}

	/**
	 * Build the template-ready view data for every line in a cart.
	 *
	 * Clamp stale quantities with the tier rule and retain snapshot amounts.
	 *
	 * @param Cart                    $cartState       The cart to describe.
	 * @param bool                    $showPlanDetails Whether to attach the tier meta lines.
	 * @param array<int, string>|null $notices         Out parameter: one advisory per line
	 *                                                 whose quantity the tier rule adjusted.
	 * @return array<int, array<string, mixed>> One row per line.
	 */
	private function lineData( Cart $cartState, bool $showPlanDetails, ?array &$notices = null ): array {
		$notices = array();
		$rows    = array();

		$flags = TierMetaPresenter::flagsFrom( array() );

		foreach ( $cartState->lines() as $line ) {
			$price     = $this->livePrice( $line->productId(), $line->priceId() );
			$requested = $line->quantity();
			$quantity  = null !== $price ? $price->clampQuantity( $requested ) : $requested;

			if ( null !== $price && $quantity !== $requested ) {
				$notices[] = OrderService::quantityAdjustedNotice( $line->productName(), $quantity );
			}

			$subtotal = $line->unitAmount() * $quantity;

			// A missing tier has no plan details.
			$meta = ( $showPlanDetails && null !== $price ) ? TierMetaPresenter::lines( $price, $flags ) : array();

			$rows[] = array(
				'product_id'     => $line->productId(),
				'price_id'       => $line->priceId(),
				'product_name'   => $line->productName(),
				'price_name'     => $line->priceName(),
				'quantity'       => $quantity,
				'unit_amount'    => $this->money->format( $line->unitAmount() ),
				'subtotal'       => $this->money->format( $subtotal ),
				'subtotal_minor' => $subtotal,
				'min'            => null !== $price ? $price->minQuantity() : 1,
				'max'            => null !== $price ? $price->maxQuantity() : null,
				'fixed_single'   => null !== $price ? $price->isFixedSingle() : false,
				'meta'           => $meta,
			);
		}

		return $rows;
	}

	/**
	 * Resolve the live tier for a cart line.
	 *
	 * @param int $productId The line's product id.
	 * @param int $priceId   The line's price id.
	 * @return ProductPrice|null The active tier, or null.
	 */
	private function livePrice( int $productId, int $priceId ): ?ProductPrice {
		$product = $this->products->get( $productId );

		return null === $product ? null : $product->activePrice( $priceId );
	}

	/**
	 * Resolve the selected checkout page or the site default.
	 *
	 * @param int $checkoutPageAttr The `checkout_page` attribute value.
	 * @return string|null The checkout permalink, or null when nothing resolves.
	 */
	private function resolveCheckoutUrl( int $checkoutPageAttr ): ?string {
		$pageId = $checkoutPageAttr;

		if ( $pageId <= 0 ) {
			$settings = get_option( 'tiermint_settings' );

			if ( is_array( $settings ) && isset( $settings['pages']['checkout'] ) ) {
				$pageId = (int) $settings['pages']['checkout'];
			}
		}

		if ( $pageId <= 0 ) {
			return null;
		}

		$permalink = get_permalink( $pageId );

		return ( is_string( $permalink ) && '' !== $permalink ) ? $permalink : null;
	}
}
