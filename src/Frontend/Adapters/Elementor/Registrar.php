<?php
/**
 * Registers the TierMint Elementor widgets, tags, and category.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend\Adapters\Elementor;

use DrPlugin\WP\TierMint\Core\Hookable;
use DrPlugin\WP\TierMint\Frontend\Adapters\Elementor\Tags\AbstractProductTag;
use DrPlugin\WP\TierMint\Frontend\Adapters\Elementor\Tags\ProductNameTag;
use DrPlugin\WP\TierMint\Frontend\Adapters\Elementor\Tags\ProductPriceTag;
use DrPlugin\WP\TierMint\Frontend\Adapters\Elementor\Tags\TagValues;
use DrPlugin\WP\TierMint\Frontend\Adapters\Elementor\Widgets\BuyButtonWidget;
use DrPlugin\WP\TierMint\Frontend\Adapters\Elementor\Widgets\CheckoutWidget;
use DrPlugin\WP\TierMint\Frontend\Adapters\Elementor\Widgets\ComponentWidget;
use DrPlugin\WP\TierMint\Frontend\Adapters\Elementor\Widgets\DownloadsWidget;
use DrPlugin\WP\TierMint\Frontend\Adapters\Elementor\Widgets\LicensesWidget;
use DrPlugin\WP\TierMint\Frontend\Adapters\Elementor\Widgets\LoginWidget;
use DrPlugin\WP\TierMint\Frontend\Adapters\Elementor\Widgets\PasswordResetWidget;
use DrPlugin\WP\TierMint\Frontend\Adapters\Elementor\Widgets\PricingTableWidget;
use DrPlugin\WP\TierMint\Frontend\Adapters\Elementor\Widgets\ProductDetailsWidget;
use DrPlugin\WP\TierMint\Frontend\Adapters\Elementor\Widgets\ProductGridWidget;
use DrPlugin\WP\TierMint\Frontend\Adapters\Elementor\Widgets\RegistrationWidget;
use DrPlugin\WP\TierMint\Frontend\AssetManager;
use DrPlugin\WP\TierMint\Frontend\ComponentRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Registers TierMint widgets and tags when Elementor is active.
 *
 * Elementor hooks avoid loading Elementor classes on sites without Elementor.
 *
 * @since 1.0.0
 */
class Registrar implements Hookable {

	/**
	 * The widget category and dynamic-tag group slug.
	 *
	 * @var string
	 */
	public const CATEGORY_SLUG = 'tiermint';

	/**
	 * Widget classes core registers with Elementor.
	 *
	 * @var array<int, class-string<ComponentWidget>>
	 */
	private const WIDGET_CLASSES = array(
		BuyButtonWidget::class,
		PricingTableWidget::class,
		CheckoutWidget::class,
		ProductGridWidget::class,
		ProductDetailsWidget::class,
		LoginWidget::class,
		RegistrationWidget::class,
		PasswordResetWidget::class,
		LicensesWidget::class,
		DownloadsWidget::class,
	);

	/**
	 * The toggle reader gating the whole adapter.
	 *
	 * @var ElementorSettings
	 */
	private ElementorSettings $settings;

	/**
	 * The render delegate handed to the widget base via its static accessor.
	 *
	 * @var WidgetRenderer
	 */
	private WidgetRenderer $renderer;

	/**
	 * The component registry the pre-enqueue scan resolves widget types against.
	 *
	 * @var ComponentRegistry
	 */
	private ComponentRegistry $registry;

	/**
	 * Render-time asset enqueueing for the pre-enqueue scan.
	 *
	 * @var AssetManager
	 */
	private AssetManager $assets;

	/**
	 * The value delegate handed to the dynamic-tag base via its static accessor.
	 *
	 * @var TagValues
	 */
	private TagValues $tagValues;

	/**
	 * Wire the adapter's collaborators.
	 *
	 * @param ElementorSettings $settings  Toggle reader.
	 * @param WidgetRenderer    $renderer  Widget render delegate.
	 * @param ComponentRegistry $registry  Registered components.
	 * @param AssetManager      $assets    Render-time asset enqueueing.
	 * @param TagValues         $tagValues Dynamic-tag value delegate.
	 */
	public function __construct(
		ElementorSettings $settings,
		WidgetRenderer $renderer,
		ComponentRegistry $registry,
		AssetManager $assets,
		TagValues $tagValues
	) {
		$this->settings  = $settings;
		$this->renderer  = $renderer;
		$this->registry  = $registry;
		$this->assets    = $assets;
		$this->tagValues = $tagValues;
	}

