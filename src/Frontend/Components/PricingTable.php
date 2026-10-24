<?php
/**
 * The multi-product pricing table component.
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
use DrPlugin\WP\TierMint\Frontend\Components\Support\SamplePreview;
use DrPlugin\WP\TierMint\Frontend\Components\Support\TierMetaPresenter;
use DrPlugin\WP\TierMint\Frontend\RenderContext;
use DrPlugin\WP\TierMint\Products\Product;
use DrPlugin\WP\TierMint\Products\ProductService;
use DrPlugin\WP\TierMint\Products\ProductStatus;
use DrPlugin\WP\TierMint\Util\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Renders purchasable products as plan columns.
 *
 * Products without a valid default tier are not shown.
 *
 * @since 1.0.0
 */
final class PricingTable extends AbstractComponent {

	/**
	 * The catalog service used to load each product and its price tiers.
	 *
	 * @var ProductService
	 */
	private ProductService $products;

	/**
	 * The money formatter for each plan's price.
	 *
	 * @var Money
	 */
	private Money $money;

	/**
	 * Resolves automatic discounts when the Discounts domain is available.
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
		return 'pricing-table';
	}

	/**
	 * The attribute schema adapters map their controls onto.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function schema(): array {
		return array(
			'product_ids'   => array(
				'type'        => 'array',
				'items'       => 'integer',
				'default'     => array(),
				'required'    => true,
				'control'     => 'product_select',
				'multiple'    => true,
				'label'       => __( 'Products', 'tiermint' ),
				'description' => __( 'The products to show as columns, in order.', 'tiermint' ),
			),
			'highlight_id'  => array(
				'type'        => 'integer',
				'default'     => 0,
				'control'     => 'number',
				'label'       => __( 'Highlighted product', 'tiermint' ),
				'description' => __( 'The product id to feature as most popular, or 0 for none.', 'tiermint' ),
			),
			'show_features' => array(
				'type'    => 'boolean',
				'default' => true,
				'control' => 'toggle',
				'label'   => __( 'Show features', 'tiermint' ),
			),
			'columns'       => array(
				'type'    => 'enum',
				'enum'    => array( '2', '3', '4' ),
				'default' => '3',
				'control' => 'select',
				'label'   => __( 'Columns', 'tiermint' ),
			),
			'button_text'   => array(
				'type'    => 'string',
				'default' => __( 'Buy now', 'tiermint' ),
				'control' => 'text',
				'label'   => __( 'Button text', 'tiermint' ),
			),
		) + TierMetaPresenter::toggleSchema();
	}

	/**
	 * Render the pricing table, or an empty string when no plan resolves.
	 *
	 * @param array<string, mixed> $attrs Resolved attribute values.
	 * @param RenderContext        $ctx   Per-request front-end state (unused here).
	 * @return string Escaped HTML, or '' when nothing should render.
	 */
	public function render( array $attrs, RenderContext $ctx ): string {
		$highlightId  = (int) $attrs['highlight_id'];
		$showFeatures = (bool) $attrs['show_features'];
		$metaFlags    = TierMetaPresenter::flagsFrom( $attrs );

		$plans = array();

		foreach ( (array) $attrs['product_ids'] as $rawId ) {
			$plan = $this->buildPlan( (int) $rawId, $highlightId, $showFeatures, $metaFlags );
			if ( null !== $plan ) {
				$plans[] = $plan;
			}
		}

		// Show sample plans only in an editor preview.
		if ( array() === $plans ) {
			return $ctx->isEditorPreview()
				? $this->renderSample( $this->sampleData( $attrs, $showFeatures, $metaFlags ) )
				: '';
		}

		return $this->renderTemplate(
			array(
				'columns'       => (string) $attrs['columns'],
				'button_text'   => (string) $attrs['button_text'],
				'popular_label' => __( 'Most popular', 'tiermint' ),
				'action_url'    => remove_query_arg( 'tiermint_cart_error' ),
				'nonce_action'  => CartActionListener::NONCE_ACTION,
				'nonce_field'   => CartActionListener::NONCE_FIELD,
				'plans'         => $this->withDiscountDisplay( $plans ),
			)
		);
	}

	/**
	 * Render each plan price and any automatic discount.
	 *
	 * @param array<int, array<string, mixed>> $plans The resolved plan rows carrying `product_id` and `amount`.
	 * @return array<int, array<string, mixed>> The plans with a `price_html` node added.
	 */
	private function withDiscountDisplay( array $plans ): array {
		$strike     = $this->strikeEnabled();
		$discounted = $this->discountedByIndex( $plans );

		foreach ( $plans as $index => $plan ) {
			$amount                        = (int) $plan['amount'];
			$plans[ $index ]['price_html'] = PriceDisplay::render( $this->money, $amount, $discounted[ $index ] ?? null, $strike );
		}

		return $plans;
	}

