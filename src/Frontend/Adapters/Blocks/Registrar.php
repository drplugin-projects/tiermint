<?php
/**
 * Auto-generates tiermint/* dynamic blocks from the component registry.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend\Adapters\Blocks;

use DrPlugin\WP\TierMint\Core\Hookable;
use DrPlugin\WP\TierMint\Frontend\AssetManager;
use DrPlugin\WP\TierMint\Frontend\AttributeResolver;
use DrPlugin\WP\TierMint\Frontend\ComponentRegistry;
use DrPlugin\WP\TierMint\Frontend\RenderContextResolver;

defined( 'ABSPATH' ) || exit;

/**
 * Registers dynamic blocks for components with block metadata.
 *
 * Schema values drive both editor controls and rendered output.
 *
 * @since 1.0.0
 */
class Registrar implements Hookable {

	/**
	 * The block category slug every TierMint block registers under.
	 *
	 * @var string
	 */
	public const CATEGORY_SLUG = 'tiermint';

	/**
	 * The component registry to expose as blocks.
	 *
	 * @var ComponentRegistry
	 */
	private ComponentRegistry $registry;

	/**
	 * Schema-driven attribute validation shared by every adapter.
	 *
	 * @var AttributeResolver
	 */
	private AttributeResolver $attributes;

	/**
	 * Schema-to-block-attribute generation.
	 *
	 * @var BlockAttributes
	 */
	private BlockAttributes $blockAttributes;

	/**
	 * Per-request context resolution.
	 *
	 * @var RenderContextResolver
	 */
	private RenderContextResolver $context;

	/**
	 * Render-time asset enqueueing.
	 *
	 * @var AssetManager
	 */
	private AssetManager $assets;

