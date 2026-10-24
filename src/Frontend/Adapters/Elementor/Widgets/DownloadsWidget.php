<?php
/**
 * The TierMint Downloads Elementor widget.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend\Adapters\Elementor\Widgets;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor widget for the downloads-list component.
 *
 * @since 1.0.0
 */
final class DownloadsWidget extends ComponentWidget {

	/**
	 * The component slug this widget renders.
	 *
	 * @return string
	 */
	protected function componentSlug(): string {
		return 'downloads-list';
	}

	/**
	 * The widget's editor title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'TierMint Downloads', 'tiermint' );
	}

	/**
	 * The widget's editor icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-download-button';
	}
}
