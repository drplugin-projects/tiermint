<?php
/**
 * The registry of front-end components.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the component list shared by all frontend adapters.
 *
 * Filter entries with a duplicate slug replace earlier components.
 *
 * @since 1.0.0
 */
class ComponentRegistry {

	/**
	 * Default component class names, before the filter.
	 *
	 * @var array<int, class-string>
	 */
	private array $defaultClasses;

	/**
	 * Resolves a class name to its instance (backed by the container).
	 *
	 * @var callable(string): object
	 */
	private $instantiator;

	/**
	 * Resolved components keyed by slug; null until first access.
	 *
	 * @var array<string, Component>|null
	 */
	private ?array $components = null;

	/**
	 * Wire the default class list and the container-backed instantiator.
	 *
	 * @param array<int, class-string> $defaultClasses Component classes shipped by core.
	 * @param callable                 $instantiator   fn (string $className): object, usually Container::get.
	 */
	public function __construct( array $defaultClasses, callable $instantiator ) {
		$this->defaultClasses = $defaultClasses;
		$this->instantiator   = $instantiator;
	}

	/**
	 * All components, keyed by slug.
	 *
	 * @return array<string, Component>
	 */
	public function all(): array {
		return $this->components();
	}

	/**
	 * Whether a component with this slug is registered.
	 *
	 * @param string $slug Component slug.
	 * @return bool
	 */
	public function has( string $slug ): bool {
		return isset( $this->components()[ $slug ] );
	}

	/**
	 * The component registered under a slug.
	 *
	 * @param string $slug Component slug.
	 * @return Component|null Null when the slug is unknown.
	 */
	public function get( string $slug ): ?Component {
		return $this->components()[ $slug ] ?? null;
	}

	/**
	 * Resolve filtered component classes on first access.
	 *
	 * @return array<string, Component>
	 */
	private function components(): array {
		if ( null !== $this->components ) {
			return $this->components;
		}

		/**
 * Filters registered frontend component classes.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, class-string> $classes Component class names.
		 */
		$classes = apply_filters( 'tiermint_frontend_components', $this->defaultClasses );

		$this->components = array();

		foreach ( (array) $classes as $className ) {
			$component = null;

			if ( is_string( $className ) && is_subclass_of( $className, Component::class ) ) {
				$instance = ( $this->instantiator )( $className );
				// The instantiator is caller-supplied, so re-check the instance.
				$component = $instance instanceof Component ? $instance : null;
			}

			if ( null === $component ) {
				_doing_it_wrong(
					__METHOD__,
					esc_html(
						sprintf(
							/* translators: 1: the rejected class name, 2: the Component interface name. */
							__( 'Class "%1$s" added via tiermint_frontend_components must implement %2$s.', 'tiermint' ),
							is_string( $className ) ? $className : gettype( $className ),
							Component::class
						)
					),
					'1.0.0'
				);
				continue;
			}

			$this->components[ $component->slug() ] = $component;
		}

		return $this->components;
	}
}
