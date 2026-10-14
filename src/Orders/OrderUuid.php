<?php
/**
 * Order uuid generator.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Orders;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Generates public UUIDs from `random_bytes()`.
 * UUIDs protect guest status and receipt access.
 */
final class OrderUuid {

	/**
	 * Generate a random RFC 4122 version 4 UUID.
	 *
	 * @since 1.0.0
	 *
	 * @return string A 36-character lower-case v4 uuid.
	 */
	public static function generate(): string {
		$bytes = random_bytes( 16 );

		// Set the version and variant bits.
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );

		$hex = bin2hex( $bytes );

		return sprintf(
			'%s-%s-%s-%s-%s',
			substr( $hex, 0, 8 ),
			substr( $hex, 8, 4 ),
			substr( $hex, 12, 4 ),
			substr( $hex, 16, 4 ),
			substr( $hex, 20, 12 )
		);
	}
}
