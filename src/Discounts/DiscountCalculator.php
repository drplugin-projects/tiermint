<?php
/**
 * The pure reduction math shared by the code path and the automatic-discount resolver.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Discounts;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Computes and allocates reductions for typed and automatic discounts.
 * The applicable map is item index => subtotal in minor units.
 *
 * @since 1.0.0
 */
final class DiscountCalculator {

	/**
	 * Compute the total reduction for a discount against its applicable items.
	 *
	 * Round percentages half up and cap all reductions at the applicable subtotal.
	 *
	 * @since 1.0.0
	 *
	 * @param Discount        $discount   The discount.
	 * @param array<int, int> $applicable The applicable items as index => subtotal (minor units).
	 * @return int The total reduction in minor units.
	 */
	public function computeTotal( Discount $discount, array $applicable ): int {
		$applicableSubtotal = array_sum( $applicable );

		if ( $applicableSubtotal <= 0 ) {
			return 0;
		}

		if ( DiscountType::Percent === $discount->type() ) {
			$raw = (int) round( $applicableSubtotal * (float) $discount->amountPercent() / 100 );
		} else {
			$raw = (int) $discount->amountFixed();
		}

		return max( 0, min( $raw, $applicableSubtotal ) );
	}

	/**
	 * Allocate reductions by subtotal with the largest-remainder method.
	 *
	 * @since 1.0.0
	 *
	 * @param int             $total      The total reduction to distribute (minor units).
	 * @param int             $itemCount  The number of input items (defines the result length).
	 * @param array<int, int> $applicable The applicable items as index => subtotal (minor units).
	 * @return array<int, int> The per-item reduction, index-aligned with the input items.
	 */
	public function allocate( int $total, int $itemCount, array $applicable ): array {
		$allocation         = array_fill( 0, max( 0, $itemCount ), 0 );
		$applicableSubtotal = array_sum( $applicable );

		if ( $total <= 0 || $applicableSubtotal <= 0 ) {
			return $allocation;
		}

		// Keep fractional remainders for the remaining minor units.
		$remainders  = array();
		$distributed = 0;

		foreach ( $applicable as $index => $subtotal ) {
			if ( $subtotal <= 0 ) {
				continue;
			}

			$exact                = $total * $subtotal / $applicableSubtotal;
			$base                 = (int) floor( $exact );
			$allocation[ $index ] = $base;
			$distributed         += $base;
			$remainders[ $index ] = $exact - $base;
		}

		$remaining = $total - $distributed;

		if ( $remaining > 0 && array() !== $remainders ) {
			$indices = array_keys( $remainders );

			// Keep allocation order deterministic on equal remainders.
			usort(
				$indices,
				static function ( int $a, int $b ) use ( $remainders, $applicable ): int {
					if ( $remainders[ $a ] !== $remainders[ $b ] ) {
						return $remainders[ $b ] <=> $remainders[ $a ];
					}

					if ( $applicable[ $a ] !== $applicable[ $b ] ) {
						return $applicable[ $b ] <=> $applicable[ $a ];
					}

					return $a <=> $b;
				}
			);

			$count = count( $indices );
			for ( $k = 0; $k < $remaining && $k < $count; $k++ ) {
				++$allocation[ $indices[ $k ] ];
			}
		}

		return $allocation;
	}
}