	/**
	 * Resolve discounted prices by plan index.
	 *
	 * @param array<int, array<string, mixed>> $plans The resolved plan rows.
	 * @return array<int, int|null> The discounted unit price keyed by plan index; null where nothing applies.
	 */
	private function discountedByIndex( array $plans ): array {
		if ( null === $this->discounts ) {
			return array();
		}

		$nodes = array();
		foreach ( $plans as $index => $plan ) {
			$nodes[ $index ] = array(
				'product_id' => (int) $plan['product_id'],
				'subtotal'   => (int) $plan['amount'],
			);
		}

		try {
			$best = $this->discounts->bestForNodes( $nodes, null );
		} catch ( DiscountPersistenceException $e ) {
			return array();
		}

		$result = array();
		foreach ( $best as $index => $application ) {
			$result[ $index ] = null === $application ? null : (int) $plans[ $index ]['amount'] - $application->total();
		}

		return $result;
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
	 * Sample table data for an editor preview.
	 *
	 * @param array<string, mixed> $attrs        Resolved attribute values.
	 * @param bool                 $showFeatures Whether to include the feature list.
	 * @param array<string, bool>  $metaFlags    The tier-meta display flags.
	 * @return array<string, mixed>
	 */
	private function sampleData( array $attrs, bool $showFeatures, array $metaFlags ): array {
		$labels   = array( __( 'Starter', 'tiermint' ), __( 'Business', 'tiermint' ) );
		$features = array(
			array( __( 'Core features', 'tiermint' ), __( 'One year of updates', 'tiermint' ) ),
			array( __( 'Everything in Starter', 'tiermint' ), __( 'Priority support', 'tiermint' ) ),
		);

		$prices = array_values( SamplePreview::tierProduct()->prices() );
		$last   = count( $prices ) - 1;

		$plans = array();
		foreach ( $prices as $index => $price ) {
			$isHighlight = $index === $last;
			$planClass   = 'tiermint-pricing-table__plan';
			if ( $isHighlight ) {
				$planClass .= ' tiermint-pricing-table__plan--highlight';
			}

			$plans[] = array(
				'product_id'   => 0,
				'price_id'     => (int) $price->id(),
				'quantity'     => $price->minQuantity(),
				'name'         => $labels[ $index ] ?? $price->name(),
				'description'  => '',
				// Sample prices do not have discounts.
				'price_html'   => PriceDisplay::render( $this->money, $price->amount(), null, $this->strikeEnabled() ),
				'tier_name'    => $price->name(),
				'meta'         => TierMetaPresenter::lines( $price, $metaFlags ),
				'features'     => $showFeatures ? ( $features[ $index ] ?? array() ) : array(),
				'is_highlight' => $isHighlight,
				'plan_class'   => $planClass,
			);
		}

		return array(
			'columns'       => (string) $attrs['columns'],
			'button_text'   => (string) $attrs['button_text'],
			'popular_label' => __( 'Most popular', 'tiermint' ),
			'action_url'    => remove_query_arg( 'tiermint_cart_error' ),
			'nonce_action'  => CartActionListener::NONCE_ACTION,
			'nonce_field'   => CartActionListener::NONCE_FIELD,
			'plans'         => $plans,
		);
	}

	/**
	 * Build one purchasable plan column.
	 *
	 * @param int                 $productId    The candidate product id.
	 * @param int                 $highlightId  The product id to mark as most popular.
	 * @param bool                $showFeatures Whether to include the feature list.
	 * @param array<string, bool> $metaFlags    The tier-meta display flags keyed by attribute name.
	 * @return array<string, mixed>|null The plan view data, or null to skip.
	 */
	private function buildPlan( int $productId, int $highlightId, bool $showFeatures, array $metaFlags ): ?array {
		if ( $productId <= 0 ) {
			return null;
		}

		$product = $this->products->get( $productId );
		if ( null === $product || ProductStatus::Published !== $product->status() ) {
			return null;
		}

		$price = $product->defaultPrice();
		if ( null === $price ) {
			return null;
		}

		$isHighlight = $highlightId > 0 && $highlightId === $productId;

		$planClass = 'tiermint-pricing-table__plan';
		if ( $isHighlight ) {
			$planClass .= ' tiermint-pricing-table__plan--highlight';
		}

		return array(
			'product_id'   => $productId,
			'price_id'     => (int) $price->id(),
			'quantity'     => $price->minQuantity(),
			'name'         => $product->name(),
			'description'  => (string) ( $product->description() ?? '' ),
			// Keep the amount for the discount display.
			'amount'       => $price->amount(),
			'tier_name'    => $price->name(),
			'meta'         => TierMetaPresenter::lines( $price, $metaFlags ),
			'features'     => $showFeatures ? $this->features( $product ) : array(),
			'is_highlight' => $isHighlight,
			'plan_class'   => $planClass,
		);
	}

	/**
	 * Return valid feature strings from the product settings.
	 *
	 * @param Product $product The product whose settings hold the features.
	 * @return array<int, string> The feature strings, in order.
	 */
	private function features( Product $product ): array {
		$features = $product->settings()['features'] ?? null;

		if ( ! is_array( $features ) ) {
			return array();
		}

		$strings = array();
		foreach ( $features as $feature ) {
			if ( is_string( $feature ) && '' !== $feature ) {
				$strings[] = $feature;
			}
		}

		return $strings;
	}
}
