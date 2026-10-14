<?php
/**
 * The ProductGrid front-end component.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend\Components;

use DrPlugin\WP\TierMint\Discounts\AutomaticDiscountResolver;
use DrPlugin\WP\TierMint\Discounts\DiscountPersistenceException;
use DrPlugin\WP\TierMint\Discounts\DiscountSettings;
use DrPlugin\WP\TierMint\Frontend\AbstractComponent;
use DrPlugin\WP\TierMint\Frontend\Components\Support\PriceDisplay;
use DrPlugin\WP\TierMint\Frontend\Components\Support\SamplePreview;
use DrPlugin\WP\TierMint\Frontend\ProductUrlProvider;
use DrPlugin\WP\TierMint\Frontend\RenderContext;
use DrPlugin\WP\TierMint\Products\Product;
use DrPlugin\WP\TierMint\Products\ProductService;
use DrPlugin\WP\TierMint\Products\ProductTaxonomy;
use DrPlugin\WP\TierMint\Products\Term;
use DrPlugin\WP\TierMint\Products\TermService;
use DrPlugin\WP\TierMint\Util\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Filtered, paginated grid of published products.
 *
 * Visitor filters can narrow the author's categories or tags, never widen them.
 *
 * @since 1.0.0
 */
class ProductGrid extends AbstractComponent {

	/**
	 * GET key for visitor category filters.
	 *
	 * @var string
	 */
	public const GET_CATEGORY = 'tiermint_category';

	/**
	 * GET key for visitor tag filters.
	 *
	 * @var string
	 */
	public const GET_TAG = 'tiermint_tag';

	/**
	 * The settings option holding the storefront defaults.
	 *
	 * @var string
	 */
	private const SETTINGS_OPTION = 'tiermint_settings';

	/**
	 * The product service the grid queries.
	 *
	 * @var ProductService
	 */
	private ProductService $products;

	/**
	 * The term service supplying the filter bar's category and tag lists.
	 *
	 * @var TermService
	 */
	private TermService $terms;

	/**
	 * The money formatter for each card's price.
	 *
	 * @var Money
	 */
	private Money $money;

	/**
	 * The builder for each card's details-page link.
	 *
	 * @var ProductUrlProvider
	 */
	private ProductUrlProvider $urls;

	/**
	 * Resolves automatic display discounts, if available.
	 *
	 * @var AutomaticDiscountResolver|null
	 */
	private ?AutomaticDiscountResolver $discounts;

	/**
	 * Reads the strikethrough setting, if available.
	 *
	 * @var DiscountSettings|null
	 */
	private ?DiscountSettings $discountSettings;

	/**
	 * Wire the grid's collaborators.
	 *
	 * @since 1.0.0
	 *
	 * @param ProductService                 $products The product query service.
	 * @param TermService                    $terms    The taxonomy service for the filter bar.
	 * @param Money                          $money    The store-currency formatter.
	 * @param ProductUrlProvider             $urls     The details-page URL builder.
	 * @param AutomaticDiscountResolver|null $discounts        The automatic-discount resolver for the strikethrough.
	 * @param DiscountSettings|null          $discountSettings The strikethrough display-toggle reader.
	 */
	public function __construct( ProductService $products, TermService $terms, Money $money, ProductUrlProvider $urls, ?AutomaticDiscountResolver $discounts = null, ?DiscountSettings $discountSettings = null ) {
		$this->products         = $products;
		$this->terms            = $terms;
		$this->money            = $money;
		$this->urls             = $urls;
		$this->discounts        = $discounts;
		$this->discountSettings = $discountSettings;
	}

