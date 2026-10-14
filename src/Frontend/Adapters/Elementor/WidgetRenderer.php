<?php
/**
 * The Elementor widget render delegate.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend\Adapters\Elementor;

use DrPlugin\WP\TierMint\Frontend\AssetManager;
use DrPlugin\WP\TierMint\Frontend\AttributeResolver;
use DrPlugin\WP\TierMint\Frontend\ComponentRegistry;
use DrPlugin\WP\TierMint\Frontend\RenderContextResolver;

defined( 'ABSPATH' ) || exit;

/**
 * Renders Elementor widgets through the shared component pipeline.
 *
 * Elementor widgets use this delegate because they cannot use constructor injection.
 *
 * @since 1.0.0
 */
class WidgetRenderer {

	/**
	 * The component registry the widget's slug resolves against.
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
	 * Render-time asset enqueueing.
	 *
	 * @var AssetManager
	 */
	private AssetManager $assets;

	/**
	 * Per-request context resolution.
	 *
	 * @var RenderContextResolver
	 */
	private RenderContextResolver $context;

	/**
	 * Schema-to-control mapping for register_controls().
	 *
	 * @var ControlMap
	 */
	private ControlMap $controlMap;

	/**
	 * The curated style-control set for register_controls().
	 *
	 * @var StyleControlMap
	 */
	private StyleControlMap $styleMap;

	/**
	 * Picker options provider for the Elementor editor.
	 *
	 * @var callable(string, array<string, mixed>): array<string, string>
	 */
	private $optionsProvider;

	/**
	 * Wire the delegate's collaborators.
	 *
	 * @param ComponentRegistry     $registry        Registered components.
	 * @param AttributeResolver     $attributes      Schema-driven attribute mapping.
	 * @param AssetManager          $assets          Render-time asset enqueueing.
	 * @param RenderContextResolver $context         Per-request context resolver.
	 * @param ControlMap            $controlMap      Schema-to-control mapping.
	 * @param StyleControlMap       $styleMap        Curated style controls.
	 * @param callable              $optionsProvider Picker options provider.
	 */
	public function __construct(
		ComponentRegistry $registry,
		AttributeResolver $attributes,
		AssetManager $assets,
		RenderContextResolver $context,
		ControlMap $controlMap,
		StyleControlMap $styleMap,
		callable $optionsProvider
	) {
		$this->registry        = $registry;
		$this->attributes      = $attributes;
		$this->assets          = $assets;
		$this->context         = $context;
		$this->controlMap      = $controlMap;
		$this->styleMap        = $styleMap;
		$this->optionsProvider = $optionsProvider;
	}

	/**
	 * Render a widget from its saved Elementor settings.
	 *
	 * Unknown widgets render empty instead of stopping the page.
	 *
	 * @param string               $slug     Component slug the widget maps to.
	 * @param array<string, mixed> $settings The widget's Elementor settings.
	 * @return string Escaped HTML, or '' when the component is unknown.
	 */
	public function renderFor( string $slug, array $settings ): string {
		$component = $this->registry->get( $slug );

		if ( null === $component ) {
			return '';
		}

		$schema   = $component->schema();
		$attrs    = $this->mapSettings( $schema, $settings );
		$resolved = $this->attributes->resolve( $schema, $attrs );

		$this->assets->enqueueFor( $component );

		return $component->render( $resolved, $this->context->resolve() );
	}

	/**
	 * Get control arguments for a component.
	 *
	 * @param string $slug Component slug.
	 * @return array<string, array<string, mixed>> Control args keyed by attribute name.
	 */
	public function controlsFor( string $slug ): array {
		$component = $this->registry->get( $slug );

		if ( null === $component ) {
			return array();
		}

		return $this->controlMap->fromSchema( $component->schema(), $this->optionsProvider );
	}

	/**
	 * The curated style-control arguments every widget shares.
	 *
	 * @return array<string, array<string, mixed>> Control args keyed by control id.
	 */
	public function styleControls(): array {
		return $this->styleMap->controls();
	}

	/**
	 * Map saved Elementor settings to raw schema attributes.
	 *
	 * URL controls store a compound value, so their URL is extracted first.
	 *
	 * @param array<string, array<string, mixed>> $schema   The component schema.
	 * @param array<string, mixed>                $settings The widget settings.
	 * @return array<string, mixed>
	 */
	private function mapSettings( array $schema, array $settings ): array {
		$mapped = array();

		foreach ( $schema as $name => $definition ) {
			if ( 'pro' === $name || ! is_array( $definition ) || ! array_key_exists( $name, $settings ) ) {
				continue;
			}

			$value = $settings[ $name ];

			if ( 'url' === ( $definition['control'] ?? '' ) && is_array( $value ) ) {
				$value = isset( $value['url'] ) ? (string) $value['url'] : '';
			}

			$mapped[ $name ] = $value;
		}

		return $mapped;
	}
}
