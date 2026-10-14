<?php
/**
 * The catalog pricing mode: tax-exclusive or tax-inclusive.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Orders;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Whether catalog prices already include tax.
 *
 * Free core cannot decide this: only a tax engine can give "prices include tax"
 * a meaning. The mode is learned through one boolean filter, so free core alone
 * is byte-identical to a tax-exclusive store.
 *
 * @since 1.0.0
 */
final class PricingMode {

	/**
	 * Whether the merchant enters catalog prices tax-inclusive.
	 *
	 * The filter is applied on every call with no memo, matching
	 * {@see \DrPlugin\WP\TierMint\Products\ProductService::recurringTiersSupported()},
	 * so a listener that flips the mode is seen at once.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when catalog prices already include tax.
	 */
	public static function includesTax(): bool {
		/**
		 * Filters whether catalog prices already include tax.
		 *
		 * Answered true only by a configured tax engine (Pro). Free core keeps
		 * the default and stays tax-exclusive.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $includesTax Whether prices are tax-inclusive. Default false.
		 */
		return (bool) apply_filters( 'tiermint_prices_include_tax', false );
	}

	/**
	 * The short note shown beside a tax-inclusive price.
	 *
	 * "tax" not "VAT", because the same row serves wherever the store sells. An
	 * empty return suppresses the note.
	 *
	 * @since 1.0.0
	 *
	 * @return string The note, stripped of markup; '' to show none.
	 */
	public static function note(): string {
		/**
		 * Filters the note shown beside a tax-inclusive price.
		 *
		 * @since 1.0.0
		 *
		 * @param string $note The default note. Filter to '' to show none.
		 */
		$note = apply_filters( 'tiermint_price_inclusive_note', __( 'incl. tax', 'tiermint' ) );

		return is_string( $note ) ? wp_strip_all_tags( $note ) : '';
	}
}
