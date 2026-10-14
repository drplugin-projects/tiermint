<?php
/**
 * The result of composing a code and an automatic discount into one combination.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Discounts;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Stores the result of {@see DiscountComposer::compose()}.
 *
 * @since 1.0.0
 */
final class ComposedDiscount {

	/**
	 * The combined per-line reduction, index-aligned with the input items.
	 *
	 * @var array<int, int>
	 */
	private array $perLine;

	/**
	 * The primary discount the order records, or null when nothing applied.
	 *
	 * @var Discount|null
	 */
	private ?Discount $primary;

	/**
	 * The stacked automatic component for order meta (`{id, amount}`), or null.
	 *
	 * @var array{id: int, amount: int}|null
	 */
	private ?array $autoMeta;

	/**
	 * The code's contribution as it landed (on the auto-reduced base under stacking), or null.
	 *
	 * @var DiscountApplication|null
	 */
	private ?DiscountApplication $codeComponent;

	/**
	 * The automatic discount's contribution, or null.
	 *
	 * @var DiscountApplication|null
	 */
	private ?DiscountApplication $autoComponent;

	/**
	 * Carry the composed outcome.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, int>                  $perLine       The combined per-line reduction.
	 * @param Discount|null                    $primary       The primary discount.
	 * @param array{id: int, amount: int}|null $autoMeta      The stacked automatic component for order meta.
	 * @param DiscountApplication|null         $codeComponent The code's landed contribution.
	 * @param DiscountApplication|null         $autoComponent The automatic discount's contribution.
	 */
	public function __construct( array $perLine, ?Discount $primary, ?array $autoMeta, ?DiscountApplication $codeComponent, ?DiscountApplication $autoComponent ) {
		$this->perLine       = array_values( $perLine );
		$this->primary       = $primary;
		$this->autoMeta      = $autoMeta;
		$this->codeComponent = $codeComponent;
		$this->autoComponent = $autoComponent;
	}

	/**
	 * The total combined reduction in minor units.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function total(): int {
		return array_sum( $this->perLine );
	}

	/**
	 * The combined per-line reduction, index-aligned with the input items.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, int>
	 */
	public function perLine(): array {
		return $this->perLine;
	}

	/**
	 * The primary discount the order records, or null when nothing applied.
	 *
	 * @since 1.0.0
	 *
	 * @return Discount|null
	 */
	public function primary(): ?Discount {
		return $this->primary;
	}

	/**
	 * The stacked automatic component for order meta (`{id, amount}`), or null.
	 *
	 * @since 1.0.0
	 *
	 * @return array{id: int, amount: int}|null
	 */
	public function autoMeta(): ?array {
		return $this->autoMeta;
	}

	/**
	 * The code's contribution as it landed on the final price, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return DiscountApplication|null
	 */
	public function codeComponent(): ?DiscountApplication {
		return $this->codeComponent;
	}

	/**
	 * The automatic discount's contribution, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return DiscountApplication|null
	 */
	public function autoComponent(): ?DiscountApplication {
		return $this->autoComponent;
	}
}
