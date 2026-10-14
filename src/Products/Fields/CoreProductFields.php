<?php
/**
 * The product fields and panels the free core registers.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Products\Fields;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Core product editor field and panel definitions.
 *
 * Content fields use product columns. Media fields use settings.
 *
 * @since 1.0.0
 */
final class CoreProductFields {

	/**
	 * Core field definitions in editor order.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array<string, mixed>> The built-in fields.
	 */
	public function fields(): array {
		return array(
			array(
				'id'      => 'overview',
				'panel'   => 'content',
				'type'    => 'rich_text',
				'label'   => __( 'Overview', 'tiermint' ),
				'help'    => __( 'A short rich-text summary shown near the top of the product page.', 'tiermint' ),
				'storage' => 'column',
			),
			array(
				'id'      => 'description',
				'panel'   => 'content',
				'type'    => 'rich_text',
				'label'   => __( 'Full description', 'tiermint' ),
				'help'    => __( 'The long description with formatting, shown on the product and pricing pages.', 'tiermint' ),
				'storage' => 'column',
			),
			array(
				'id'    => 'main_image',
				'panel' => 'media',
				'type'  => 'media',
				'label' => __( 'Main image', 'tiermint' ),
				'help'  => __( 'The primary product image, from the media library.', 'tiermint' ),
			),
			array(
				'id'    => 'thumbnail',
				'panel' => 'media',
				'type'  => 'media',
				'label' => __( 'Thumbnail', 'tiermint' ),
				'help'  => __( 'A smaller image for product grids and listings.', 'tiermint' ),
			),
			array(
				'id'    => 'og_image',
				'panel' => 'media',
				'type'  => 'media',
				'label' => __( 'Social share image', 'tiermint' ),
				'help'  => __( 'Shown when the product page is shared on social networks.', 'tiermint' ),
			),
			array(
				'id'    => 'screenshots',
				'panel' => 'media',
				'type'  => 'media_gallery',
				'label' => __( 'Screenshots', 'tiermint' ),
				'help'  => __( 'A gallery of product screenshots, from the media library.', 'tiermint' ),
			),
		);
	}

	/**
	 * Core editor panels.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array<string, string>> The built-in panels as `{id, label}` rows.
	 */
	public function panels(): array {
		return array(
			array(
				'id'    => 'content',
				'label' => __( 'Content', 'tiermint' ),
			),
			array(
				'id'    => 'media',
				'label' => __( 'Media', 'tiermint' ),
			),
		);
	}
}
