<?php
/**
 * The one seam that renders a catalog price node, plain or struck through.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend\Components\Support;

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
		// With no note the markup is byte-identical to a bare price.
		$note = self::note();

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
	 * The short note shown after every catalog price, or '' for none.
	 *
	 * @since 1.0.0
	 *
	 * @return string The note, stripped of tags.
	 */
	private static function note(): string {
		/**
		 * Filters the note shown after every catalog price.
		 *
		 * Empty by default. The note has no product context, so it suits a
		 * store-wide line such as "one-time payment" or "per license". Tags are
		 * stripped; an empty or non-string return shows no note.
		 *
		 * @since 1.0.0
		 *
		 * @param string $note The note. Default ''.
		 */
		$note = apply_filters( 'tiermint_price_note', '' );

		return is_string( $note ) && '' !== $note ? wp_strip_all_tags( $note ) : '';
	}

	/**
	 * Render a single price, with the note when one is set.
	 *
	 * An empty note returns the bare escaped amount, byte-identical to the markup
	 * the surface wrappers held before.
	 *
	 * @since 1.0.0
	 *
	 * @param string $amount The price, already formatted.
	 * @param string $note   The note, or '' for none.
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
	 * @param string $note The note, or '' for none.
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
