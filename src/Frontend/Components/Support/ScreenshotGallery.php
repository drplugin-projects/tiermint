<?php
/**
 * Shared screenshot-gallery resolver.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend\Components\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves screenshot IDs to gallery rows.
 *
 * Missing attachments are omitted.
 *
 * @since 1.0.0
 */
final class ScreenshotGallery {

	/**
	 * The image size rendered for each thumbnail.
	 *
	 * @var string
	 */
	private const THUMB_SIZE = 'large';

	/**
	 * Resolve attachment IDs to gallery rows.
	 *
	 * @param mixed $ids The raw screenshots value (expected: a list of ids).
	 * @return array<int, array{full: string, thumb: string, alt: string, caption: string}>
	 */
	public static function fromIds( $ids ): array {
		if ( ! is_array( $ids ) ) {
			return array();
		}

		$shots = array();

		foreach ( $ids as $id ) {
			$shot = self::resolve( (int) $id );

			if ( null !== $shot ) {
				$shots[] = $shot;
			}
		}

		return $shots;
	}

	/**
	 * Resolve one attachment ID to a gallery row.
	 *
	 * @param int $id The attachment id.
	 * @return array{full: string, thumb: string, alt: string, caption: string}|null
	 */
	private static function resolve( int $id ): ?array {
		if ( $id <= 0 ) {
			return null;
		}

		$full = wp_get_attachment_image_url( $id, 'full' );

		if ( ! is_string( $full ) || '' === $full ) {
			return null;
		}

		$thumb = wp_get_attachment_image( $id, self::THUMB_SIZE, false, array( 'class' => 'tiermint-product-screenshots__img' ) );

		return array(
			'full'    => $full,
			'thumb'   => is_string( $thumb ) ? $thumb : '',
			'alt'     => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'caption' => (string) wp_get_attachment_caption( $id ),
		);
	}
}
