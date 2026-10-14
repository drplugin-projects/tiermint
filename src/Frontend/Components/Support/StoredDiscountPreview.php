<?php
/**
 * Prices a cart's stored discount code, composed with the best automatic discount, for display.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend\Components\Support;

use DrPlugin\WP\TierMint\Discounts\Discount;
use DrPlugin\WP\TierMint\Discounts\DiscountComposer;
use DrPlugin\WP\TierMint\Discounts\DiscountPersistenceException;
use DrPlugin\WP\TierMint\Discounts\DiscountService;
use DrPlugin\WP\TierMint\Discounts\DiscountValidationException;
use DrPlugin\WP\TierMint\Util\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Builds discount display data for carts.
 *
 * Validation is advisory. Checkout validates again.
 *
 * @since 1.0.0
 */
final class StoredDiscountPreview {

	/**
	 * Build template data for stored and automatic discounts.
	 *
	 * @param DiscountService|null                              $discounts The engine, or null when the domain is absent.
	 * @param string|null                                       $code      The cart's stored code.
	 * @param array<int, array{product_id: int, subtotal: int}> $items     The priced line items in minor units.
	 * @param Money                                             $money     The store money formatter.
	 * @param DiscountComposer|null                             $composer  The shared composer; null shows the code alone.
	 * @return array{state: string, code: string, label: string, amount_minor: int, amount: string, error: string, auto: array{applied: bool, label: string, amount_minor: int, amount: string}, total_minor: int}
	 */
	public static function view( ?DiscountService $discounts, ?string $code, array $items, Money $money, ?DiscountComposer $composer = null ): array {
		$view = array(
			'state'        => '',
			'code'         => '',
			'label'        => '',
			'amount_minor' => 0,
			'amount'       => '',
			'error'        => '',
			'auto'         => array(
				'applied'      => false,
				'label'        => '',
				'amount_minor' => 0,
				'amount'       => '',
			),
			'total_minor'  => 0,
		);

		$code = (string) ( $code ?? '' );

		// Validate the stored code without customer-specific rules.
		$codeApplication = null;

		if ( '' !== $code && null !== $discounts ) {
			try {
				$codeApplication = $discounts->applyToCart( $code, $items, null );

				$view['state'] = 'applied';
				$view['code']  = $codeApplication->discount()->code();
				$view['label'] = self::codeLabel( $codeApplication->discount() );
			} catch ( DiscountValidationException $e ) {
				$errors        = array_values( array_filter( $e->getErrors(), 'is_string' ) );
				$view['state'] = 'invalid';
				$view['error'] = array() !== $errors ? $errors[0] : $e->getMessage();
			} catch ( DiscountPersistenceException $e ) {
				// A read failure must not break the render.
				return $view;
			}
		}

		// Use the shared composer for automatic discounts.
		if ( null !== $composer ) {
			try {
				$composed = $composer->compose( $codeApplication, $items, null );

				$codeComponent = $composed->codeComponent();
				if ( null !== $codeComponent ) {
					$view['amount_minor'] = $codeComponent->total();
					$view['amount']       = $money->format( $codeComponent->total() );
				}

				$autoComponent = $composed->autoComponent();
				if ( null !== $autoComponent ) {
					$view['auto'] = array(
						'applied'      => true,
						'label'        => self::autoLabel( $autoComponent->discount() ),
						'amount_minor' => $autoComponent->total(),
						'amount'       => $money->format( $autoComponent->total() ),
					);
				}

				$view['total_minor'] = $composed->total();

				return $view;
			} catch ( DiscountPersistenceException $e ) {
				// Fall back to the code-only view.
				$composer = null;
			}
		}

		// Use the code-only view when composition is unavailable.
		if ( null !== $codeApplication ) {
			$view['amount_minor'] = $codeApplication->total();
			$view['amount']       = $money->format( $codeApplication->total() );
			$view['total_minor']  = $codeApplication->total();
		}

		return $view;
	}

	/**
	 * Summary label for a typed discount code.
	 *
	 * @param Discount $discount The applied code discount.
	 * @return string
	 */
	private static function codeLabel( Discount $discount ): string {
		$name = $discount->name();

		if ( null !== $name && '' !== $name ) {
			return $name;
		}

		return sprintf(
			/* translators: %s: the discount code the buyer applied. */
			__( 'Discount (%s)', 'tiermint' ),
			$discount->code()
		);
	}

	/**
	 * Summary label for an automatic discount.
	 *
	 * @param Discount $discount The applied automatic discount.
	 * @return string
	 */
	private static function autoLabel( Discount $discount ): string {
		$name = $discount->name();

		return ( null !== $name && '' !== $name ) ? $name : __( 'Automatic discount', 'tiermint' );
	}
}
