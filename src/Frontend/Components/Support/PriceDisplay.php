<?php
/**
 * The one seam that renders a catalog price node, plain or struck through.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend\Components\Support;

use DrPlugin\WP\TierMint\Orders\PricingMode;
use DrPlugin\WP\TierMint\Util\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Renders plain or discounted catalog prices.
 *
 * All catalog surfaces use this shared markup.
 *
 * @since 1.0.0
 */
final class PriceDisplay {

	/**
	 * Render a price node.
	 *
	 * @since 1.0.0
	 *
	 * @param Money    $money         The store money formatter.
	 * @param int      $original      The original price in minor units.
	 * @param int|null $discounted    The discounted price in minor units, or null for no discount.
	 * @param bool     $strikeEnabled Whether the strikethrough display toggle is on.
	 * @return string The escaped price markup for the surface's wrapper to echo raw.
	 */
	public static function render( Money $money, int $original, ?int $discounted, bool $strikeEnabled ): string {
		// Gross mode appends the "incl. tax" note; net mode leaves it empty and the
		// markup is byte-identical to before. Both figures are already gross, so the
		// note is a display affordance only, never a conversion.
		$note = PricingMode::includesTax() ? PricingMode::note() : '';

		// Use the plain price when no reduction applies.
		if ( null === $discounted || $discounted >= $original ) {
			return self::plain( $money->format( $original ), $note );
		}

		// Show only the reduced price when the strike is disabled.
		if ( ! $strikeEnabled ) {
			return self::plain( $money->format( $discounted ), $note );
		}

		return self::pair( $money->format( $original ), $money->format( $discounted ), $note );
	}

	/**
	 * Render a single price, with the tax-inclusive note when one is set.
	 *
	 * An empty note returns the bare escaped amount, byte-identical to the net-mode
	 * markup the surface wrappers held before.
	 *
	 * @since 1.0.0
	 *
	 * @param string $amount The price, already formatted.
	 * @param string $note   The tax-inclusive note, or '' for none.
	 * @return string The rendered price markup.
	 */
	private static function plain( string $amount, string $note ): string {
		if ( '' === $note ) {
			return esc_html( $amount );
		}

		return sprintf(
			'<span class="tiermint-price"><span class="tiermint-price__amount">%s</span> <span class="tiermint-price__note">%s</span></span>',
			esc_html( $amount ),
			esc_html( $note )
		);
	}

	/**
	 * Render the was/now pair through the shared partial.
	 *
	 * @since 1.0.0
	 *
	 * @param string $was  The original price, already formatted.
	 * @param string $now  The discounted price, already formatted.
	 * @param string $note The tax-inclusive note, or '' for none.
	 * @return string The rendered pair markup.
	 */
	private static function pair( string $was, string $now, string $note ): string {
		$tiermint_was  = $was;
		$tiermint_now  = $now;
		$tiermint_note = $note;

		ob_start();
		// Keep the shared markup outside theme overrides.
		require TIERMINT_PATH . 'templates/components/partials/price.php';

		return (string) ob_get_clean();
	}
}
