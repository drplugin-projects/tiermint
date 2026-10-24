<?php
/**
 * Discount domain service: validation, management CRUD, and checkout application.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Discounts;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Customers\Customer;
use DrPlugin\WP\TierMint\Orders\OrderRepository;

/**
 * Creates and manages discounts, then applies codes to carts.
 *
 * Unknown, disabled and future codes share one message. Not final for collaborator tests.
 *
 * @since 1.0.0
 */
class DiscountService {

	/**
	 * The repository discounts are read from and written through.
	 *
	 * @var DiscountRepository
	 */
	private DiscountRepository $repository;

	/**
	 * The orders repository, used only to answer the once-per-customer question.
	 *
	 * @var OrderRepository
	 */
	private OrderRepository $orders;

	/**
	 * The shared reduction math, so a typed code and an automatic discount compute
	 * identical amounts.
	 *
	 * @var DiscountCalculator
	 */
	private DiscountCalculator $calculator;

	/**
	 * Wire the service with its repositories.
	 *
	 * @since 1.0.0
	 *
	 * @param DiscountRepository $repository Reads and writes discounts.
	 * @param OrderRepository    $orders     The orders repository (once-per-customer lookups).
	 */
	public function __construct( DiscountRepository $repository, OrderRepository $orders ) {
		$this->repository = $repository;
		$this->orders     = $orders;
		$this->calculator = new DiscountCalculator();
	}

