<?php
/**
 * Lightweight PSR-11 service container.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Core;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Explicit, dependency-free service container.
 *
 * Bindings are lazy singletons unless registered with {@see factory()}. It mirrors
 * PSR-11 without its runtime dependency.
 *
 * @since 1.0.0
 */
final class Container {

	/**
	 * Service factories keyed by id.
	 *
	 * @var array<string, callable>
	 */
	private array $bindings = array();

	/**
	 * Resolved singleton instances keyed by id.
	 *
	 * @var array<string, mixed>
	 */
	private array $resolved = array();

	/**
	 * Factory binding ids.
	 *
	 * @var array<string, true>
	 */
	private array $factories = array();

	/**
	 * Bind a lazy singleton.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $id      Service id (typically a class/interface name).
	 * @param callable $factory Receives this container, returns the service.
	 * @return void
	 */
	public function singleton( string $id, callable $factory ): void {
		$this->bindings[ $id ] = $factory;
		unset( $this->factories[ $id ], $this->resolved[ $id ] );
	}

	/**
	 * Bind a service that is rebuilt for each resolution.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $id      Service id.
	 * @param callable $factory Receives this container, returns a fresh service.
	 * @return void
	 */
	public function factory( string $id, callable $factory ): void {
		$this->bindings[ $id ]  = $factory;
		$this->factories[ $id ] = true;
		unset( $this->resolved[ $id ] );
	}

	/**
	 * Bind an existing value.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id    Service id.
	 * @param mixed  $value The pre-built service instance or value.
	 * @return void
	 */
	public function instance( string $id, $value ): void {
		$this->resolved[ $id ] = $value;
		// Keep a binding so has() and extend() work for instances.
		$this->bindings[ $id ] = static function () use ( $value ) {
			return $value;
		};
		unset( $this->factories[ $id ] );
	}

	/**
	 * Resolve a service by id.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id Service id.
	 * @return mixed The resolved service.
	 *
	 * @throws ServiceNotFoundException When no binding exists for $id.
	 */
	public function get( string $id ) {
		// array_key_exists() also finds stored null values.
		if ( array_key_exists( $id, $this->resolved ) ) {
			return $this->resolved[ $id ];
		}

		if ( ! isset( $this->bindings[ $id ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer exception; $id is a trusted internal service id, never user input or HTML output.
			throw ServiceNotFoundException::forId( $id );
		}

		$service = ( $this->bindings[ $id ] )( $this );

		// Factory bindings are never cached.
		if ( ! isset( $this->factories[ $id ] ) ) {
			$this->resolved[ $id ] = $service;
		}

		return $service;
	}

	/**
	 * Check whether an id is bound.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id Service id.
	 * @return bool True when the id can be resolved.
	 */
	public function has( string $id ): bool {
		return isset( $this->bindings[ $id ] ) || array_key_exists( $id, $this->resolved );
	}

	/**
	 * Decorate an existing binding.
	 *
	 * Pro uses this to wrap a core service. The next resolution rebuilds the service.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $id        Service id to decorate.
	 * @param callable $decorator Receives ( mixed $previous, Container $c ), returns the new service.
	 * @return void
	 *
	 * @throws ServiceNotFoundException When no binding exists for $id.
	 */
	public function extend( string $id, callable $decorator ): void {
		if ( ! isset( $this->bindings[ $id ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer exception; $id is a trusted internal service id, never user input or HTML output.
			throw ServiceNotFoundException::forId( $id );
		}

		$previousFactory = $this->bindings[ $id ];

		$this->bindings[ $id ] = static function ( Container $c ) use ( $previousFactory, $decorator ) {
			return $decorator( $previousFactory( $c ), $c );
		};

		// Rebuild the decorated service on its next resolution.
		unset( $this->resolved[ $id ] );
	}
}
