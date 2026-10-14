<?php
/**
 * The standalone license delivery email definition.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Emails;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Sends license keys created after the purchase receipt.
 *
 * @since 1.0.0
 */
final class LicenseDeliveryEmail extends Email {

	/**
	 * The registry id for the license delivery email.
	 *
	 * @var string
	 */
	public const ID = 'license_delivery';

	/**
	 * The email id.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * The template key for the license delivery body.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function template(): string {
		return 'license-delivery';
	}

	/**
	 * Build the license delivery subject.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $ctx The license delivery context.
	 * @return string The subject line.
	 */
	public function subject( array $ctx ): string {
		$store   = (string) ( $ctx['store_name'] ?? '' );
		$product = (string) ( $ctx['product_name'] ?? '' );

		return sprintf(
			/* translators: 1: the store name, 2: the product name. */
			__( '%1$s: your license key for %2$s', 'tiermint' ),
			$store,
			$product
		);
	}
}
