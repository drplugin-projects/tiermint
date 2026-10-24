<?php
/**
 * The generic Elementor widget over a TierMint component.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend\Adapters\Elementor\Widgets;

use DrPlugin\WP\TierMint\Frontend\Adapters\Elementor\Registrar;
use DrPlugin\WP\TierMint\Frontend\Adapters\Elementor\WidgetRenderer;
use DrPlugin\WP\TierMint\Frontend\Kses;
use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Base widget for each TierMint component.
 *
 * Elementor rebuilds saved widgets without injected services. The registrar
 * sets the shared renderer through a static accessor.
 *
 * @since 1.0.0
 */
abstract class ComponentWidget extends Widget_Base {

	/**
	 * The renderer set by the adapter registrar.
	 *
	 * @var WidgetRenderer|null
	 */
	private static ?WidgetRenderer $renderer = null;

	/**
	 * Hand every widget its render delegate.
	 *
	 * @param WidgetRenderer $renderer The shared render delegate.
	 * @return void
	 */
	public static function setRenderer( WidgetRenderer $renderer ): void {
		self::$renderer = $renderer;
	}

	/**
	 * The render delegate, or a clear failure if the adapter never set it.
	 *
	 * @return WidgetRenderer
	 * @throws RuntimeException When called before the registrar wired the delegate.
	 */
	protected static function renderer(): WidgetRenderer {
		if ( null === self::$renderer ) {
			throw new RuntimeException( 'TierMint Elementor widgets need their render delegate; the adapter sets it on register.' );
		}

		return self::$renderer;
	}

	/**
	 * The TierMint component slug this widget renders.
	 *
	 * @return string
	 */
	abstract protected function componentSlug(): string;

	/**
	 * The stable Elementor widget name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'tiermint-' . $this->componentSlug();
	}

	/**
	 * The editor category the widget files under.
	 *
	 * @return array<int, string>
	 */
	public function get_categories(): array {
		return array( Registrar::CATEGORY_SLUG );
	}

	/**
	 * Register component and style controls.
	 *
	 * @return void
	 */
	protected function register_controls(): void {
		$slug = $this->componentSlug();

		$this->start_controls_section(
			'tiermint_content',
			array(
				'label' => __( 'Content', 'tiermint' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		foreach ( self::renderer()->controlsFor( $slug ) as $id => $args ) {
			$this->add_control( $id, $args );
		}

		$this->end_controls_section();

		$this->start_controls_section(
			'tiermint_style',
			array(
				'label' => __( 'Style', 'tiermint' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		foreach ( self::renderer()->styleControls() as $id => $args ) {
			$this->add_control( $id, $args );
		}

		$this->end_controls_section();
	}

	/**
	 * Render the widget with its display settings.
	 *
	 * @return void
	 */
	protected function render(): void {
		echo wp_kses( self::renderer()->renderFor( $this->componentSlug(), $this->get_settings_for_display() ), Kses::allowed() );
	}
}
