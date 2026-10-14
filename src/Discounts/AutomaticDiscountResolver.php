<?php
/**
 * Resolves the best automatic (no-code) discount for a cart.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Discounts;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Customers\Customer;
use DrPlugin\WP\TierMint\Orders\OrderRepository;
use DrPlugin\WP\TierMint\Products\ProductTaxonomy;
use DrPlugin\WP\TierMint\Products\TermRelationshipRepository;

/**
 * Selects the best automatic discount for carts and catalog prices.
 * Uses the same rules and reduction math as typed codes.
 *
 * @since 1.0.0
 */
class AutomaticDiscountResolver {

	/**
	 * The discounts repository (the one active-automatic read).
	 *
	 * @var DiscountRepository
	 */
	private DiscountRepository $discounts;

	/**
	 * The term-relationship repository, for the batched category-membership read.
	 *
	 * @var TermRelationshipRepository
	 */
	private TermRelationshipRepository $terms;

	/**
	 * The orders repository, used only for the once-per-customer question.
	 *
	 * @var OrderRepository
	 */
	private OrderRepository $orders;

	/**
	 * The shared reduction math.
	 *
	 * @var DiscountCalculator
	 */
	private DiscountCalculator $calculator;

	/**
	 * Active automatic discounts for this request, or null before the first read.
	 *
	 * @var array<int, Discount>|null
	 */
	private ?array $activeCache = null;

	/**
	 * Wire the resolver with its collaborators.
	 *
	 * @since 1.0.0
	 *
	 * @param DiscountRepository         $discounts  Reads the active automatic discounts.
	 * @param TermRelationshipRepository $terms      Reads category memberships in one batched query.
	 * @param OrderRepository            $orders     Answers the once-per-customer question.
	 * @param DiscountCalculator         $calculator The shared reduction math.
	 */
	public function __construct(
		DiscountRepository $discounts,
		TermRelationshipRepository $terms,
		OrderRepository $orders,
		DiscountCalculator $calculator
	) {
		$this->discounts  = $discounts;
		$this->terms      = $terms;
		$this->orders     = $orders;
		$this->calculator = $calculator;
	}

	/**
	 * Resolve the best automatic discount for a cart, or null when none applies.
	 *
	 * Items use the same minor-unit shape as {@see DiscountService::applyToCart()}.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, array{product_id: int, subtotal: int}> $items    The cart items.
	 * @param Customer|null                                     $customer The resolved buyer, or null
	 *                                                                     for an unresolved guest.
	 * @return DiscountApplication|null The best-for-cart application, or null.
	 *
	 * @throws DiscountPersistenceException When the discount read faults at the database.
	 */
	public function resolveForCart( array $items, ?Customer $customer ): ?DiscountApplication {
		$candidates = $this->activeAutomatic();

		if ( array() === $candidates ) {
			return null;
		}

		$membership = null;

		return $this->selectBest( $items, $candidates, time(), $customer, $membership, $this->productIds( $items ) );
	}

	/**
	 * The best automatic discount for each catalog price node, keyed by the caller's own index.
	 *
	 * Each node is priced as a one-item cart. Category memberships load once per batch.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, array{product_id: int, subtotal: int}> $nodes    The price nodes in minor units.
	 * @param Customer|null                                     $customer The resolved buyer, or null.
	 * @return array<int, DiscountApplication|null> Keyed by the input index; null where nothing applies.
	 *
	 * @throws DiscountPersistenceException When the discount read faults at the database.
	 */
	public function bestForNodes( array $nodes, ?Customer $customer ): array {
		$result = array();
		foreach ( array_keys( $nodes ) as $index ) {
			$result[ $index ] = null;
		}

		$candidates = $this->activeAutomatic();

		if ( array() === $candidates ) {
			return $result;
		}

		// Load category memberships once, only when a candidate needs them.
		$membership = null;
		$universe   = $this->productIds( $nodes );
		$now        = time();

		foreach ( $nodes as $index => $node ) {
			$result[ $index ] = $this->selectBest( array( $node ), $candidates, $now, $customer, $membership, $universe );
		}

		return $result;
	}

