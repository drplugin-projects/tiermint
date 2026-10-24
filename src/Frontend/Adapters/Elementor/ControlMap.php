<?php
/**
 * Maps a component schema to Elementor control arguments.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend\Adapters\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * Maps component schemas to Elementor control arguments.
 *
 * Picker options load only in the editor, not during front-end rendering.
 *
 * @since 1.0.0
 */
class ControlMap {

	/**
	 * Elementor control type strings.
	 *
	 * @var string
	 */
	public const TYPE_TEXT     = 'text';
	public const TYPE_NUMBER   = 'number';
	public const TYPE_SWITCHER = 'switcher';
	public const TYPE_SELECT   = 'select';
	public const TYPE_SELECT2  = 'select2';
	public const TYPE_URL      = 'url';
	public const TYPE_COLOR    = 'color';

	/**
	 * Build the control arguments for every attribute in a schema.
	 *
	 * @param array<string, array<string, mixed>> $schema          The component schema.
	 * @param callable                            $optionsProvider fn (string $kind, array $definition): array<string,string>,
	 *                                                             invoked lazily for `product`/`term` pickers only.
	 * @return array<string, array<string, mixed>> Control args keyed by attribute name.
	 */
	public function fromSchema( array $schema, callable $optionsProvider ): array {
		$controls = array();

		foreach ( $schema as $name => $definition ) {
			if ( ! is_array( $definition ) ) {
				continue;
			}

			$controls[ $name ] = $this->controlFor( $definition, $optionsProvider );
		}

		return $controls;
	}

	/**
	 * Build the Elementor control arguments for one attribute definition.
	 *
	 * @param array<string, mixed> $definition      The attribute definition.
	 * @param callable             $optionsProvider The picker options provider.
	 * @return array<string, mixed>
	 */
	private function controlFor( array $definition, callable $optionsProvider ): array {
		$label   = (string) ( $definition['label'] ?? '' );
		$control = (string) ( $definition['control'] ?? 'text' );

		$base = array( 'label' => $label );

		if ( isset( $definition['description'] ) && '' !== (string) $definition['description'] ) {
			$base['description'] = (string) $definition['description'];
		}

		switch ( $control ) {
			case 'number':
				return $base + array(
					'type'    => self::TYPE_NUMBER,
					'default' => $definition['default'] ?? 0,
				);

			case 'toggle':
				return $base + array(
					'type'         => self::TYPE_SWITCHER,
					'return_value' => 'yes',
					'default'      => ! empty( $definition['default'] ) ? 'yes' : '',
				);

			case 'select':
				$options = array();
				foreach ( (array) ( $definition['enum'] ?? array() ) as $value ) {
					$options[ (string) $value ] = (string) $value;
				}
				return $base + array(
					'type'    => self::TYPE_SELECT,
					'options' => $options,
					'default' => (string) ( $definition['default'] ?? '' ),
				);

			case 'url':
				return $base + array( 'type' => self::TYPE_URL );

			case 'color':
				return $base + array( 'type' => self::TYPE_COLOR );

			case 'product_select':
				return $base + $this->pickerControl( 'product', $definition, $optionsProvider );

			case 'term_select':
				return $base + $this->pickerControl( 'term', $definition, $optionsProvider );

			case 'page_select':
				$picker = $this->pickerControl( 'page', $definition, $optionsProvider );
				// A page picker defaults to its labelled site-wide setting.
				$picker['default'] = '0';
				return $base + $picker;

			case 'text':
			default:
				return $base + array(
					'type'    => self::TYPE_TEXT,
					'default' => (string) ( $definition['default'] ?? '' ),
				);
		}
	}

	/**
	 * Build a single or multiple SELECT2 picker control.
	 *
	 * @param string               $kind            Picker kind (`product`, `term`, or `page`).
	 * @param array<string, mixed> $definition      The attribute definition.
	 * @param callable             $optionsProvider The picker options provider.
	 * @return array<string, mixed>
	 */
	private function pickerControl( string $kind, array $definition, callable $optionsProvider ): array {
		$multiple = 'array' === ( $definition['type'] ?? '' ) || ! empty( $definition['multiple'] );

		return array(
			'type'        => self::TYPE_SELECT2,
			'options'     => (array) $optionsProvider( $kind, $definition ),
			'multiple'    => $multiple,
			'label_block' => true,
			'default'     => $multiple ? array() : '',
		);
	}
}