	/**
	 * Create a discount from request data.
	 *
	 * Recheck a failed insert so a concurrent duplicate is reported correctly.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $data The discount input.
	 * @return Discount The created, reloaded discount.
	 *
	 * @throws DuplicateDiscountCodeException When the code is already taken.
	 * @throws DiscountPersistenceException  When the write fails for a reason other than a
	 *                                       recoverable race with a concurrent create.
	 */
	public function create( array $data ): Discount {
		$discount = $this->buildDiscount( $data, null );

		if ( null !== $this->repository->findByCode( $discount->code() ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw DuplicateDiscountCodeException::forCode( $discount->code() );
		}

		try {
			$id = $this->repository->create( $discount );
		} catch ( DiscountPersistenceException $e ) {
			// A concurrent insert can win after the first lookup.
			if ( null !== $this->repository->findByCode( $discount->code() ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
				throw DuplicateDiscountCodeException::forCode( $discount->code() );
			}

			throw $e;
		}

		return $this->requireDiscount( $id );
	}

	/**
	 * Apply supplied fields to an existing discount.
	 *
	 * The order flow alone updates use_count.
	 *
	 * @since 1.0.0
	 *
	 * @param int                  $id   The discount id to update.
	 * @param array<string, mixed> $data The partial discount input.
	 * @return Discount The reloaded, persisted discount. A field-level failure surfaces as the
	 *                  {@see DiscountValidationException} raised by the entity, and a failed
	 *                  write as the {@see DiscountPersistenceException} raised by the repository.
	 *
	 * @throws DiscountNotFoundException      When no discount has the given id.
	 * @throws DuplicateDiscountCodeException When a changed code collides with another discount.
	 */
	public function update( int $id, array $data ): Discount {
		$existing = $this->repository->find( $id );

		if ( null === $existing ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw DiscountNotFoundException::forId( $id );
		}

		$discount = $this->buildDiscount( $data, $existing );

		// Check only a changed code. Case-only changes keep the same code.
		if ( strtolower( $discount->code() ) !== strtolower( $existing->code() ) ) {
			$clash = $this->repository->findByCode( $discount->code() );

			if ( null !== $clash && $clash->id() !== $id ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
				throw DuplicateDiscountCodeException::forCode( $discount->code() );
			}
		}

		$this->repository->update( $discount );

		return $this->requireDiscount( $id );
	}

	/**
	 * Delete a discount without changing order snapshots.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The discount id to delete.
	 * @return void A failed delete surfaces as the {@see DiscountPersistenceException} raised
	 *              by the repository.
	 *
	 * @throws DiscountNotFoundException When no discount has the given id.
	 */
	public function delete( int $id ): void {
		if ( null === $this->repository->find( $id ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw DiscountNotFoundException::forId( $id );
		}

		$this->repository->delete( $id );
	}

	/**
	 * Load a discount by id.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The discount id.
	 * @return Discount The discount.
	 *
	 * @throws DiscountNotFoundException When no discount has the given id.
	 */
	public function get( int $id ): Discount {
		return $this->requireDiscount( $id );
	}

	/**
	 * Load a discount by its code, or null when none matches. The lookup is case
	 * insensitive.
	 *
	 * @since 1.0.0
	 *
	 * @param string $code The code to look up.
	 * @return Discount|null The discount, or null.
	 */
	public function getByCode( string $code ): ?Discount {
		return $this->repository->findByCode( $code );
	}

	/**
	 * List discounts for a collection view (delegates to the repository query).
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $args Query args (status, search, paging, ordering).
	 * @return array<int, Discount> The matching discounts.
	 */
	public function list( array $args ): array {
		return $this->repository->query( $args );
	}

	/**
	 * Count discounts matching a collection query (for pagination).
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $args Query args (status, search).
	 * @return int The total matching discount count.
	 */
	public function count( array $args ): int {
		return $this->repository->countQuery( $args );
	}

	/**
	 * Apply a code and allocate its reduction across cart items.
	 *
	 * Eligibility uses original subtotals.
	 *
	 * @since 1.0.0
	 *
	 * @since 1.0.0
	 *
	 * @param string                                            $code     The code the buyer typed.
	 * @param array<int, array{product_id: int, subtotal: int}> $items The cart items in minor units.
	 * @param Customer|null                                     $customer The resolved buyer, or null for an unresolved guest.
	 * @param array<int, int>|null                              $chargeSubtotals Optional reduced per-line
	 *        subtotals (index-aligned with `$items`) to compute the reduction against; gates still use `$items`.
	 * @return DiscountApplication The computed application.
	 *
	 * @throws DiscountValidationException  When the code cannot be applied (with a user message).
	 * @throws DiscountPersistenceException When the code lookup faults at the database.
	 */
	public function applyToCart( string $code, array $items, ?Customer $customer, ?array $chargeSubtotals = null ): DiscountApplication {
		$discount = $this->repository->findByCode( trim( $code ) );

		// Do not reveal unknown, disabled, future or automatic discounts.
		if ( null === $discount || ! $discount->isActive() || $discount->isAuto() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw $this->reject( $this->genericInvalidMessage() );
		}

		$now = time();

		if ( null !== $discount->startsAt() && $now < strtotime( $discount->startsAt() . ' UTC' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw $this->reject( $this->genericInvalidMessage() );
		}

		// A known expired code can use a specific message.
		if ( ! $discount->isWithinWindow( $now ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw $this->reject( __( 'This discount code has expired.', 'tiermint' ) );
		}

		if ( ! $discount->hasUsesRemaining() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw $this->reject( __( 'This discount code has reached its usage limit.', 'tiermint' ) );
		}

		// The order minimum is measured against the whole cart, not just the scoped items.
		$orderSubtotal = 0;
		foreach ( $items as $item ) {
			$orderSubtotal += (int) ( $item['subtotal'] ?? 0 );
		}

		if ( null !== $discount->minTotal() && $orderSubtotal < $discount->minTotal() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw $this->reject( __( 'Your order total is below the minimum required for this discount code.', 'tiermint' ) );
		}

		$applicable = array();
		foreach ( $items as $index => $item ) {
			if ( $discount->appliesToProduct( (int) ( $item['product_id'] ?? 0 ) ) ) {
				$applicable[ $index ] = (int) ( $item['subtotal'] ?? 0 );
			}
		}

		if ( array() === $applicable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw $this->reject( __( 'This discount code does not apply to the items in your cart.', 'tiermint' ) );
		}

		// Guests without an id cannot be checked against prior orders.
		if ( $discount->isOncePerCustomer() && null !== $customer && null !== $customer->id() ) {
			if ( $this->orders->customerUsedDiscount( (int) $customer->id(), (int) $discount->id() ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
				throw $this->reject( __( 'You have already used this discount code.', 'tiermint' ) );
			}
		}

		// Stacked codes use the reduced amounts after scope validation.
		$chargeBase = $applicable;

		if ( null !== $chargeSubtotals ) {
			$chargeBase = array();

			foreach ( array_keys( $applicable ) as $index ) {
				$chargeBase[ $index ] = max( 0, (int) ( $chargeSubtotals[ $index ] ?? 0 ) );
			}
		}

		$total      = $this->calculator->computeTotal( $discount, $chargeBase );
		$allocation = $this->calculator->allocate( $total, count( $items ), $chargeBase );

		return new DiscountApplication( $discount, $total, $allocation );
	}

	/**
	 * Record a completed use with the repository's atomic increment.
	 *
	 * @since 1.0.0
	 *
	 * @param int $discountId The discount to credit a use to.
	 * @return void
	 *
	 * @throws DiscountPersistenceException When the increment faults at the database.
	 */
	public function recordUse( int $discountId ): void {
		$this->repository->incrementUseCount( $discountId );
	}

	/**
	 * Build a validated discount from request data.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $data     The input.
	 * @param Discount|null        $existing The discount being updated, or null on create.
	 * @return Discount The validated entity.
	 *
	 * @throws DiscountValidationException When the type/status is unknown or a field-level
	 *                                     invariant fails.
	 */
	private function buildDiscount( array $data, ?Discount $existing ): Discount {
		$errors = array();

		$type   = $this->resolveType( $data, $existing, $errors );
		$status = $this->resolveStatus( $data, $existing, $errors );
		$scope  = $this->resolveScope( $data, $existing, $errors );

		if ( array() !== $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw DiscountValidationException::withErrors( $errors );
		}

		$auto = (bool) $this->merge( $data, 'auto', $existing, static fn ( Discount $d ): bool => $d->isAuto(), false, static fn ( $v ): bool => (bool) $v );
		$name = $this->merge( $data, 'name', $existing, static fn ( Discount $d ): ?string => $d->name(), null, array( $this, 'nullableString' ) );
		$code = (string) $this->merge( $data, 'code', $existing, static fn ( Discount $d ): string => $d->code(), '', static fn ( $v ): string => (string) $v );

		// Blank automatic codes keep an existing value or use a generated new value.
		if ( $auto && '' === trim( $code ) ) {
			$code = ( null !== $existing && '' !== trim( $existing->code() ) )
				? $existing->code()
				: $this->deriveAutoCode( is_string( $name ) ? $name : null );
		}

		return new Discount(
			null !== $existing ? $existing->id() : null,
			$code,
			$name,
			$type,
			$this->merge( $data, 'amount_percent', $existing, static fn ( Discount $d ): ?float => $d->amountPercent(), null, array( $this, 'nullableFloat' ) ),
			$this->merge( $data, 'amount_fixed', $existing, static fn ( Discount $d ): ?int => $d->amountFixed(), null, array( $this, 'nullableInt' ) ),
			$this->merge( $data, 'product_ids', $existing, static fn ( Discount $d ): ?array => $d->productIds(), null, array( $this, 'nullableIntList' ) ),
			$this->merge( $data, 'min_total', $existing, static fn ( Discount $d ): ?int => $d->minTotal(), null, array( $this, 'nullableInt' ) ),
			$this->merge( $data, 'max_uses', $existing, static fn ( Discount $d ): ?int => $d->maxUses(), null, array( $this, 'nullableCap' ) ),
			null !== $existing ? $existing->useCount() : 0,
			(bool) $this->merge( $data, 'once_per_customer', $existing, static fn ( Discount $d ): bool => $d->isOncePerCustomer(), false, static fn ( $v ): bool => (bool) $v ),
			$this->merge( $data, 'starts_at', $existing, static fn ( Discount $d ): ?string => $d->startsAt(), null, array( $this, 'nullableString' ) ),
			$this->merge( $data, 'expires_at', $existing, static fn ( Discount $d ): ?string => $d->expiresAt(), null, array( $this, 'nullableString' ) ),
			$status,
			null !== $existing ? $existing->createdAt() : null,
			null !== $existing ? $existing->updatedAt() : null,
			$auto,
			$scope,
			$this->merge( $data, 'category_ids', $existing, static fn ( Discount $d ): ?array => $d->categoryIds(), null, array( $this, 'nullableIntList' ) )
		);
	}

	/**
	 * Resolve an automatic discount scope from request data.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $data     The input.
	 * @param Discount|null        $existing The discount being updated, or null.
	 * @param array<int, string>   $errors   Error accumulator, appended to by reference.
	 * @return DiscountScope|null The resolved scope, or null to let the entity derive it.
	 */
	private function resolveScope( array $data, ?Discount $existing, array &$errors ): ?DiscountScope {
		if ( ! array_key_exists( 'scope_type', $data ) ) {
			return null !== $existing ? $existing->scopeType() : null;
		}

		$scope = DiscountScope::tryFrom( (string) $data['scope_type'] );

		if ( null === $scope ) {
			$errors[] = __( 'The discount scope must be all, products, or categories.', 'tiermint' );

			return null !== $existing ? $existing->scopeType() : null;
		}

		return $scope;
	}

	/**
	 * Derive a unique code for an automatic discount.
	 *
	 * Use a numeric suffix before a random fallback.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $name The discount name to derive from.
	 * @return string The unique derived code.
	 */
	private function deriveAutoCode( ?string $name ): string {
		$base = null === $name ? '' : (string) preg_replace( '/[^A-Z0-9]/', '', strtoupper( $name ) );

		if ( '' === $base ) {
			$base = 'AUTO';
		}

		$base = substr( $base, 0, 50 );

		if ( null === $this->repository->findByCode( $base ) ) {
			return $base;
		}

		for ( $i = 2; $i <= 99; $i++ ) {
			$suffix    = (string) $i;
			$candidate = substr( $base, 0, 50 - strlen( $suffix ) ) . $suffix;

			if ( null === $this->repository->findByCode( $candidate ) ) {
				return $candidate;
			}
		}

		// Use random text when all numeric suffixes are used.
		return substr( 'AUTO' . strtoupper( bin2hex( random_bytes( 8 ) ) ), 0, 50 );
	}

	/**
	 * Resolve a request field, existing value or create default.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $data         The input.
	 * @param string               $key          The field key.
	 * @param Discount|null        $existing     The discount being updated, or null.
	 * @param callable             $fromExisting Reads the field from the existing discount.
	 * @param mixed                $fallback     The create default when the key is absent.
	 * @param callable             $cast         Casts the raw request value.
	 * @return mixed The resolved field value.
	 */
	private function merge( array $data, string $key, ?Discount $existing, callable $fromExisting, $fallback, callable $cast ) {
		if ( array_key_exists( $key, $data ) ) {
			return $cast( $data[ $key ] );
		}

		return null !== $existing ? $fromExisting( $existing ) : $fallback;
	}

	/**
	 * Resolve a type, defaulting to the saved or percent type.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $data     The input.
	 * @param Discount|null        $existing The discount being updated, or null.
	 * @param array<int, string>   $errors   Error accumulator, appended to by reference.
	 * @return DiscountType The resolved type.
	 */
	private function resolveType( array $data, ?Discount $existing, array &$errors ): DiscountType {
		if ( ! array_key_exists( 'type', $data ) ) {
			return null !== $existing ? $existing->type() : DiscountType::Percent;
		}

		$type = DiscountType::tryFrom( (string) $data['type'] );

		if ( null === $type ) {
			$errors[] = __( 'The discount type must be either "percent" or "fixed".', 'tiermint' );

			return null !== $existing ? $existing->type() : DiscountType::Percent;
		}

		return $type;
	}

	/**
	 * Resolve a status, defaulting to the saved or active status.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $data     The input.
	 * @param Discount|null        $existing The discount being updated, or null.
	 * @param array<int, string>   $errors   Error accumulator, appended to by reference.
	 * @return DiscountStatus The resolved status.
	 */
	private function resolveStatus( array $data, ?Discount $existing, array &$errors ): DiscountStatus {
		if ( ! array_key_exists( 'status', $data ) ) {
			return null !== $existing ? $existing->status() : DiscountStatus::Active;
		}

		$status = DiscountStatus::tryFrom( (string) $data['status'] );

		if ( null === $status ) {
			$errors[] = __( 'The discount status must be either "active" or "inactive".', 'tiermint' );

			return null !== $existing ? $existing->status() : DiscountStatus::Active;
		}

		return $status;
	}

	/**
	 * Reload a discount by id after a write, failing loudly if it vanished.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The discount id.
	 * @return Discount The reloaded discount.
	 *
	 * @throws DiscountNotFoundException When the discount cannot be reloaded.
	 */
	private function requireDiscount( int $id ): Discount {
		$discount = $this->repository->find( $id );

		if ( null === $discount ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw DiscountNotFoundException::forId( $id );
		}

		return $discount;
	}

	/**
	 * Build a rejection exception carrying a single user-facing message.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message The translated, user-facing reason.
	 * @return DiscountValidationException The rejection.
	 */
	private function reject( string $message ): DiscountValidationException {
		return DiscountValidationException::withErrors( array( $message ) );
	}

	/**
	 * Get the generic message for unknown, disabled and future codes.
	 *
	 * @since 1.0.0
	 *
	 * @return string The translated message.
	 */
	private function genericInvalidMessage(): string {
		return __( 'That is not a valid discount code.', 'tiermint' );
	}

	/**
	 * Cast a raw value to a non-empty trimmed string, or null when blank or null.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value The raw value.
	 * @return string|null The string, or null.
	 */
	private function nullableString( $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		$string = trim( (string) $value );

		return '' === $string ? null : $string;
	}

	/**
	 * Cast a raw value to an int, or null when null or an empty string.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value The raw value.
	 * @return int|null The int, or null.
	 */
	private function nullableInt( $value ): ?int {
		if ( null === $value || '' === $value ) {
			return null;
		}

		return (int) $value;
	}

	/**
	 * Cast an admin usage cap, with zero meaning unlimited.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value The raw value.
	 * @return int|null The positive cap, or null for unlimited.
	 */
	private function nullableCap( $value ): ?int {
		$count = ( null === $value || '' === $value ) ? 0 : (int) $value;

		return $count > 0 ? $count : null;
	}

	/**
	 * Cast a raw value to a float, or null when null or an empty string.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value The raw value.
	 * @return float|null The float, or null.
	 */
	private function nullableFloat( $value ): ?float {
		if ( null === $value || '' === $value ) {
			return null;
		}

		return (float) $value;
	}

	/**
	 * Cast a non-empty list of integers.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value The raw value.
	 * @return array<int, int>|null The int list, or null.
	 */
	private function nullableIntList( $value ): ?array {
		if ( ! is_array( $value ) || array() === $value ) {
			return null;
		}

		return array_map( static fn ( $v ): int => (int) $v, array_values( $value ) );
	}
}