	/**
	 * Select the best automatic discount for an item set.
	 *
	 * Cart and catalog share the cached eligibility check.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, array{product_id: int, subtotal: int}> $items               The item set (one line, or a whole cart).
	 * @param array<int, Discount>                              $candidates          The active automatic discounts.
	 * @param int                                               $now                 The current UNIX timestamp.
	 * @param Customer|null                                     $customer            The resolved buyer, or null.
	 * @param array<int, array<int, int>>|null                  $membership          The shared membership cache, by reference.
	 * @param array<int, int>                                   $membershipUniverse  The product ids to batch when membership loads.
	 * @return DiscountApplication|null The best application, or null.
	 */
	private function selectBest( array $items, array $candidates, int $now, ?Customer $customer, ?array &$membership, array $membershipUniverse ): ?DiscountApplication {
		$cartSubtotal = 0;
		foreach ( $items as $item ) {
			$cartSubtotal += (int) ( $item['subtotal'] ?? 0 );
		}

		$bestDiscount   = null;
		$bestTotal      = 0;
		$bestApplicable = array();

		foreach ( $candidates as $discount ) {
			if ( ! $discount->isWithinWindow( $now ) || ! $discount->hasUsesRemaining() ) {
				continue;
			}

			if ( null !== $discount->minTotal() && $cartSubtotal < $discount->minTotal() ) {
				continue;
			}

			// Guests are checked when the order intent is created.
			if (
				$discount->isOncePerCustomer()
				&& null !== $customer
				&& null !== $customer->id()
				&& $this->orders->customerUsedDiscount( (int) $customer->id(), (int) $discount->id() )
			) {
				continue;
			}

			// Load the category memberships once, only when a candidate actually needs them.
			if ( DiscountScope::Categories === $discount->scopeType() && null === $membership ) {
				$membership = $this->terms->termIdsForProducts( $membershipUniverse, ProductTaxonomy::Category );
			}

			$applicable = $this->applicableItems( $discount, $items, is_array( $membership ) ? $membership : array() );

			if ( array() === $applicable ) {
				continue;
			}

			$total = $this->calculator->computeTotal( $discount, $applicable );

			if ( $total <= 0 ) {
				continue;
			}

			// Equal totals keep the lower id.
			if ( $total > $bestTotal ) {
				$bestTotal      = $total;
				$bestDiscount   = $discount;
				$bestApplicable = $applicable;
			}
		}

		if ( null === $bestDiscount ) {
			return null;
		}

		$allocation = $this->calculator->allocate( $bestTotal, count( $items ), $bestApplicable );

		return new DiscountApplication( $bestDiscount, $bestTotal, $allocation );
	}

	/**
	 * The active automatic discounts, read once and memoized for this resolver instance.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, Discount> The active automatic discounts, id-ascending.
	 *
	 * @throws DiscountPersistenceException When the discount read faults at the database.
	 */
	private function activeAutomatic(): array {
		if ( null === $this->activeCache ) {
			$this->activeCache = $this->discounts->activeAutomatic();
		}

		return $this->activeCache;
	}

	/**
	 * Return applicable cart items as index => subtotal.
	 *
	 * @since 1.0.0
	 *
	 * @param Discount                                          $discount   The candidate discount.
	 * @param array<int, array{product_id: int, subtotal: int}> $items      The cart items.
	 * @param array<int, array<int, int>>                       $membership Product id => its category term ids.
	 * @return array<int, int> The applicable items as index => subtotal.
	 */
	private function applicableItems( Discount $discount, array $items, array $membership ): array {
		$applicable = array();
		$scope      = $discount->scopeType();

		foreach ( $items as $index => $item ) {
			$productId = (int) ( $item['product_id'] ?? 0 );
			$covered   = false;

			switch ( $scope ) {
				case DiscountScope::All:
					$covered = true;
					break;
				case DiscountScope::Products:
					$covered = $discount->appliesToProduct( $productId );
					break;
				case DiscountScope::Categories:
					$covered = $this->productInCategories( $productId, $membership, $discount->categoryIds() );
					break;
			}

			if ( $covered ) {
				$applicable[ $index ] = (int) ( $item['subtotal'] ?? 0 );
			}
		}

		return $applicable;
	}

	/**
	 * Whether a product is directly assigned to one of the scoped categories.
	 *
	 * @since 1.0.0
	 *
	 * @param int                         $productId   The product id.
	 * @param array<int, array<int, int>> $membership  Product id => its category term ids.
	 * @param array<int, int>|null        $categoryIds The discount's scoped category ids.
	 * @return bool
	 */
	private function productInCategories( int $productId, array $membership, ?array $categoryIds ): bool {
		if ( null === $categoryIds ) {
			return false;
		}

		foreach ( $membership[ $productId ] ?? array() as $termId ) {
			if ( in_array( (int) $termId, $categoryIds, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return distinct product ids for the membership query.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, array{product_id: int, subtotal: int}> $items The cart items.
	 * @return array<int, int> The distinct product ids.
	 */
	private function productIds( array $items ): array {
		$ids = array();

		foreach ( $items as $item ) {
			$ids[] = (int) ( $item['product_id'] ?? 0 );
		}

		return array_values( array_unique( $ids ) );
	}
}
