<?php
/**
 * The curated Elementor style controls, each writing a design token.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend\Adapters\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * Maps shared design tokens to Elementor style controls.
 *
 * Elementor scopes the generated CSS to each widget wrapper.
 *
 * @since 1.0.0
 */
class StyleControlMap {

	/**
	 * Slider control type string, mirroring \Elementor\Controls_Manager::SLIDER.
	 *
	 * @var string
	 */
	private const TYPE_SLIDER = 'slider';

	/**
	 * The style controls, keyed by control id.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>> Elementor control args per control id.
	 */
	public function controls(): array {
		return array(
			'tiermint_color_primary'  => array(
				'label'     => __( 'Primary color', 'tiermint' ),
				'type'      => ControlMap::TYPE_COLOR,
				'selectors' => array( '{{WRAPPER}}' => '--tiermint-color-primary: {{VALUE}};' ),
			),
			'tiermint_color_text'     => array(
				'label'     => __( 'Text color', 'tiermint' ),
				'type'      => ControlMap::TYPE_COLOR,
				'selectors' => array( '{{WRAPPER}}' => '--tiermint-color-text: {{VALUE}};' ),
			),
			'tiermint_color_surface'  => array(
				'label'     => __( 'Surface color', 'tiermint' ),
				'type'      => ControlMap::TYPE_COLOR,
				'selectors' => array( '{{WRAPPER}}' => '--tiermint-color-surface: {{VALUE}};' ),
			),
			'tiermint_radius'         => array(
				'label'      => __( 'Corner radius', 'tiermint' ),
				'type'       => self::TYPE_SLIDER,
				'size_units' => array( 'px', 'em', 'rem' ),
				'selectors'  => array( '{{WRAPPER}}' => '--tiermint-radius: {{SIZE}}{{UNIT}};' ),
			),
			'tiermint_font_size_base' => array(
				'label'      => __( 'Base font size', 'tiermint' ),
				'type'       => self::TYPE_SLIDER,
				'size_units' => array( 'px', 'em', 'rem' ),
				'selectors'  => array( '{{WRAPPER}}' => '--tiermint-font-size-base: {{SIZE}}{{UNIT}};' ),
			),
		);
	}
}