	/**
	 * Get the component slug.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'product-grid';
	}

	/**
	 * The attribute schema.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function schema(): array {
		return array(
			'category'         => array(
				'type'        => 'array',
				'items'       => 'string',
				'default'     => array(),
				'label'       => __( 'Categories', 'tiermint' ),
				'description' => __( 'Limit the grid to products in these category slugs. Leave empty to show every category.', 'tiermint' ),
				'control'     => 'term_select',
				'taxonomy'    => 'category',
			),
			'tag'              => array(
				'type'        => 'array',
				'items'       => 'string',
				'default'     => array(),
				'label'       => __( 'Tags', 'tiermint' ),
				'description' => __( 'Limit the grid to products carrying these tag slugs. Leave empty to show every tag.', 'tiermint' ),
				'control'     => 'term_select',
				'taxonomy'    => 'tag',
			),
			'include_children' => array(
				'type'        => 'boolean',
				'default'     => true,
				'label'       => __( 'Include child categories', 'tiermint' ),
				'description' => __( 'Also show products filed under subcategories of the selected categories.', 'tiermint' ),
				'control'     => 'toggle',
			),
			'show_filters'     => array(
				'type'        => 'boolean',
				'default'     => false,
				'label'       => __( 'Show filter bar', 'tiermint' ),
				'description' => __( 'Let visitors narrow the grid by category and tag.', 'tiermint' ),
				'control'     => 'toggle',
			),
			'columns'          => array(
				'type'        => 'enum',
				'enum'        => array( '2', '3', '4' ),
				'default'     => '3',
				'label'       => __( 'Columns', 'tiermint' ),
				'description' => __( 'How many products to show per row on wide screens.', 'tiermint' ),
				'control'     => 'select',
			),
			'per_page'         => array(
				'type'        => 'integer',
				'default'     => 9,
				'label'       => __( 'Products per page', 'tiermint' ),
				'description' => __( 'The maximum number of products to display.', 'tiermint' ),
				'control'     => 'number',
			),
			'orderby'          => array(
				'type'        => 'enum',
				'enum'        => array( 'created_at', 'name', 'id' ),
				'default'     => 'created_at',
				'label'       => __( 'Order by', 'tiermint' ),
				'description' => __( 'Which product field to sort by.', 'tiermint' ),
				'control'     => 'select',
			),
			'order'            => array(
				'type'        => 'enum',
				'enum'        => array( 'DESC', 'ASC' ),
				'default'     => 'DESC',
				'label'       => __( 'Order', 'tiermint' ),
				'description' => __( 'The sort direction.', 'tiermint' ),
				'control'     => 'select',
			),
			'show_price'       => array(
				'type'        => 'boolean',
				'default'     => true,
				'label'       => __( 'Show price', 'tiermint' ),
				'description' => __( "Display each product's starting price.", 'tiermint' ),
				'control'     => 'toggle',
			),
			'show_buy_button'  => array(
				// The default is the store-wide storefront.grid_buy_buttons
				// setting, so a merchant flips buy buttons everywhere in one
				// place; an explicit block/shortcode value still wins.
				'type'        => 'boolean',
				'default'     => $this->buyButtonsDefault(),
				'label'       => __( 'Show buy buttons', 'tiermint' ),
				'description' => __( 'Show an add-to-cart button on each card. Defaults to the storefront setting.', 'tiermint' ),
				'control'     => 'toggle',
			),
			'show_thumbnails'  => array(
				'type'        => 'boolean',
				'default'     => true,
				'label'       => __( 'Show thumbnails', 'tiermint' ),
				'description' => __( "Show each product's thumbnail image on its card. Cards fall back to a lettered tile when off or when a product has no image.", 'tiermint' ),
				'control'     => 'toggle',
			),
			'link_to_details'  => array(
				'type'        => 'boolean',
				'default'     => true,
				'label'       => __( 'Link to product page', 'tiermint' ),
				'description' => __( 'Link each card to its product details page.', 'tiermint' ),
				'control'     => 'toggle',
			),
		);
	}

	/**
	 * The store-wide default for card buy buttons, from
	 * `tiermint_settings['storefront']['grid_buy_buttons']`, defaulting on.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private function buyButtonsDefault(): bool {
		$settings = get_option( self::SETTINGS_OPTION );

		if ( is_array( $settings ) && isset( $settings['storefront']['grid_buy_buttons'] ) ) {
			return (bool) $settings['storefront']['grid_buy_buttons'];
		}

		return true;
	}

	/**
	 * Render the grid.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $attrs Resolved attribute values.
	 * @param RenderContext        $ctx   Per-request front-end state.
	 * @return string Escaped HTML.
	 */
	public function render( array $attrs, RenderContext $ctx ): string {
		$showFilters = (bool) ( $attrs['show_filters'] ?? false );

		$categories = $this->slugList( $attrs['category'] ?? array() );
		$tags       = $this->slugList( $attrs['tag'] ?? array() );

		// A filter URL narrows the grid whether or not the interactive bar is shown:
		// the bar is a visitor convenience, the GET keys are the link contract the
		// product-meta term links point at. narrow() still holds curation, so a
		// visitor selection can only ever narrow the author's fixed set, never widen
		// it; an absent key leaves the author's set untouched.
		$categories = $this->narrow( $categories, $this->visitorSelection( self::GET_CATEGORY ) );
		$tags       = $this->narrow( $tags, $this->visitorSelection( self::GET_TAG ) );

		// An unmatched category/tag slug must restrict the query to nothing,
		// never fall through to the unfiltered catalogue.
		$products = $this->products->list(
			array(
				'status'           => 'published',
				'category'         => $categories,
				'tag'              => $tags,
				'include_children' => (bool) ( $attrs['include_children'] ?? true ),
				'per_page'         => (int) ( $attrs['per_page'] ?? 9 ),
				'orderby'          => (string) ( $attrs['orderby'] ?? 'created_at' ),
				'order'            => (string) ( $attrs['order'] ?? 'DESC' ),
				'page'             => 1,
			)
		);

		$showPrice      = (bool) ( $attrs['show_price'] ?? true );
		$showBuyButton  = (bool) ( $attrs['show_buy_button'] ?? true );
		$showThumbnails = (bool) ( $attrs['show_thumbnails'] ?? true );
		$linkToDetails  = (bool) ( $attrs['link_to_details'] ?? true );

		// A card's price and buy form come from the product's default purchasable tier,
		// which skips recurring tiers the store cannot bill (a product whose only tier is
		// an unsupported subscription shows no price and no buy button).
		$recurringSupported = ProductService::recurringTiersSupported();

		// One batched read of the best automatic discount for every priced card, so a sale
		// strikes each covered card and a grid page stays a bounded number of queries.
		$discounted = $this->discountedByProduct( $products, $showPrice, $recurringSupported );

		$cards = array();
		foreach ( $products as $product ) {
			$cards[] = $this->cardData( $product, $showPrice, $recurringSupported, $showBuyButton, $showThumbnails, $linkToDetails, $discounted );
		}

		// An empty grid in an editor canvas would render only "No products found.",
		// so fill it with a few sample cards. Live pages keep the honest empty grid.
		$isSample = array() === $cards && $ctx->isEditorPreview();
		if ( $isSample ) {
			$cards = $this->sampleCards( $showPrice, $showThumbnails );
		}

		$data = array(
			'columns'    => (string) ( $attrs['columns'] ?? '3' ),
			'products'   => $cards,
			'filter_bar' => $showFilters ? $this->filterBarData( $categories, $tags ) : null,
		);

		return $isSample ? $this->renderSample( $data ) : $this->renderTemplate( $data );
	}

