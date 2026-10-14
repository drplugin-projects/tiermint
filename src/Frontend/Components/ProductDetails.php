<?php
/**
 * The all-in-one product details component.
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
use DrPlugin\WP\TierMint\Frontend\Components\Support\ScreenshotGallery;
use DrPlugin\WP\TierMint\Frontend\Components\Support\TierMetaPresenter;
use DrPlugin\WP\TierMint\Frontend\ProductPageTitle;
use DrPlugin\WP\TierMint\Products\Product;
use DrPlugin\WP\TierMint\Products\ProductService;
use DrPlugin\WP\TierMint\Util\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a product page with media, copy and a purchase form.
 *
 * It uses the shared tier selector and purchase-form partial.
 *
 * @since 1.0.0
 */
final class ProductDetails extends AbstractProductComponent {

	use ResolvesTierDiscounts;

	/**
	 * The money formatter for each tier's price.
	 *
	 * @var Money
	 */
	private Money $money;

	/**
	 * Resolves whether the theme owns the product heading.
	 *
	 * @var ProductPageTitle
	 */
	private ProductPageTitle $pageTitle;

	/**
	 * Wire the catalog, money, and product-title collaborators.
	 *
	 * @param ProductService                 $products  The catalog service.
	 * @param Money                          $money     The money formatter.
	 * @param ProductPageTitle               $pageTitle The product-title source authority.
	 * @param AutomaticDiscountResolver|null $discounts        The automatic-discount resolver for the strikethrough.
	 * @param DiscountSettings|null          $discountSettings The strikethrough display-toggle reader.
	 */
	public function __construct( ProductService $products, Money $money, ProductPageTitle $pageTitle, ?AutomaticDiscountResolver $discounts = null, ?DiscountSettings $discountSettings = null ) {
		parent::__construct( $products );
		$this->money     = $money;
		$this->pageTitle = $pageTitle;
		$this->setTierDiscountDeps( $discounts, $discountSettings );
	}

	/**
	 * The component slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'product-details';
	}

	/**
	 * Reduce a product to the flat, escape-ready view data the template needs.
	 *
	 * @param Product              $product The product to render.
	 * @param array<string, mixed> $attrs   Resolved attribute values (the tier-meta toggles).
	 * @return array<string, mixed>
	 */
	protected function viewData( Product $product, array $attrs ): array {
		$settings = $product->settings();

		return array(
			'name'        => $product->name(),
			'show_name'   => ! $this->pageTitle->blockHidesNameFor( $product ),
			'layout'      => 'single' === ( $attrs['layout'] ?? 'two-column' ) ? 'single' : 'two-column',
			'main_image'  => $this->image( (int) ( $settings['main_image'] ?? 0 ) ),
			'overview'    => $this->richField( $product->overview() ),
			'description' => $this->richField( $product->description() ),
			'screenshots' => ScreenshotGallery::fromIds( $settings['screenshots'] ?? array() ),
			'purchase'    => array( 'block' => 'tiermint-product-details' ) + ProductTierSelector::formData(
				$product,
				$this->money,
				TierMetaPresenter::flagsFrom( $attrs ),
				$this->tierDiscountMap( $product ),
				$this->tierStrikeEnabled()
			),
		);
	}

	/**
	 * Whether the product has no purchasable tier.
	 *
	 * @param array<string, mixed> $data The reduced view data.
	 * @return bool
	 */
	protected function viewDataIsEmpty( array $data ): bool {
		return empty( $data['purchase']['has_tiers'] );
	}

	/**
	 * Sample purchase data for an editor preview.
	 *
	 * @param Product              $product The resolved product.
	 * @param array<string, mixed> $attrs   Resolved attribute values (the tier-meta toggles).
	 * @return array<string, mixed>
	 */
	protected function sampleViewData( Product $product, array $attrs ): array {
		$data             = $this->viewData( $product, $attrs );
		$data['purchase'] = array( 'block' => 'tiermint-product-details' )
			+ ProductTierSelector::formData( SamplePreview::tierProduct(), $this->money, TierMetaPresenter::flagsFrom( $attrs ) );

		return $data;
	}

	/**
	 * The routed product and tier-meta options.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function schema(): array {
		return parent::schema() + array(
			'layout' => array(
				'type'        => 'enum',
				'enum'        => array( 'two-column', 'single' ),
				'default'     => 'two-column',
				'label'       => __( 'Layout', 'tiermint' ),
				'description' => __( 'Two columns (media beside the buying panel) where there is room, or a single stacked column.', 'tiermint' ),
				'control'     => 'select',
			),
		) + TierMetaPresenter::toggleSchema();
	}

	/**
	 * Asset handles for the shared screenshot grid.
	 *
	 * @return array<string, string[]>
	 */
	public function assets(): array {
		$assets = parent::assets();

		$assets['styles']  = array_merge( $assets['styles'], array( 'tiermint-product-screenshots' ) );
		$assets['scripts'] = array_merge( $assets['scripts'], array( 'tiermint-product-screenshots' ) );

		return $assets;
	}

	/**
	 * Render the editor placeholder when no product resolves.
	 *
	 * @return string Escaped HTML.
	 */
	protected function editorPlaceholder(): string {
		return '<div class="tiermint-component tiermint-product-details tiermint-product-details--placeholder">'
			. '<p>' . esc_html__( 'Product details will appear here on the product page.', 'tiermint' ) . '</p>'
			. '</div>';
	}

	/**
	 * Resolve an attachment to a URL and alt text.
	 *
	 * @param int $id The attachment id.
	 * @return array{url: string, alt: string}|null
	 */
	private function image( int $id ): ?array {
		if ( $id <= 0 ) {
			return null;
		}

		$url = wp_get_attachment_image_url( $id, 'large' );

		if ( ! is_string( $url ) || '' === $url ) {
			return null;
		}

		return array(
			'url' => $url,
			'alt' => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
		);
	}
}
