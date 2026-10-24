<?php
/**
 * Registry resolving the active payment gateways.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Payments;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Registry of active payment gateways.
 *
 * It filters and caches gateways once per request. Each gateway id becomes its
 * map key. Tests extend this class.
 *
 * @since 1.0.0
 */
class GatewayRegistry {

	/**
	 * The resolved gateway map, or null before the first all() call.
	 *
	 * @var array<string, GatewayInterface>|null
	 */
	private ?array $resolved = null;

	/**
	 * Store the built-in gateway map.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, GatewayInterface> $defaults The gateways the core plugin ships, keyed by id.
	 */
	public function __construct( private readonly array $defaults = array() ) {}

	/**
	 * Return active gateways keyed by id.
	 *
	 * Filter once per request so each caller sees the same set.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, GatewayInterface>
	 */
	public function all(): array {
		if ( null !== $this->resolved ) {
			return $this->resolved;
		}

		/**
		 * Filters registered payment gateways.
 *
		 * Extensions add gateways here. Invalid values are ignored and ids become keys.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, GatewayInterface> $gateways The gateway map, seeded with the core defaults keyed by id.
		 */
		$filtered = apply_filters( 'tiermint_payment_gateways', $this->defaults );

		$this->resolved = array();

		foreach ( (array) $filtered as $gateway ) {
			if ( $gateway instanceof GatewayInterface ) {
				// Use the gateway id, not the filter array key.
				$this->resolved[ $gateway->id() ] = $gateway;
			}
		}

		return $this->resolved;
	}

	/**
	 * The gateway registered under the given id.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id The gateway id, e.g. 'stripe'.
	 * @return GatewayInterface
	 * @throws GatewayNotFoundException When no active gateway answers to the id.
	 */
	public function get( string $id ): GatewayInterface {
		$all = $this->all();

		if ( ! isset( $all[ $id ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw GatewayNotFoundException::forId( $id );
		}

		return $all[ $id ];
	}

	/**
	 * Whether a gateway is registered under the given id.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id The gateway id to probe.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->all()[ $id ] );
	}
}
