<?php
/**
 * Combines code and automatic discounts.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Discounts;

use DrPlugin\WP\TierMint\Customers\Customer;

// Block direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Applies the code-combination setting to validated code and automatic discounts.
 *
 * In stack mode, automatic discounts apply before codes. The code remains primary.
 *
 * @since 1.0.0
 */
class DiscountComposer {

	/**
	 * Resolves automatic discounts.
	 *
	 * @var AutomaticDiscountResolver
	 */
	private AutomaticDiscountResolver $resolver;

	/**
	 * Reads the combination rule. Null uses exclusive mode.
	 *
	 * @var DiscountSettings|null
	 */
	private ?DiscountSettings $settings;

	/**
	 * Re-prices codes in stack mode. Null skips the re-price.
	 *
	 * @var DiscountService|null
	 */
	private ?DiscountService $discounts;

	/**
	 * Creates the composer.
	 *
	 * @since 1.0.0
	 *
	 * @param AutomaticDiscountResolver $resolver  Resolves automatic discounts.
	 * @param DiscountSettings|null     $settings  Reads the combination rule.
	 * @param DiscountService|null      $discounts Re-prices stacked codes.
	 */
	public function __construct( AutomaticDiscountResolver $resolver, ?DiscountSettings $settings, ?DiscountService $discounts ) {
		$this->resolver  = $resolver;
		$this->settings  = $settings;
		$this->discounts = $discounts;
	}

	/**
	 * Combines the validated code with the cart's automatic discount.
	 *
	 * @since 1.0.0
	 *
	 * @param DiscountApplication|null                          $codeApplication Validated code application, if any.
	 * @param array<int, array{product_id: int, subtotal: int}> $items           Cart items in minor units.
	 * @param Customer|null                                     $customer        Resolved buyer, if any.
	 * @return ComposedDiscount Combined discount.
	 *
	 * @throws DiscountPersistenceException When the automatic-discount read faults at the database.
	 */
	public function compose( ?DiscountApplication $codeApplication, array $items, ?Customer $customer ): ComposedDiscount {
		$lineCount = count( $items );
		$stacks    = null !== $this->settings && $this->settings->stacks();

		if ( ! $stacks ) {
			// A code replaces automatic discounts in exclusive mode.
			if ( null !== $codeApplication ) {
				return new ComposedDiscount( $this->perLine( $codeApplication, $lineCount ), $codeApplication->discount(), null, $codeApplication, null );
			}

			$auto = $this->resolver->resolveForCart( $items, $customer );

			if ( null === $auto ) {
				return new ComposedDiscount( $this->zeros( $lineCount ), null, null, null, null );
			}

			return new ComposedDiscount( $this->perLine( $auto, $lineCount ), $auto->discount(), null, null, $auto );
		}

		// Apply automatic discounts before re-pricing the code.
		$auto      = $this->resolver->resolveForCart( $items, $customer );
		$autoAlloc = $this->perLine( $auto, $lineCount );

		$codeStack = $this->repriceOnReducedBase( $codeApplication, $items, $customer, $autoAlloc );
		$codeAlloc = $this->perLine( $codeStack, $lineCount );

		$combined = array();
		for ( $i = 0; $i < $lineCount; $i++ ) {
			$combined[ $i ] = $autoAlloc[ $i ] + $codeAlloc[ $i ];
		}

		// A redeemed code is the primary discount.
		$primary = null !== $codeStack
			? $codeStack->discount()
			: ( null !== $auto ? $auto->discount() : null );

		// Store the automatic part only when it stacks with a code.
		$autoMeta = ( null !== $codeStack && null !== $auto )
			? array(
				'id'     => (int) $auto->discount()->id(),
				'amount' => (int) $auto->total(),
			)
			: null;

		return new ComposedDiscount( $combined, $primary, $autoMeta, $codeStack, $auto );
	}

	/**
	 * Re-prices a validated code after automatic reductions.
	 *
	 * Validation stays based on the original cart.
	 *
	 * @since 1.0.0
	 *
	 * @param DiscountApplication|null                          $codeApplication Validated code application, if any.
	 * @param array<int, array{product_id: int, subtotal: int}> $items           Original cart items.
	 * @param Customer|null                                     $customer        Resolved buyer, if any.
	 * @param array<int, int>                                   $autoAlloc       Automatic reductions by line.
	 * @return DiscountApplication|null Re-priced code application, if any.
	 */
	private function repriceOnReducedBase( ?DiscountApplication $codeApplication, array $items, ?Customer $customer, array $autoAlloc ): ?DiscountApplication {
		if ( null === $codeApplication || null === $this->discounts ) {
			return null;
		}

		$reduced = array();
		foreach ( array_values( $items ) as $index => $item ) {
			$reduced[ $index ] = (int) ( $item['subtotal'] ?? 0 ) - ( $autoAlloc[ $index ] ?? 0 );
		}

		return $this->discounts->applyToCart( $codeApplication->discount()->code(), $items, $customer, $reduced );
	}

	/**
	 * Gets reductions by line, using zeros when nothing applies.
	 *
	 * @since 1.0.0
	 *
	 * @param DiscountApplication|null $application Application, if any.
	 * @param int                      $count       Number of lines.
	 * @return array<int, int> Reductions by line.
	 */
	private function perLine( ?DiscountApplication $application, int $count ): array {
		$amounts = $this->zeros( $count );

		if ( null === $application ) {
			return $amounts;
		}

		for ( $i = 0; $i < $count; $i++ ) {
			$amounts[ $i ] = $application->amountForItem( $i );
		}

		return $amounts;
	}

	/**
	 * Creates zero reductions for each line.
	 *
	 * @since 1.0.0
	 *
	 * @param int $count Number of lines.
	 * @return array<int, int>
	 */
	private function zeros( int $count ): array {
		return array_fill( 0, max( 0, $count ), 0 );
	}
}