	/**
	 * Build sample product cards for the editor.
	 *
	 * @param bool $showPrice      Whether each card shows a price.
	 * @param bool $showThumbnails Whether each card shows a media tile.
	 * @return array<int, array<string, mixed>>
	 */
	private function sampleCards( bool $showPrice, bool $showThumbnails ): array {
		$samples = array(
			array( __( 'Starter Plugin', 'tiermint' ), 4900 ),
			array( __( 'Pro Toolkit', 'tiermint' ), 7900 ),
			array( __( 'Premium Suite', 'tiermint' ), 12900 ),
		);

		$cards = array();
		foreach ( $samples as $sample ) {
			$name    = $sample[0];
			$cards[] = array(
				'name'        => $name,
				'monogram'    => $this->monogram( $name ),
				'image'       => $showThumbnails ? SamplePreview::placeholderTile( 'tiermint-product-grid__image', $name ) : null,
				'description' => __( 'A short description of what this product does and who it is for.', 'tiermint' ),
				'url'         => null,
				// Sample prices still use the shared display helper.
				'price_html'  => $showPrice ? PriceDisplay::render( $this->money, (int) $sample[1], null, $this->strikeEnabled() ) : null,
				'buy'         => null,
			);
		}

		return $cards;
	}

	/**
	 * Build escape-ready view data for one product card.
	 *
	 * @since 1.0.0
	 *
	 * @param Product              $product            The product to reduce.
	 * @param bool                 $showPrice          Whether the price should be displayed.
	 * @param bool                 $recurringSupported Whether recurring tiers can be billed on this install.
	 * @param bool                 $showBuyButton      Whether a buy button should be rendered.
	 * @param bool                 $showThumbnails     Whether the card shows the product's thumbnail image.
	 * @param bool                 $linkToDetails      Whether the card links to the details page.
	 * @param array<int, int|null> $discounted         The discounted unit price keyed by product id (from the batched resolve).
	 * @return array{name: string, monogram: string, image: string|null, description: string|null, url: string|null, price_html: string|null, buy: array{product_id: int, price_id: int, quantity: int}|null}
	 */
	private function cardData( Product $product, bool $showPrice, bool $recurringSupported, bool $showBuyButton, bool $showThumbnails, bool $linkToDetails, array $discounted ): array {
		$default  = $product->defaultPrice( $recurringSupported );
		$hasPrice = null !== $default;

		return array(
			'name'        => $product->name(),
			'monogram'    => $this->monogram( $product->name() ),
			'image'       => $showThumbnails ? $this->cardImage( $product ) : null,
			'description' => $this->cardCopy( $product ),
			'url'         => $linkToDetails ? $this->urls->url( $product ) : null,
			'price_html'  => ( $showPrice && $hasPrice )
				? PriceDisplay::render( $this->money, $default->amount(), $discounted[ (int) $product->id() ] ?? null, $this->strikeEnabled() )
				: null,
			'buy'         => ( $showBuyButton && $hasPrice )
				? array(
					'product_id' => (int) $product->id(),
					'price_id'   => (int) $default->id(),
					'quantity'   => $default->minQuantity(),
				)
				: null,
		);
	}

