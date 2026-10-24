<?php
/**
 * Schema-driven attribute validation and coercion.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves adapter input to typed component attributes.
 *
 * Invalid values use defaults so rendering cannot fail.
 *
 * @since 1.0.0
 */
class AttributeResolver {

	/**
	 * Spellings accepted as boolean true from shortcode/builder input.
	 *
	 * @var array<int, string>
	 */
	private const TRUTHY = array( 'true', '1', 'yes', 'on' );

	/**
	 * Spellings accepted as boolean false from shortcode/builder input.
	 *
	 * @var array<int, string>
	 */
	private const FALSEY = array( 'false', '0', 'no', 'off', '' );

	/**
	 * Resolve raw input against a component schema.
	 *
	 * @param array<string, array<string, mixed>> $schema Component schema.
	 * @param array<string, mixed>                $input  Raw adapter input.
	 * @return array<string, mixed> Typed values for every schema attribute.
	 */
	public function resolve( array $schema, array $input ): array {
		$resolved = array();

		foreach ( $schema as $name => $definition ) {
			$default = $this->defaultFor( $definition );

			if ( ! array_key_exists( $name, $input ) ) {
				$resolved[ $name ] = $default;
				continue;
			}

			$resolved[ $name ] = $this->coerce( $definition, $input[ $name ], $default );
		}

		return $resolved;
	}

	/**
	 * Get a definition default, or its type default.
	 *
	 * @param array<string, mixed> $definition Attribute definition.
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

	/**
	 * Coerce one raw value to its declared type.
	 *
	 * @param array<string, mixed> $definition Attribute definition.
	 * @param mixed                $value      Raw input value.
	 * @param mixed                $fallback   Fallback when coercion fails.
	 * @return mixed
	 */
	private function coerce( array $definition, $value, $fallback ) {
		switch ( $definition['type'] ?? 'string' ) {
			case 'integer':
				return is_numeric( $value ) ? (int) $value : $fallback;

			case 'number':
				return is_numeric( $value ) ? (float) $value : $fallback;

			case 'boolean':
				return $this->coerceBoolean( $value, $fallback );

			case 'array':
				return $this->coerceArray( $definition, $value );

			case 'enum':
				$options = array_map( 'strval', (array) ( $definition['enum'] ?? array() ) );
				return in_array( (string) $value, $options, true ) ? (string) $value : $fallback;

			default:
				return is_scalar( $value ) ? (string) $value : $fallback;
		}
	}

	/**
	 * Coerce standard boolean input; invalid values keep the default.
	 *
	 * @param mixed $value    Raw input value.
	 * @param mixed $fallback Fallback for unrecognized spellings.
	 * @return bool
	 */
	private function coerceBoolean( $value, $fallback ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) ) {
			$value = (string) $value;
		}

		if ( is_string( $value ) ) {
			$normalized = strtolower( trim( $value ) );
			if ( in_array( $normalized, self::TRUTHY, true ) ) {
				return true;
			}
			if ( in_array( $normalized, self::FALSEY, true ) ) {
				return false;
			}
		}

		return (bool) $fallback;
	}

	/**
	 * Coerce an array or comma-separated shortcode value.
	 *
	 * @param array<string, mixed> $definition Attribute definition ('items' key).
	 * @param mixed                $value      Raw input value.
	 * @return array<int, mixed>
	 */
	private function coerceArray( array $definition, $value ): array {
		if ( is_string( $value ) ) {
			$value = array_filter( array_map( 'trim', explode( ',', $value ) ), static fn ( string $part ): bool => '' !== $part );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$items  = (string) ( $definition['items'] ?? 'string' );
		$result = array();

		foreach ( $value as $item ) {
			if ( 'integer' === $items ) {
				if ( is_numeric( $item ) ) {
					$result[] = (int) $item;
				}
				continue;
			}

			if ( 'number' === $items ) {
				if ( is_numeric( $item ) ) {
					$result[] = (float) $item;
				}
				continue;
			}

			if ( is_scalar( $item ) ) {
				$result[] = (string) $item;
			}
		}

		return $result;
	}
}
