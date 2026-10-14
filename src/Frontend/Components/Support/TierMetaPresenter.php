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
	 * Toggle attribute: show the billing-schedule line.
	 *
	 * @var string
	 */
	public const SHOW_BILLING = 'show_billing';

	/**
	 * Toggle attribute: show the free-trial line.
	 *
	 * @var string
	 */
	public const SHOW_TRIAL = 'show_trial';

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
			self::SHOW_BILLING          => array(
				'type'        => 'boolean',
				'default'     => true,
				'control'     => 'toggle',
				'label'       => __( 'Show billing interval', 'tiermint' ),
				'description' => __( 'Show the billing schedule on subscription plans.', 'tiermint' ),
			),
			self::SHOW_TRIAL            => array(
				'type'        => 'boolean',
				'default'     => true,
				'control'     => 'toggle',
				'label'       => __( 'Show free trial', 'tiermint' ),
				'description' => __( 'Show the free-trial length on subscription plans.', 'tiermint' ),
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
			self::SHOW_BILLING          => (bool) ( $attrs[ self::SHOW_BILLING ] ?? true ),
			self::SHOW_TRIAL            => (bool) ( $attrs[ self::SHOW_TRIAL ] ?? true ),
		);
	}

	/**
	 * Build metadata lines for one tier.
	 *
	 * @since 1.0.0
	 *
	 * @param ProductPrice        $price The tier to describe.
	 * @param array<string, bool> $flags The four display flags keyed by attribute name.
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

		if ( ! empty( $flags[ self::SHOW_BILLING ] ) ) {
			$billing = self::billingLine( $price );
			if ( null !== $billing ) {
				$lines[] = $billing;
			}
		}

		if ( ! empty( $flags[ self::SHOW_TRIAL ] ) ) {
			$trial = self::trialLine( $price );
			if ( null !== $trial ) {
				$lines[] = $trial;
			}
		}

		/**
		 * Filters the plain-text metadata lines for a price tier.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, string>  $lines The meta lines, in display order.
		 * @param ProductPrice        $price The tier being described.
		 * @param array<string, bool> $flags The four resolved display flags.
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

		if ( ProductPrice::LICENSE_LENGTH_BILLING === $raw ) {
			return null === $price->resolvedLicenseLength()
				? __( 'Lifetime license', 'tiermint' )
				: __( 'License lasts as long as your subscription', 'tiermint' );
		}

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

	/**
	 * Build the billing-schedule line.
	 *
	 * @param ProductPrice $price The tier.
	 * @return string|null
	 */
	private static function billingLine( ProductPrice $price ): ?string {
		if ( ! $price->isSubscription() ) {
			return null;
		}

		$period   = $price->billingPeriod();
		$interval = $price->billingInterval();

		if ( null === $period || null === $interval ) {
			return null;
		}

		if ( 1 === $interval ) {
			switch ( $period ) {
				case 'day':
					return __( 'Billed daily', 'tiermint' );
				case 'week':
					return __( 'Billed weekly', 'tiermint' );
				case 'month':
					return __( 'Billed monthly', 'tiermint' );
				case 'year':
				default:
					return __( 'Billed yearly', 'tiermint' );
			}
		}

		switch ( $period ) {
			case 'day':
				/* translators: %d: number of days between charges. */
				return sprintf( _n( 'Billed every %d day', 'Billed every %d days', $interval, 'tiermint' ), $interval );
			case 'week':
				/* translators: %d: number of weeks between charges. */
				return sprintf( _n( 'Billed every %d week', 'Billed every %d weeks', $interval, 'tiermint' ), $interval );
			case 'month':
				/* translators: %d: number of months between charges. */
				return sprintf( _n( 'Billed every %d month', 'Billed every %d months', $interval, 'tiermint' ), $interval );
			case 'year':
			default:
				/* translators: %d: number of years between charges. */
				return sprintf( _n( 'Billed every %d year', 'Billed every %d years', $interval, 'tiermint' ), $interval );
		}
	}

	/**
	 * Build the free-trial line.
	 *
	 * @param ProductPrice $price The tier.
	 * @return string|null
	 */
	private static function trialLine( ProductPrice $price ): ?string {
		if ( ! $price->isSubscription() ) {
			return null;
		}

		$days = $price->trialDays();
		if ( $days <= 0 ) {
			return null;
		}

		/* translators: %d: number of free-trial days. The plural form exists for locales that inflect the compound; English keeps the unit singular. */
		return sprintf( _n( '%d-day free trial', '%d-day free trial', $days, 'tiermint' ), $days );
	}
}