	/**
	 * Get batched automatic display discounts by product ID.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, Product> $products           The products on the page.
	 * @param bool                $showPrice          Whether prices are displayed at all.
	 * @param bool                $recurringSupported Whether recurring tiers can be billed on this install.
	 * @return array<int, int|null> The discounted unit price keyed by product id (only covered products present).
	 */
	private function discountedByProduct( array $products, bool $showPrice, bool $recurringSupported ): array {
		if ( ! $showPrice || null === $this->discounts ) {
			return array();
		}

		$nodes = array();
		foreach ( $products as $product ) {
			$default = $product->defaultPrice( $recurringSupported );
			if ( null === $default ) {
				continue;
			}

			$nodes[] = array(
				'product_id' => (int) $product->id(),
				'subtotal'   => $default->amount(),
			);
		}

		if ( array() === $nodes ) {
			return array();
		}

		try {
			$best = $this->discounts->bestForNodes( $nodes, null );
		} catch ( DiscountPersistenceException $e ) {
			return array();
		}

		$result = array();
		foreach ( array_values( $nodes ) as $index => $node ) {
			$application = $best[ $index ] ?? null;
			if ( null !== $application ) {
				$result[ (int) $node['product_id'] ] = (int) $node['subtotal'] - $application->total();
			}
		}

		return $result;
	}

	/**
	 * Check if discounted prices show a strikethrough.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private function strikeEnabled(): bool {
		return null === $this->discountSettings || $this->discountSettings->strikethroughEnabled();
	}

	/**
	 * Build the thumbnail fallback monogram.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name The product name.
	 * @return string A single uppercased character, or an empty string.
	 */
	private function monogram( string $name ): string {
		$trimmed = trim( $name );

		if ( '' === $trimmed ) {
			return '';
		}

		if ( function_exists( 'mb_substr' ) && function_exists( 'mb_strtoupper' ) ) {
			return mb_strtoupper( mb_substr( $trimmed, 0, 1 ) );
		}

		$first = substr( $trimmed, 0, 1 );

		return preg_match( '/^[\x00-\x7F]$/', $first ) ? strtoupper( $first ) : '';
	}

	/**
	 * Render the card image, or return null for a monogram.
	 *
	 * @since 1.0.0
	 *
	 * @param Product $product The product whose image to render.
	 * @return string|null The escaped image markup, or null when there is none.
	 */
	private function cardImage( Product $product ): ?string {
		$settings = $product->settings();
		$id       = (int) ( $settings['thumbnail'] ?? 0 );

		if ( $id <= 0 ) {
			$id = (int) ( $settings['main_image'] ?? 0 );
		}

		if ( $id <= 0 ) {
			return null;
		}

		$html = wp_get_attachment_image(
			$id,
			'medium_large',
			false,
			array(
				'class'   => 'tiermint-product-grid__image',
				'loading' => 'lazy',
				'alt'     => '',
			)
		);

		return ( is_string( $html ) && '' !== $html ) ? $html : null;
	}

	/**
	 * The card's copy: the short overview when set, otherwise the description.
	 *
	 * @since 1.0.0
	 *
	 * @param Product $product The product whose copy to pick.
	 * @return string|null The chosen copy, or null when both are empty.
	 */
	private function cardCopy( Product $product ): ?string {
		$overview = $product->overview();

		if ( null !== $overview && '' !== trim( $overview ) ) {
			return $overview;
		}

		return $product->description();
	}

