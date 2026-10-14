<?php
/**
 * Immutable result of applying a discount to a cart.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Discounts;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Stores a discount, its total reduction, and its per-item allocation.
 * Item amounts align with {@see DiscountService::applyToCart()} input.
 *
 * @since 1.0.0
 */
final class DiscountApplication {

	/**
	 * The discount that was applied.
	 *
	 * @var Discount
	 */
	private Discount $discount;

	/**
	 * The total reduction in minor units (non-negative).
	 *
	 * @var int
	 */
	private int $total;

	/**
	 * The per-item reduction in minor units, index-aligned with the input items.
	 *
	 * @var array<int, int>
	 */
	private array $itemAmounts;

	/**
	 * Carry the computed application.
	 *
	 * @since 1.0.0
	 *
	 * @param Discount        $discount    The discount that was applied.
	 * @param int             $total       The total reduction in minor units.
	 * @param array<int, int> $itemAmounts The per-item reduction, index-aligned with the input items.
	 */
	public function __construct( Discount $discount, int $total, array $itemAmounts ) {
		$this->discount    = $discount;
		$this->total       = $total;
		$this->itemAmounts = array_values( $itemAmounts );
	}

	/**
	 * The discount that was applied.
	 *
	 * @since 1.0.0
	 *
	 * @return Discount
	 */
	public function discount(): Discount {
		return $this->discount;
	}

	/**
	 * The total reduction in minor units.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function total(): int {
		return $this->total;
	}

	/**
	 * The full per-item allocation, index-aligned with the input items.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, int> The per-item reductions in minor units.
	 */
	public function itemAmounts(): array {
		return $this->itemAmounts;
	}

	/**
	 * Return an item's reduction, or zero for an unknown index.
	 *
	 * @since 1.0.0
	 *
	 * @param int $index The zero-based index of the item in the input list.
	 * @return int The item's reduction in minor units, or 0 for an unknown index.
	 */
	public function amountForItem( int $index ): int {
		return $this->itemAmounts[ $index ] ?? 0;
	}
}