	/**
	 * Wire the adapter's collaborators.
	 *
	 * @param ComponentRegistry     $registry        Registered components.
	 * @param AttributeResolver     $attributes      Schema-driven attribute mapping.
	 * @param BlockAttributes       $blockAttributes Block attribute generation.
	 * @param RenderContextResolver $context         Per-request context resolver.
	 * @param AssetManager          $assets          Render-time asset enqueueing.
	 */
	public function __construct(
		ComponentRegistry $registry,
		AttributeResolver $attributes,
		BlockAttributes $blockAttributes,
		RenderContextResolver $context,
		AssetManager $assets
	) {
		$this->registry        = $registry;
		$this->attributes      = $attributes;
		$this->blockAttributes = $blockAttributes;
		$this->context         = $context;
		$this->assets          = $assets;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'init', array( $this, 'registerBlocks' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueueEditorAssets' ) );
		add_filter( 'block_categories_all', array( $this, 'addBlockCategory' ) );
	}

	/**
	 * Register blocks for components with local block metadata.
	 *
	 * @return void
	 */
	public function registerBlocks(): void {
		foreach ( $this->registry->all() as $slug => $component ) {
			$dir = $this->blocksBasePath() . $slug;

			if ( ! file_exists( $dir . '/block.json' ) ) {
				continue;
			}

			// Idempotence: a re-fired init (tests, misbehaving plugins)
			// must not trip core's already-registered notice.
			$name = $this->blockName( $slug );
			if ( null !== $name && $this->isBlockRegistered( $name ) ) {
				continue;
			}

			register_block_type(
				$dir,
				array( 'attributes' => $this->blockAttributes->fromSchema( $component->schema() ) )
			);
		}
	}

	/**
	 * Check whether core has registered a block name.
	 *
	 * @param string $name The full block name, e.g. tiermint/buy-button.
	 * @return bool
	 */
	protected function isBlockRegistered( string $name ): bool {
		return class_exists( '\WP_Block_Type_Registry' )
			&& \WP_Block_Type_Registry::get_instance()->is_registered( $name );
	}

	/**
	 * Add the TierMint block category.
	 *
	 * @param array<int, array<string, mixed>> $categories Registered block categories.
	 * @return array<int, array<string, mixed>>
	 */
	public function addBlockCategory( array $categories ): array {
		foreach ( $categories as $category ) {
			if ( self::CATEGORY_SLUG === ( $category['slug'] ?? '' ) ) {
				return $categories;
			}
		}

		$categories[] = array(
			'slug'  => self::CATEGORY_SLUG,
			'title' => __( 'TierMint', 'tiermint' ),
		);

		return $categories;
	}

	/**
	 * Render a block through the shared component pipeline.
	 *
	 * Unknown stored blocks render empty instead of stopping the page.
	 *
	 * @param string               $slug       Component slug the block maps to.
	 * @param array<string, mixed> $attributes Stored block attributes.
	 * @return string Escaped HTML, or '' when the component is unknown.
	 */
	public function renderBlock( string $slug, array $attributes ): string {
		$component = $this->registry->get( $slug );

		if ( null === $component ) {
			return '';
		}

		$resolved = $this->attributes->resolve( $component->schema(), $attributes );

		$this->assets->enqueueFor( $component );

		return $component->render( $resolved, $this->context->resolve() );
	}

	/**
	 * Enqueue the shared block editor script when it is built.
	 *
	 * @return void
	 */
	public function enqueueEditorAssets(): void {
		$assetFile = $this->editorAssetPath();

		if ( ! file_exists( $assetFile ) ) {
			return;
		}

		$asset = include $assetFile;

		wp_enqueue_script(
			'tiermint-blocks-editor',
			TIERMINT_URL . 'assets/blocks/build/index.js',
			$asset['dependencies'] ?? array(),
			(string) ( $asset['version'] ?? TIERMINT_VERSION ),
			true
		);

		/* Load translations for the editor script. */
		wp_set_script_translations( 'tiermint-blocks-editor', 'tiermint', TIERMINT_PATH . 'languages' );

		wp_add_inline_script(
			'tiermint-blocks-editor',
			'window.tierMintBlocks = ' . wp_json_encode( array( 'blocks' => $this->editorBlockData() ) ) . ';',
			'before'
		);
	}

	/**
	 * Build editor controls that block-type metadata does not provide.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function editorBlockData(): array {
		$blocks = array();

		foreach ( $this->registry->all() as $slug => $component ) {
			$name = $this->blockName( $slug );

			if ( null === $name ) {
				continue;
			}

			$controls = array();
			foreach ( $component->schema() as $attribute => $definition ) {
				// The reserved Pro gating bucket is not an attribute.
				if ( 'pro' === $attribute ) {
					continue;
				}

				$controls[] = array(
					'name'        => $attribute,
					'type'        => (string) ( $definition['type'] ?? 'string' ),
					'label'       => (string) ( $definition['label'] ?? $attribute ),
					'description' => (string) ( $definition['description'] ?? '' ),
					'control'     => (string) ( $definition['control'] ?? 'text' ),
					'options'     => array_map( 'strval', (array) ( $definition['enum'] ?? array() ) ),
					'items'       => (string) ( $definition['items'] ?? 'string' ),
					// Picker settings not available in block-type metadata.
					'taxonomy'    => (string) ( $definition['taxonomy'] ?? '' ),
					'routed'      => (bool) ( $definition['routed'] ?? false ),
					'zero_label'  => (string) ( $definition['zero_label'] ?? '' ),
				);
			}

			$blocks[] = array(
				'name'     => $name,
				'controls' => $controls,
			);
		}

		return $blocks;
	}

	/**
	 * Get the built editor asset manifest path.
	 *
	 * @return string
	 */
	protected function editorAssetPath(): string {
		return TIERMINT_PATH . 'assets/blocks/build/index.asset.php';
	}

	/**
	 * Get a component block name from its metadata.
	 *
	 * @param string $slug Component slug.
	 * @return string|null
	 */
	protected function blockName( string $slug ): ?string {
		$file = $this->blocksBasePath() . $slug . '/block.json';

		if ( ! file_exists( $file ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the plugin's own block metadata, not a remote resource.
		$metadata = json_decode( (string) file_get_contents( $file ), true );

		$name = is_array( $metadata ) ? (string) ( $metadata['name'] ?? '' ) : '';

		return '' !== $name ? $name : 'tiermint/' . $slug;
	}

	/**
	 * Get the block metadata directory.
	 *
	 * @return string
	 */
	protected function blocksBasePath(): string {
		return TIERMINT_PATH . 'blocks/';
	}
}
