<?php
/**
 * Presenter turning a price tier into human-readable meta lines.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend\Components\Support;

use DrPlugin\WP\TierMint\Products\ProductPrice;

defined( 'ABSPATH' ) || exit;

/**
 * Builds translated metadata lines for price tiers.
 *
 * All product surfaces use the same wording.
 *
 * @since 1.0.0
 */
final class TierMetaPresenter {

	/**
	 * Toggle attribute: show the license-duration line.
	 *
	 * @var string
	 */
	public const SHOW_LICENSE_LENGTH = 'show_license_length';

	/**
	 * Toggle attribute: show the activation-limit line.
	 *
	 * @var string
	 */
	public const SHOW_ACTIVATION_LIMIT = 'show_activation_limit';

	/**
	 * Schema rows for tier metadata toggles.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function toggleSchema(): array {
		return array(
			self::SHOW_LICENSE_LENGTH   => array(
				'type'        => 'boolean',
				'default'     => true,
				'control'     => 'toggle',
				'label'       => __( 'Show license length', 'tiermint' ),
				'description' => __( "Show each plan's license duration.", 'tiermint' ),
			),
			self::SHOW_ACTIVATION_LIMIT => array(
				'type'        => 'boolean',
				'default'     => true,
				'control'     => 'toggle',
				'label'       => __( 'Show activation limit', 'tiermint' ),
				'description' => __( 'Show how many sites each plan may activate.', 'tiermint' ),
			),
		);
	}

	/**
	 * Read tier metadata flags from component attributes.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $attrs Resolved component attributes.
	 * @return array<string, bool>
	 */
	public static function flagsFrom( array $attrs ): array {
		return array(
			self::SHOW_LICENSE_LENGTH   => (bool) ( $attrs[ self::SHOW_LICENSE_LENGTH ] ?? true ),
			self::SHOW_ACTIVATION_LIMIT => (bool) ( $attrs[ self::SHOW_ACTIVATION_LIMIT ] ?? true ),
		);
	}

	/**
	 * Build metadata lines for one tier.
	 *
	 * @since 1.0.0
	 *
	 * @param ProductPrice        $price The tier to describe.
	 * @param array<string, bool> $flags The display flags keyed by attribute name.
	 * @return array<int, string>
	 */
	public static function lines( ProductPrice $price, array $flags ): array {
		$lines = array();

		if ( ! empty( $flags[ self::SHOW_LICENSE_LENGTH ] ) ) {
			$license = self::licenseLine( $price );
			if ( null !== $license ) {
				$lines[] = $license;
			}
		}

		if ( ! empty( $flags[ self::SHOW_ACTIVATION_LIMIT ] ) ) {
			$lines[] = self::activationLine( $price );
		}

		/**
		 * Filters the plain-text metadata lines for a price tier.
		 *
		 * The license line comes first when its flag is on and the length reads
		 * as a duration or lifetime, then the activation line.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, string>  $lines The meta lines, in display order.
		 * @param ProductPrice        $price The tier being described.
		 * @param array<string, bool> $flags The resolved display flags.
		 */
		$lines = apply_filters( 'tiermint_tier_meta_lines', $lines, $price, $flags );

		$clean = array();
		foreach ( (array) $lines as $line ) {
			if ( is_string( $line ) && '' !== $line ) {
				$clean[] = $line;
			}
		}

		return $clean;
	}

	/**
	 * Build the license-duration line.
	 *
	 * @param ProductPrice $price The tier.
	 * @return string|null
	 */
	private static function licenseLine( ProductPrice $price ): ?string {
		$raw = $price->licenseLength();

		if ( null === $raw ) {
			return __( 'Lifetime license', 'tiermint' );
		}

		// Accept stored values with surrounding whitespace.
		if ( 1 !== preg_match( '/^\+?(\d+) (day|week|month|year)s?$/i', trim( $raw ), $matches ) ) {
			return null;
		}

		$count = (int) $matches[1];
		if ( $count <= 0 ) {
			return null;
		}

		switch ( strtolower( $matches[2] ) ) {
			case 'day':
				/* translators: %d: number of days. The plural form exists for locales that inflect the compound; English keeps the unit singular. */
				return sprintf( _n( '%d-day license', '%d-day license', $count, 'tiermint' ), $count );
			case 'week':
				/* translators: %d: number of weeks. The plural form exists for locales that inflect the compound; English keeps the unit singular. */
				return sprintf( _n( '%d-week license', '%d-week license', $count, 'tiermint' ), $count );
			case 'month':
				/* translators: %d: number of months. The plural form exists for locales that inflect the compound; English keeps the unit singular. */
				return sprintf( _n( '%d-month license', '%d-month license', $count, 'tiermint' ), $count );
			case 'year':
			default:
				/* translators: %d: number of years. The plural form exists for locales that inflect the compound; English keeps the unit singular. */
				return sprintf( _n( '%d-year license', '%d-year license', $count, 'tiermint' ), $count );
		}
	}

	/**
	 * Build the activation-limit line.
	 *
	 * @param ProductPrice $price The tier.
	 * @return string
	 */
	private static function activationLine( ProductPrice $price ): string {
		$limit = $price->activationLimit();

		if ( null === $limit ) {
			return __( 'Unlimited activations', 'tiermint' );
		}

		/* translators: %d: number of site activations the license allows. */
		return sprintf( _n( '%d activation', '%d activations', $limit, 'tiermint' ), $limit );
	}
}
