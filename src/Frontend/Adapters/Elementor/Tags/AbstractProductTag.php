<?php
/**
 * Shared base for the free product dynamic tags.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend\Adapters\Elementor\Tags;

use DrPlugin\WP\TierMint\Frontend\Adapters\Elementor\Registrar;
use Elementor\Controls_Manager;
use Elementor\Core\DynamicTags\Tag;
use Elementor\Modules\DynamicTags\Module;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Shared base for product name and price tags.
 *
 * Tags use a static delegate because Elementor does not support constructor injection.
 *
 * @since 1.0.0
 */
abstract class AbstractProductTag extends Tag {

	/**
	 * The shared value delegate, set once by the adapter's registrar.
	 *
	 * @var TagValues|null
	 */
	private static ?TagValues $values = null;

	/**
	 * Hand every tag its value delegate.
	 *
	 * @param TagValues $values The shared value delegate.
	 * @return void
	 */
	public static function setValues( TagValues $values ): void {
		self::$values = $values;
	}

	/**
	 * Get the value delegate.
	 *
	 * @return TagValues
	 * @throws RuntimeException When called before the registrar wired the delegate.
	 */
	protected static function values(): TagValues {
		if ( null === self::$values ) {
			throw new RuntimeException( 'TierMint Elementor tags need their value delegate; the adapter sets it on register.' );
		}

		return self::$values;
	}

	/**
	 * The dynamic-tag group these tags file under.
	 *
	 * @return string
	 */
	public function get_group(): string {
		return Registrar::CATEGORY_SLUG;
	}

	/**
	 * Get the text category for this tag.
	 *
	 * @return array<int, string>
	 */
	public function get_categories(): array {
		return array( Module::TEXT_CATEGORY );
	}

	/**
	 * Register the product selector.
	 *
	 * @return void
	 */
	protected function register_controls(): void {
		$this->add_control(
			'product_id',
			array(
				'label'   => __( 'Product', 'tiermint' ),
				'type'    => Controls_Manager::NUMBER,
				'default' => 0,
			)
		);
	}

	/**
	 * The chosen product id from the tag's settings.
	 *
	 * @return int
	 */
	protected function productId(): int {
		return (int) $this->get_settings( 'product_id' );
	}
}
