<?php
/**
 * Generates block.json-style attribute definitions from component schemas.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend\Adapters\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Builds block attributes from component schemas.
 *
 * Editor values use the same defaults as server-side values.
 *
 * @since 1.0.0
 */
class BlockAttributes {

	/**
	 * Build block attributes from a component schema.
	 *
	 * @param array<string, array<string, mixed>> $schema Component schema.
	 * @return array<string, array<string, mixed>> block.json attribute definitions.
	 */
	public function fromSchema( array $schema ): array {
		$attributes = array();

		foreach ( $schema as $name => $definition ) {
			$attributes[ $name ] = $this->definitionFor( $definition );
		}

		return $attributes;
	}

	/**
	 * Map one schema definition to a block attribute definition.
	 *
	 * @param array<string, mixed> $definition Schema attribute definition.
	 * @return array<string, mixed>
	 */
	private function definitionFor( array $definition ): array {
		$type = (string) ( $definition['type'] ?? 'string' );

		switch ( $type ) {
			case 'integer':
			case 'number':
			case 'boolean':
				return array(
					'type'    => $type,
					'default' => $this->defaultFor( $definition ),
				);

			case 'array':
				return array(
					'type'    => 'array',
					'items'   => array( 'type' => (string) ( $definition['items'] ?? 'string' ) ),
					'default' => $this->defaultFor( $definition ),
				);

			case 'enum':
				return array(
					'type'    => 'string',
					'enum'    => array_map( 'strval', (array) ( $definition['enum'] ?? array() ) ),
					'default' => $this->defaultFor( $definition ),
				);

			default:
				return array(
					'type'    => 'string',
					'default' => $this->defaultFor( $definition ),
				);
		}
	}

	/**
	 * Get a schema default, using the resolver fallback when absent.
	 *
	 * @param array<string, mixed> $definition Schema attribute definition.
	 * @return mixed
	 */
	private function defaultFor( array $definition ) {
		if ( array_key_exists( 'default', $definition ) ) {
			return $definition['default'];
		}

		switch ( $definition['type'] ?? 'string' ) {
			case 'integer':
				return 0;
			case 'number':
				return 0.0;
			case 'boolean':
				return false;
			case 'array':
				return array();
			case 'enum':
				$options = (array) ( $definition['enum'] ?? array() );
				return array() !== $options ? (string) reset( $options ) : '';
			default:
				return '';
		}
	}
}