	/**
	 * Build filter-bar view data.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string> $selectedCategories The effective category slugs.
	 * @param array<int, string> $selectedTags       The effective tag slugs.
	 * @return array{action_url: string, categories: array<int, array{slug: string, name: string, depth: int}>, tags: array<int, array{slug: string, name: string}>, selected_category: string, selected_tags: array<int, string>}
	 */
	private function filterBarData( array $selectedCategories, array $selectedTags ): array {
		$tags = array();
		foreach ( $this->terms->listByTaxonomy( ProductTaxonomy::Tag ) as $tag ) {
			$tags[] = array(
				'slug' => $tag->slug(),
				'name' => $tag->name(),
			);
		}

		return array(
			'action_url'        => (string) remove_query_arg( array( self::GET_CATEGORY, self::GET_TAG ) ),
			'categories'        => $this->categoryOptions( $this->terms->listByTaxonomy( ProductTaxonomy::Category ) ),
			'tags'              => $tags,
			// The category control can show only the first selected slug.
			'selected_category' => $selectedCategories[0] ?? '',
			'selected_tags'     => $selectedTags,
		);
	}

	/**
	 * Flatten category terms into depth-aware options.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, Term> $terms The category terms to order.
	 * @return array<int, array{slug: string, name: string, depth: int}>
	 */
	private function categoryOptions( array $terms ): array {
		$childrenByParent = array();
		foreach ( $terms as $term ) {
			$childrenByParent[ $term->parent() ][] = $term;
		}

		$ordered = array();
		$visited = array();
		$this->appendCategoryLevel( $childrenByParent, 0, 0, $ordered, $visited );

		// Keep categories whose parent is missing from the result.
		foreach ( $terms as $term ) {
			$id = (int) $term->id();
			if ( isset( $visited[ $id ] ) ) {
				continue;
			}
			$visited[ $id ] = true;
			$ordered[]      = array(
				'slug'  => $term->slug(),
				'name'  => $term->name(),
				'depth' => 0,
			);
		}

		return $ordered;
	}

	/**
	 * Depth-first append of one category level and its descendants.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, array<int, Term>>                              $childrenByParent Terms grouped by parent id.
	 * @param int                                                       $parentId         The parent id to expand.
	 * @param int                                                       $depth            The current tree depth.
	 * @param array<int, array{slug: string, name: string, depth: int}> $ordered        Accumulating option list, by reference.
	 * @param array<int, bool>                                          $visited          Visited term ids, by reference.
	 * @return void
	 */
	private function appendCategoryLevel( array $childrenByParent, int $parentId, int $depth, array &$ordered, array &$visited ): void {
		foreach ( $childrenByParent[ $parentId ] ?? array() as $term ) {
			$id = (int) $term->id();
			if ( isset( $visited[ $id ] ) ) {
				continue;
			}
			$visited[ $id ] = true;
			$ordered[]      = array(
				'slug'  => $term->slug(),
				'name'  => $term->name(),
				'depth' => $depth,
			);
			$this->appendCategoryLevel( $childrenByParent, $id, $depth + 1, $ordered, $visited );
		}
	}

	/**
	 * Limit visitor filters to the author's fixed selection.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string> $authorSlugs  The author's fixed slugs.
	 * @param array<int, string> $visitorSlugs The visitor's chosen slugs.
	 * @return array<int, string> The effective slugs to query.
	 */
	private function narrow( array $authorSlugs, array $visitorSlugs ): array {
		if ( array() === $authorSlugs ) {
			return $visitorSlugs;
		}

		if ( array() === $visitorSlugs ) {
			return $authorSlugs;
		}

		$inside = array_values( array_intersect( $visitorSlugs, $authorSlugs ) );

		// Preserve the author's selection when no visitor value matches.
		return array() === $inside ? $authorSlugs : $inside;
	}

	/**
	 * Normalize attribute slugs.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value The raw attribute value.
	 * @return array<int, string> The cleaned slug list.
	 */
	private function slugList( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$slugs = array();
		foreach ( $value as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}
			$slug = (string) $item;
			if ( '' !== $slug ) {
				$slugs[] = $slug;
			}
		}

		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Read sanitized visitor filter slugs from GET.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key The GET key to read.
	 * @return array<int, string> The sanitized visitor slugs.
	 */
	private function visitorSelection( string $key ): array {
		// This public filter only reads state from a shareable URL.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw = isset( $_GET[ $key ] ) ? wp_unslash( $_GET[ $key ] ) : array();

		$slugs = array();
		foreach ( (array) $raw as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$slug = sanitize_title( (string) $value );
			if ( '' !== $slug ) {
				$slugs[] = $slug;
			}
		}

		return array_values( array_unique( $slugs ) );
	}
}
