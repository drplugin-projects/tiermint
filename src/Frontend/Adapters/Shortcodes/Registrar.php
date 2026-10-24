<?php
/**
 * Auto-generates [tiermint_*] shortcodes from the component registry.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend\Adapters\Shortcodes;

use DrPlugin\WP\TierMint\Core\Hookable;
use DrPlugin\WP\TierMint\Frontend\AssetManager;
use DrPlugin\WP\TierMint\Frontend\AttributeResolver;
use DrPlugin\WP\TierMint\Frontend\Component;
use DrPlugin\WP\TierMint\Frontend\ComponentRegistry;
use DrPlugin\WP\TierMint\Frontend\Kses;
use DrPlugin\WP\TierMint\Frontend\RenderContextResolver;

defined( 'ABSPATH' ) || exit;

/**
 * Registers shortcodes for components in the registry.
 *
 * Shortcodes use the same schema and renderer as other adapters.
 *
 * @since 1.0.0
 */
class Registrar implements Hookable {

	/**
	 * Short aliases mapped to component slugs.
	 *
	 * @var array<string, string>
	 */
	public const ALIASES = array(
		'tiermint_buy'     => 'buy-button',
		'tiermint_pricing' => 'pricing-table',
		'tiermint_login'   => 'login-form',
	);

	/**
	 * The component registry to expose as shortcodes.
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
	 * @param ComponentRegistry     $registry   Registered components.
	 * @param AttributeResolver     $attributes Schema-driven att mapping.
	 * @param RenderContextResolver $context    Per-request context resolver.
	 * @param AssetManager          $assets     Render-time asset enqueueing.
	 */
	public function __construct(
		ComponentRegistry $registry,
		AttributeResolver $attributes,
		RenderContextResolver $context,
		AssetManager $assets
	) {
		$this->registry   = $registry;
		$this->attributes = $attributes;
		$this->context    = $context;
		$this->assets     = $assets;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'init', array( $this, 'registerShortcodes' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueueForQueriedContent' ) );
	}

	/**
	 * Pre-enqueue assets for shortcodes in the queried singular post.
	 *
	 * Rendering happens after wp_head, so this prevents styles from reaching the footer.
	 *
	 * @return void
	 */
	public function enqueueForQueriedContent(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post    = get_post();
		$content = is_object( $post ) && isset( $post->post_content ) ? (string) $post->post_content : '';

		if ( '' === $content ) {
			return;
		}

		foreach ( $this->registry->all() as $slug => $component ) {
			foreach ( $this->tagsFor( $slug ) as $tag ) {
				if ( has_shortcode( $content, $tag ) ) {
					$this->assets->enqueueFor( $component );
					break;
				}
			}
		}
	}

	/**
	 * Get the generated tag and aliases for a component.
	 *
	 * @param string $slug Component slug.
	 * @return array<int, string>
	 */
	private function tagsFor( string $slug ): array {
		$tags = array( 'tiermint_' . str_replace( '-', '_', $slug ) );

		foreach ( self::ALIASES as $tag => $aliasSlug ) {
			if ( $aliasSlug === $slug ) {
				$tags[] = $tag;
			}
		}

		return $tags;
	}

	/**
	 * Register every component's mechanical tag plus the documented aliases.
	 *
	 * @return void
	 */
	public function registerShortcodes(): void {
		foreach ( $this->registry->all() as $slug => $component ) {
			add_shortcode( 'tiermint_' . str_replace( '-', '_', $slug ), $this->callbackFor( $component ) );
		}

		foreach ( self::ALIASES as $tag => $slug ) {
			$component = $this->registry->get( $slug );
			if ( null !== $component ) {
				add_shortcode( $tag, $this->callbackFor( $component ) );
			}
		}
	}

	/**
	 * Build the shortcode callback for one component.
	 *
	 * @param Component $component The component to render.
	 * @return callable The shortcode handler.
	 */
	private function callbackFor( Component $component ): callable {
		return function ( $atts ) use ( $component ): string {
			// WordPress passes '' instead of an array when no atts are set.
			$raw = is_array( $atts ) ? $atts : array();

			$resolved = $this->attributes->resolve( $component->schema(), $raw );

			$this->assets->enqueueFor( $component );

			return wp_kses( $component->render( $resolved, $this->context->resolve() ), Kses::allowed() );
		};
	}
}