	/**
	 * Register hooks when the integration is enabled.
	 *
	 * @return void
	 */
	public function hooks(): void {
		if ( ! $this->settings->elementorEnabled() ) {
			return;
		}

		add_action( 'elementor/elements/categories_registered', array( $this, 'registerCategory' ) );
		add_action( 'elementor/widgets/register', array( $this, 'registerWidgets' ) );
		add_action( 'elementor/dynamic_tags/register', array( $this, 'registerTags' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueueForQueriedContent' ) );
	}

	/**
	 * Register the TierMint widget category so the widgets group together in the
	 * editor panel.
	 *
	 * @param \Elementor\Elements_Manager $elementsManager Elementor's elements manager.
	 * @return void
	 */
	public function registerCategory( $elementsManager ): void {
		$elementsManager->add_category(
			self::CATEGORY_SLUG,
			array(
				'title' => __( 'TierMint', 'tiermint' ),
				'icon'  => 'eicon-cart-medium',
			)
		);
	}

	/**
	 * Register the widgets after setting their render delegate.
	 *
	 * @param \Elementor\Widgets_Manager $widgetsManager Elementor's widgets manager.
	 * @return void
	 */
	public function registerWidgets( $widgetsManager ): void {
		ComponentWidget::setRenderer( $this->renderer );

		foreach ( self::WIDGET_CLASSES as $class ) {
			$widgetsManager->register( new $class() );
		}
	}

	/**
	 * Register the tags after setting their value delegate.
	 *
	 * @param \Elementor\Core\DynamicTags\Manager $tagsManager Elementor's dynamic-tags manager.
	 * @return void
	 */
	public function registerTags( $tagsManager ): void {
		AbstractProductTag::setValues( $this->tagValues );

		$tagsManager->register_group( self::CATEGORY_SLUG, array( 'title' => __( 'TierMint', 'tiermint' ) ) );
		$tagsManager->register( new ProductNameTag() );
		$tagsManager->register( new ProductPriceTag() );
	}

	/**
	 * Pre-enqueue assets for TierMint widgets in the queried Elementor page.
	 *
	 * The scan runs before wp_head, where widget rendering is too late.
	 *
	 * @return void
	 */
	public function enqueueForQueriedContent(): void {
		if ( ! is_singular() ) {
			return;
		}

		$postId = get_queried_object_id();

		if ( $postId <= 0 ) {
			return;
		}

		$data = get_post_meta( $postId, '_elementor_data', true );

		if ( ! is_string( $data ) || '' === $data ) {
			return;
		}

		foreach ( $this->slugsInElementorData( $data ) as $slug ) {
			$component = $this->registry->get( $slug );

			if ( null !== $component ) {
				$this->assets->enqueueFor( $component );
			}
		}
	}

	/**
	 * Get unique TierMint widget slugs from Elementor data.
	 *
	 * @param string $json The raw _elementor_data meta value.
	 * @return array<int, string> Unique component slugs.
	 */
	private function slugsInElementorData( string $json ): array {
		$decoded = json_decode( $json, true );

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$slugs = array();
		$this->collectWidgetSlugs( $decoded, $slugs );

		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Collect TierMint widget slugs from an Elementor element tree.
	 *
	 * @param array<int|string, mixed> $nodes The elements at this level.
	 * @param array<int, string>       $slugs Slug accumulator, appended by reference.
	 * @return void
	 */
	private function collectWidgetSlugs( array $nodes, array &$slugs ): void {
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$type = isset( $node['widgetType'] ) && is_string( $node['widgetType'] ) ? $node['widgetType'] : '';

			if ( str_starts_with( $type, 'tiermint-' ) ) {
				$slugs[] = substr( $type, strlen( 'tiermint-' ) );
			}

			if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
				$this->collectWidgetSlugs( $node['elements'], $slugs );
			}
		}
	}
}
