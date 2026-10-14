<?php
/**
 * Order service.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Orders;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Customers\CustomerService;
use DrPlugin\WP\TierMint\Products\Product;
use DrPlugin\WP\TierMint\Products\ProductPrice;
use DrPlugin\WP\TierMint\Products\ProductService;
use DrPlugin\WP\TierMint\Products\ProductStatus;

/**
 * Creates and updates orders.
 *
 * It prices lines at checkout and uses OrderRepository.
 *
 * @since 1.0.0
 */
class OrderService {

	/**
	 * The repository orders are read from and written through.
	 *
	 * @var OrderRepository
	 */
	private OrderRepository $orders;

	/**
	 * The customer service used to resolve the buyer and maintain purchase counters.
	 *
	 * @var CustomerService
	 */
	private CustomerService $customers;

	/**
	 * The product catalog used to re-price cart lines at order-creation time.
	 *
	 * @var ProductService
	 */
	private ProductService $products;

	/**
	 * Cart source and clear target.
	 *
	 * @var CartService
	 */
	private CartService $cart;

	/**
	 * The state machine that decides which status transitions are legal.
	 *
	 * @var OrderStateMachine
	 */
	private OrderStateMachine $machine;

	/**
	 * Checkout discount service, when available.
	 *
	 * @var \DrPlugin\WP\TierMint\Discounts\DiscountService|null
	 */
	private ?\DrPlugin\WP\TierMint\Discounts\DiscountService $discounts;

	/**
	 * Resolves automatic discounts when the engine is available.
	 *
	 * @var \DrPlugin\WP\TierMint\Discounts\AutomaticDiscountResolver|null
	 */
	private ?\DrPlugin\WP\TierMint\Discounts\AutomaticDiscountResolver $autoResolver;

	/**
	 * Reads the code and automatic discount combination rule.
	 *
	 * @var \DrPlugin\WP\TierMint\Discounts\DiscountSettings|null
	 */
	private ?\DrPlugin\WP\TierMint\Discounts\DiscountSettings $discountSettings;

	/**
	 * Builds the same discount result for quotes and checkout.
	 *
	 * @var \DrPlugin\WP\TierMint\Discounts\DiscountComposer|null
	 */
	private ?\DrPlugin\WP\TierMint\Discounts\DiscountComposer $discountComposer = null;

	/**
	 * Wire the service with its repository and collaborators.
	 *
	 * @since 1.0.0
	 *
	 * @param OrderRepository                                                $orders           The orders repository.
	 * @param CustomerService                                                $customers        The customer service (buyer resolution + counters).
	 * @param ProductService                                                 $products         The product catalog for authoritative pricing.
	 * @param CartService                                                    $cart             The cart service (source of the order, cleared after).
	 * @param OrderStateMachine                                              $machine          The status transition guard.
	 * @param \DrPlugin\WP\TierMint\Discounts\DiscountService|null           $discounts        The discount engine; null disables code redemption.
	 * @param \DrPlugin\WP\TierMint\Discounts\AutomaticDiscountResolver|null $autoResolver   The automatic-discount resolver; null disables automatic discounts.
	 * @param \DrPlugin\WP\TierMint\Discounts\DiscountSettings|null          $discountSettings The combination-rule reader; null defaults to code-replaces-auto.
	 */
	public function __construct(
		OrderRepository $orders,
		CustomerService $customers,
		ProductService $products,
		CartService $cart,
		OrderStateMachine $machine,
		?\DrPlugin\WP\TierMint\Discounts\DiscountService $discounts = null,
		?\DrPlugin\WP\TierMint\Discounts\AutomaticDiscountResolver $autoResolver = null,
		?\DrPlugin\WP\TierMint\Discounts\DiscountSettings $discountSettings = null
	) {
		$this->orders           = $orders;
		$this->customers        = $customers;
		$this->products         = $products;
		$this->cart             = $cart;
		$this->machine          = $machine;
		$this->discounts        = $discounts;
		$this->autoResolver     = $autoResolver;
		$this->discountSettings = $discountSettings;
	}

	/**
	 * Create a pending order from the current cart, then clear it.
	 *
	 * Server pricing validates lines and discounts. Extensions add tax after discounts.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed>      $buyer   The buyer input for find-or-create (`email`
	 *                                            required, optional `user_id`, `name`).
	 * @param array<string, mixed>      $context Optional request context (`ip_address`, `country`,
	 *                                            `discount_code`). Any `tiermint_preview` key is
	 *                                            stripped: a real checkout is never a preview.
	 * @param array<int, string>|null   $notices Out parameter: receives the buyer-facing
	 *                                         advisories `tiermint_checkout_quote_notices`
	 *                                         produced for this charge, so the caller can
	 *                                         show them next to the amount they explain.
	 * @param array<string, mixed>|null $discountBreakdown Out parameter: receives the code and
	 *                                         automatic-discount components as they landed
	 *                                         (`discount`, `auto_discount`, same shape as
	 *                                         {@see quote()}), so the intent response can tell
	 *                                         the client which line each reduction is.
	 * @return Order The created, reloaded pending order.
	 *
	 * @throws OrderValidationException When the cart is empty or a line is no longer
	 *                                  purchasable.
	 * @throws \DrPlugin\WP\TierMint\Discounts\DiscountValidationException When a
	 *                                  request-supplied discount code is invalid (a
	 *                                  cart-stored code is dropped instead, never thrown).
	 */
	public function createFromCart( array $buyer, array $context = array(), ?array &$notices = null, ?array &$discountBreakdown = null ): Order {
		// Clear this output before a failed checkout can leave stale notices.
		$notices = array();

		// Only quote() may mark a request as a preview.
		unset( $context['tiermint_preview'] );

		// Set the gross-mode marker from the filter, over any caller value.
		$context = $this->markPricingMode( $context );

		$cart = $this->cart->getCart();

		if ( $cart->isEmpty() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured validation message, surfaced as an API error, never echoed as HTML.
			throw OrderValidationException::withErrors( array( __( 'The cart is empty.', 'tiermint' ) ) );
		}

		// Reject unavailable lines before customer creation writes a record.
		$clampNotices = array();
		$items        = $this->buildItems( $cart, $clampNotices );

		/**
		 * Filters checkout errors after line pricing and before customer creation.
		 *
		 * Return translated buyer messages. quote() returns these as notices.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, string>    $errors  Error accumulator, empty by default.
		 * @param array<int, OrderItem> $items   The live-priced line items.
		 * @param array<string, mixed>  $context The request context (ip_address, country,
		 *        discount_code, plus any keys an extension added through
		 *        `tiermint_checkout_intent_context`).
		 */
		$vetoes = apply_filters( 'tiermint_checkout_errors', array(), $items, $context );

		if ( is_array( $vetoes ) ) {
			$vetoes = array_values( array_filter( $vetoes, 'is_string' ) );

			if ( array() !== $vetoes ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Extension-supplied translated validation messages, surfaced as API errors, never echoed as HTML.
				throw OrderValidationException::withErrors( $vetoes );
			}
		}

		// Reject request codes. Report stale cart codes.
		$requestCode  = trim( (string) ( $context['discount_code'] ?? '' ) );
		$fromRequest  = '' !== $requestCode;
		$discountCode = $fromRequest ? $requestCode : (string) ( $cart->discountCode() ?? '' );

		$codeApplication = null;
		$discountDropped = null;

		// Reject a bad request code before customer creation. Recheck it after lookup.
		if ( '' !== $discountCode && ( $fromRequest || null !== $this->discounts ) ) {
			if ( $fromRequest ) {
				$codeApplication = $this->applyDiscount( $discountCode, $items, null );
			} else {
				try {
					$codeApplication = $this->applyDiscount( $discountCode, $items, null );
				} catch ( \DrPlugin\WP\TierMint\Discounts\DiscountValidationException $e ) {
					$discountDropped = $this->discountDroppedNotice( $e );
				}
			}
		}

		$customer = $this->customers->findOrCreate( $buyer );

		// Recheck once-per-customer after the buyer is resolved.
		if ( null !== $codeApplication ) {
			try {
				$codeApplication = $this->applyDiscount( $discountCode, $items, $customer );
			} catch ( \DrPlugin\WP\TierMint\Discounts\DiscountValidationException $e ) {
				if ( $fromRequest ) {
					throw $e;
				}

				$codeApplication = null;
				$discountDropped = $this->discountDroppedNotice( $e );
			}
		}

		// Compose the code and automatic discount into the final line allocations.
		$composition     = $this->composeWithAutomatic( $items, $customer, $codeApplication );
		$items           = $composition['items'];
		$primaryDiscount = $composition['primary'];
		$autoMeta        = $composition['auto_meta'];

		// Return each discount component in the same shape as quote().
		$discountBreakdown = $this->discountPayload( $composition['code_component'], $composition['auto_component'] );

		if ( null !== $primaryDiscount ) {
			/**
			 * Fires after a discount is applied to a checkout.
			 *
			 * @since 1.0.0
			 *
			 * @param \DrPlugin\WP\TierMint\Discounts\Discount $discount The primary applied discount.
			 * @param array<int, array<string, int>>           $cart     The priced lines the
			 *        discount was computed against: product_id and subtotal (minor units) per line.
			 */
			do_action( 'tiermint_discount_applied', $primaryDiscount, $this->discountItems( $items ) );
		}

		// Tax follows discounts. Gross mode rebases the lines to net, so the sums
		// come from the taxed items. Net mode is a no-op here.
		$items = $this->applyTaxes( $items, $context );

		$subtotal      = $this->sumField( $items, 'subtotal' );
		$discountTotal = $this->sumField( $items, 'discount' );
		$taxTotal      = $this->sumField( $items, 'tax' );

		// Notices explain settled amounts and cannot change them.
		$noticeSeed = null !== $discountDropped ? array_merge( $clampNotices, array( $discountDropped ) ) : $clampNotices;
		$notices    = $this->checkoutNotices( $noticeSeed, $this->lineBreakdown( $items ), $context );

		$order = new Order(
			null,
			OrderUuid::generate(),
			OrderStatus::Pending,
			$customer->id(),
			$customer->email(),
			$this->storeCurrency(),
			$subtotal,
			$discountTotal,
			$taxTotal,
			$subtotal - $discountTotal + $taxTotal,
			null,
			null,
			null !== $primaryDiscount ? $primaryDiscount->id() : null,
			null !== $primaryDiscount ? $primaryDiscount->code() : null,
			OrderType::Purchase,
			null,
			$this->contextValue( $context, 'ip_address' ),
			$this->contextValue( $context, 'country' ),
			null,
			null,
			null,
			$items
		);

		$saved = $this->requireOrder( $this->orders->create( $order ) );

		// Save the stacked automatic component before order-created listeners run.
		if ( null !== $autoMeta ) {
			update_metadata( 'tiermint_order', (int) $saved->id(), '_tiermint_auto_discount_id', $autoMeta['id'] );
			update_metadata( 'tiermint_order', (int) $saved->id(), '_tiermint_auto_discount', $autoMeta['amount'] );
		}

		// Only empty the cart once the order is safely persisted.
		$this->cart->clear();

		/**
		 * Fires after a pending order is created at checkout, before payment.
		 *
		 * @since 1.0.0
		 *
		 * @param Order $order The newly created pending order, reloaded from storage.
		 */
		do_action( 'tiermint_order_created', $saved );

		return $saved;
	}

	/**
	 * Price the current cart without changing it.
	 *
	 * Preview listeners must avoid irreversible work. Vetoes become notices.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $context Optional request context (`ip_address`, `country`,
	 *                                       `discount_code`, plus anything an extension added
	 *                                       through `tiermint_checkout_intent_context`). A
	 *                                       `tiermint_preview` key is set to true here,
	 *                                       overwriting anything supplied, so a caller can
	 *                                       never pass a quote off to a listener as a real
	 *                                       checkout.
	 * @return array{currency: string, subtotal: int, discount_total: int, discount: array<string, mixed>|null, auto_discount: array<string, mixed>|null, tax_total: int, total: int, lines: array<int, array<string, mixed>>, notices: array<int, string>}
	 *         The priced quote in minor units of the store currency. `discount` is the typed
	 *         code line (null when no code was submitted, `{valid, code, label, amount}` when
	 *         applied, `{valid:false, code, message}` when refused), carrying amounts only.
	 *         `auto_discount` is the automatic-discount line (`{label, amount}` when one
	 *         applied, null otherwise), with no code. `notices` carries extension vetoes and
	 *         any quantity-clamp advisory; a refused discount code rides the `discount` field.
	 *
	 * @throws OrderValidationException When the cart is empty or a line is no longer
	 *                                  purchasable.
	 */
	public function quote( array $context = array() ): array {
		$cart = $this->cart->getCart();

		if ( $cart->isEmpty() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured validation message, surfaced as an API error, never echoed as HTML.
			throw OrderValidationException::withErrors( array( __( 'The cart is empty.', 'tiermint' ) ) );
		}

		/* Preview listeners must avoid external work. */
		$context['tiermint_preview'] = true;

		// Mark the pricing mode for every seam, as a checkout does.
		$context = $this->markPricingMode( $context );

		$clampNotices = array();
		$items        = $this->buildItems( $cart, $clampNotices );

		$notices = array();

		/** This filter is documented in {@see createFromCart()}. */
		$vetoes = apply_filters( 'tiermint_checkout_errors', array(), $items, $context );

		if ( is_array( $vetoes ) ) {
			// A quote reports vetoes but still returns its current price.
			$notices = array_values( array_filter( $vetoes, 'is_string' ) );
		}

		// Add clamp notices after filtering the veto list.
		$notices = array_merge( $notices, $clampNotices );

		$discount    = null;
		$requestCode = trim( (string) ( $context['discount_code'] ?? '' ) );

		// A request code wins. Otherwise price the code stored with the cart.
		$discountCode    = '' !== $requestCode ? $requestCode : (string) ( $cart->discountCode() ?? '' );
		$codeApplication = null;

		if ( '' !== $discountCode ) {
			try {
				// Quotes do not create customers. Check customer rules at checkout.
				$codeApplication = $this->applyDiscount( $discountCode, $items, null );
			} catch ( \DrPlugin\WP\TierMint\Discounts\DiscountValidationException $e ) {
				// Report a rejected code at full price.
				$errors   = array_values( array_filter( $e->getErrors(), 'is_string' ) );
				$discount = array(
					'valid'   => false,
					'code'    => $discountCode,
					'message' => array() !== $errors ? $errors[0] : $e->getMessage(),
				);
			}
		}

		// Compose automatic and code discounts for the quote.
		$composition = $this->composeWithAutomatic( $items, null, $codeApplication );
		$items       = $composition['items'];

		// Keep code and automatic discount fields separate.
		$payload = $this->discountPayload( $composition['code_component'], $composition['auto_component'] );

		if ( null !== $payload['discount'] ) {
			$discount = $payload['discount'];
		}

		$autoDiscount = $payload['auto_discount'];

		// Tax follows discount allocation and, in gross mode, rebases the lines to
		// net, so the money sums are taken from the taxed items (a no-op in net mode).
		$items = $this->applyTaxes( $items, $context );

		$lines = $this->lineBreakdown( $items );

		// Quotes retain non-fatal notices for this shared filter.
		$notices = $this->checkoutNotices( $notices, $lines, $context );

		return array(
			'currency'       => $this->storeCurrency(),
			'subtotal'       => $this->sumField( $items, 'subtotal' ),
			// The shown discount, not the order row's net share (gross mode).
			'discount_total' => $this->displayedDiscountTotal( $payload ),
			// Typed-code discount payload.
			'discount'       => $discount,
			// Automatic discount payload.
			'auto_discount'  => $autoDiscount,
			'tax_total'      => $this->sumField( $items, 'tax' ),
			// total = sum(lines.total). In gross mode subtotal - discount + tax does not.
			'total'          => $this->sumField( $items, 'total' ),
			'lines'          => $lines,
			'notices'        => $notices,
		);
	}

	/**
	 * Filter buyer notices after cart figures are settled.
	 *
	 * Listeners cannot change amounts. Failures keep existing notices.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string>               $notices The advisories to seed the filter with:
	 *        a quote's vetoes and discount refusal, or on a checkout any quantity-clamp
	 *        advisory the backstop produced plus the advisory for a cart-stored discount
	 *        code that went invalid and was dropped (a veto and a request-supplied bad
	 *        code are hard failures on a checkout, so those never reach here).
	 * @param array<int, array<string, mixed>> $lines   The priced line breakdown.
	 * @param array<string, mixed>             $context The request context the cart was priced against.
	 * @return array<int, string> The advisories, non-strings dropped.
	 */
	private function checkoutNotices( array $notices, array $lines, array $context ): array {
		try {
			/**
			 * Filters buyer notices for a priced cart.
			 *
			 * Return translated strings after pricing.
			 *
			 * @since 1.0.0
			 *
			 * @param array<int, string>               $notices Advisories so far: on a quote, extension
			 *        vetoes then any discount refusal; on a checkout, any quantity-clamp advisory the
			 *        backstop produced plus a dropped cart-stored discount code (a veto and a
			 *        request-supplied bad code are hard failures on a checkout, so they never reach here).
			 * @param array<int, array<string, mixed>> $lines   The priced line breakdown, in the shape
			 *        the quote route returns it.
			 * @param array<string, mixed>             $context The request context the cart was priced against.
			 */
			$filtered = apply_filters( 'tiermint_checkout_quote_notices', $notices, $lines, $context );
		} catch ( \Throwable ) {
			// Keep existing notices when a listener fails.
			return array_values( array_filter( $notices, 'is_string' ) );
		}

		return is_array( $filtered ) ? array_values( array_filter( $filtered, 'is_string' ) ) : array();
	}

	/**
	 * Build the shared quote and checkout line payload.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, OrderItem> $items The priced, discount-allocated, taxed line items.
	 * @return array<int, array<string, mixed>> The per-line breakdown.
	 */
	private function lineBreakdown( array $items ): array {
		$lines = array();

		foreach ( array_values( $items ) as $item ) {
			$lines[] = array(
				'product_id' => $item->productId(),
				// Price ids are present for priced cart lines.
				'price_id'   => (int) ( $item->priceId() ?? 0 ),
				'name'       => $item->productName(),
				'quantity'   => $item->quantity(),
				'subtotal'   => $item->subtotal(),
				'discount'   => $item->discount(),
				'tax'        => $item->tax(),
				'total'      => $item->total(),
			);
		}

		return $lines;
	}

	/**
	 * Create a renewal order from a paid recurring invoice.
	 *
	 * The gateway transaction id deduplicates webhook redelivery.
	 *
	 * @since 1.0.0
	 *
	 * @param Order  $parentOrder  The initial (or previous) order the renewal chains to.
	 * @param int    $amount       The invoice amount in minor units.
	 * @param string $currency     The invoice currency; must match the parent's.
	 * @param string $gateway      The gateway id, e.g. 'stripe'.
	 * @param string $gatewayTxnId The gateway-side invoice/transaction id.
	 * @return Order The persisted renewal order (or the already-recorded one). A failed
	 *               write propagates as OrderPersistenceException from the repository.
	 *
	 * @throws OrderValidationException When the parent is unsaved, is a refund row, has no
	 *                                  line items, or the currency disagrees with it.
	 */
	public function createRenewal( Order $parentOrder, int $amount, string $currency, string $gateway, string $gatewayTxnId ): Order {
		$existing = $this->orders->findByGatewayTxnId( $gateway, $gatewayTxnId );

		if ( null !== $existing ) {
			return $existing;
		}

		$errors = array();

		if ( null === $parentOrder->id() ) {
			$errors[] = __( 'A renewal needs a saved parent order.', 'tiermint' );
		}

		if ( OrderType::Refund === $parentOrder->type() ) {
			$errors[] = __( 'A refund record cannot be renewed.', 'tiermint' );
		}

		if ( strtoupper( $currency ) !== $parentOrder->currency() ) {
			$errors[] = __( 'A renewal must use the same currency as its parent order.', 'tiermint' );
		}

		if ( array() === $parentOrder->items() ) {
			$errors[] = __( 'A renewal needs a parent order with a line item.', 'tiermint' );
		}

		if ( array() !== $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured validation messages, surfaced as API errors, never echoed as HTML.
			throw OrderValidationException::withErrors( $errors );
		}

		// Preserve the parent line identity with the gateway invoice amount.
		$parentItem = $parentOrder->items()[0];
		$item       = new OrderItem(
			null,
			null,
			$parentItem->productId(),
			$parentItem->priceId(),
			$parentItem->productName(),
			$parentItem->priceName(),
			$parentItem->quantity(),
			$amount,
			0,
			0,
			$amount
		);

		$order = new Order(
			null,
			OrderUuid::generate(),
			OrderStatus::Pending,
			$parentOrder->customerId(),
			$parentOrder->email(),
			$parentOrder->currency(),
			$amount,
			0,
			0,
			$amount,
			$gateway,
			$gatewayTxnId,
			null,
			null,
			OrderType::Renewal,
			$parentOrder->id(),
			null,
			null,
			null,
			null,
			null,
			array( $item )
		);

		$saved = $this->requireOrder( $this->orders->create( $order ) );

		/** This action is documented in {@see createFromCart()}. */
		do_action( 'tiermint_order_created', $saved );

		return $saved;
	}

	/**
	 * Mark an order completed after payment.
	 *
	 * One webhook runs side effects. Completion is otherwise idempotent.
	 *
	 * @since 1.0.0
	 *
	 * @param int $orderId The order to complete.
	 * @return Order The reloaded, completed order.
	 *
	 * @throws OrderNotFoundException   When no order has the given id.
	 * @throws OrderTransitionException When the order cannot move to completed from its
	 *                                  current status.
	 * @throws OrderPersistenceException When the write fails.
	 */
	public function markCompleted( int $orderId ): Order {
		return $this->complete( $orderId )[0];
	}

	/**
	 * Complete an order and report whether this call did it.
	 *
	 * Callers with their own once-only side effects (a renewal's license
	 * extension and hook) run them only when this returns the order.
	 *
	 * @since 1.0.0
	 *
	 * @param int $orderId The order to complete.
	 * @return Order|null The completed order when this call won the swap; null when it
	 *                    was already completed or another writer completed it first.
	 *
	 * @throws OrderNotFoundException   When no order has the given id.
	 * @throws OrderTransitionException When the order cannot move to completed from its
	 *                                  current status.
	 * @throws OrderPersistenceException When the write fails.
	 */
	public function completeOnce( int $orderId ): ?Order {
		list( $order, $won ) = $this->complete( $orderId );

		return $won ? $order : null;
	}

	/**
	 * Move an order to completed, running side effects only for the winner.
	 *
	 * @since 1.0.0
	 *
	 * @param int $orderId The order to complete.
	 * @return array{0: Order, 1: bool} The reloaded order and whether this call won the swap.
	 *
	 * @throws OrderNotFoundException   When no order has the given id.
	 * @throws OrderTransitionException When the order cannot move to completed from its
	 *                                  current status.
	 * @throws OrderPersistenceException When the write fails.
	 */
	private function complete( int $orderId ): array {
		$order = $this->requireOrder( $orderId );

		if ( $order->isCompleted() ) {
			return array( $order, false );
		}

		$this->machine->assertCanTransition( $order->status(), OrderStatus::Completed );

		$won = $this->orders->transitionStatus(
			$order,
			$this->machine->sourcesFor( OrderStatus::Completed ),
			OrderStatus::Completed,
			gmdate( 'Y-m-d H:i:s' )
		);

		$saved = $this->requireOrder( $orderId );

		if ( ! $won ) {
			// Another delivery owns side effects. An abandoned paid order is an anomaly.
			if ( OrderStatus::Abandoned === $saved->status() ) {
				// Raise the normal transition error for a paid abandoned order.
				$this->machine->assertCanTransition( $saved->status(), OrderStatus::Completed );
			}

			return array( $saved, false );
		}

		// Keep the buyer's purchase counters current.
		if ( null !== $saved->customerId() ) {
			$this->customers->recordCompletedPurchase( $saved->customerId(), $saved->total() );
		}

		/**
			 * Fires after an order is paid and marked completed.
		 *
		 * @since 1.0.0
		 *
		 * @param Order $order The completed order, reloaded from storage.
		 */
		do_action( 'tiermint_order_completed', $saved );

		return array( $saved, true );
	}

	/**
	 * Find an order by its gateway transaction id.
	 *
	 * @since 1.0.0
	 *
	 * @param string $gateway The gateway id, e.g. 'stripe'.
	 * @param string $txnId   The gateway-side transaction id.
	 * @return Order|null The order, or null when no order carries the id.
	 */
	public function getByGatewayTxnId( string $gateway, string $txnId ): ?Order {
		return $this->orders->findByGatewayTxnId( $gateway, $txnId );
	}

	/**
	 * Save the gateway and transaction id for an order.
	 *
	 * @since 1.0.0
	 *
	 * @param int         $orderId      The order to stamp.
	 * @param string      $gateway      The gateway id, e.g. 'stripe'.
	 * @param string|null $gatewayTxnId The gateway-side transaction id, or null when the gateway has none.
	 * @return Order The reloaded, stamped order.
	 *
	 * @throws OrderNotFoundException    When no order has the given id.
	 * @throws OrderPersistenceException When the write fails.
	 */
	public function attachGateway( int $orderId, string $gateway, ?string $gatewayTxnId ): Order {
		$order = $this->requireOrder( $orderId );

		// Only gateway columns change, so a stale status cannot overwrite completion.
		$this->orders->stampGateway( $order, $gateway, $gatewayTxnId );

		return $this->requireOrder( $orderId );
	}

	/**
	 * Mark a payment as failed and fire its hook.
	 *
	 * The compare-and-swap cannot overwrite a racing completion.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $orderId The order to fail.
	 * @param string $reason  A short machine or gateway reason for the failure.
	 * @return Order The reloaded, failed order.
	 *
	 * @throws OrderNotFoundException   When no order has the given id.
	 * @throws OrderTransitionException When the order cannot move to failed from its
	 *                                  current status.
	 * @throws OrderPersistenceException When the write fails.
	 */
	public function markFailed( int $orderId, string $reason ): Order {
		$order = $this->requireOrder( $orderId );

		if ( OrderStatus::Failed === $order->status() ) {
			return $order;
		}

		$this->machine->assertCanTransition( $order->status(), OrderStatus::Failed );

		$won = $this->orders->transitionStatus(
			$order,
			$this->machine->sourcesFor( OrderStatus::Failed ),
			OrderStatus::Failed
		);

		$saved = $this->requireOrder( $orderId );

		if ( ! $won ) {
			// A newer state owns the outcome and its hooks.
			return $saved;
		}

		/**
		 * Fires after an order's payment fails.
		 *
		 * @since 1.0.0
		 *
		 * @param Order  $order  The failed order, reloaded from storage.
		 * @param string $reason A short reason for the failure.
		 */
		do_action( 'tiermint_order_failed', $saved, $reason );

		return $saved;
	}

	/**
	 * Mark a stale pending order as abandoned.
	 *
	 * Fire its hook only when the compare-and-swap succeeds.
	 *
	 * @since 1.0.0
	 *
	 * @param int $orderId The order to abandon.
	 * @return Order The reloaded, abandoned order.
	 *
	 * @throws OrderNotFoundException   When no order has the given id.
	 * @throws OrderTransitionException When the order cannot move to abandoned from its
	 *                                  current status.
	 * @throws OrderPersistenceException When the write fails.
	 */
	public function markAbandoned( int $orderId ): Order {
		$order = $this->requireOrder( $orderId );

		if ( OrderStatus::Abandoned === $order->status() ) {
			return $order;
		}

		$this->machine->assertCanTransition( $order->status(), OrderStatus::Abandoned );

		// A competing completion keeps the paid order unchanged.
		$won = $this->orders->transitionStatus(
			$order,
			$this->machine->sourcesFor( OrderStatus::Abandoned ),
			OrderStatus::Abandoned
		);

		$saved = $this->requireOrder( $orderId );

		if ( ! $won ) {
			// Do not abandon an order another writer owns.
			return $saved;
		}

		/**
			 * Fires after the abandonment sweep marks an order abandoned.
		 *
		 * @since 1.0.0
		 *
		 * @param Order $order The abandoned order, reloaded from storage.
		 */
		do_action( 'tiermint_order_abandoned', $saved );

		return $saved;
	}

	/**
	 * Load an order by id, or null when it does not exist.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The order id.
	 * @return Order|null The order, or null.
	 */
	public function get( int $id ): ?Order {
		return $this->orders->find( $id );
	}

	/**
	 * Load an order by its public UUID.
	 *
	 * @since 1.0.0
	 *
	 * @param string $uuid The order uuid.
	 * @return Order|null The order, or null.
	 */
	public function getByUuid( string $uuid ): ?Order {
		return $this->orders->findByUuid( $uuid );
	}

	/**
	 * List orders for a collection view (delegates to the repository query).
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $args Query args (status, customer_id, product_id, search, paging, ordering).
	 * @return array<int, Order> The matching orders.
	 */
	public function list( array $args ): array {
		return $this->orders->query( $args );
	}

	/**
	 * Count orders matching a collection query (for pagination).
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $args Query args (status, customer_id, product_id, search).
	 * @return int The total matching order count.
	 */
	public function count( array $args ): int {
		return $this->orders->countQuery( $args );
	}

	/**
	 * Get stale pending purchases with no payment transaction.
	 *
	 * @since 1.0.0
	 *
	 * @param string $before A `Y-m-d H:i:s` UTC cutoff; orders created at/before it match.
	 * @param int    $limit  The maximum number of orders to return (batch cap).
	 * @return array<int, Order> The matching orders, oldest first.
	 */
	public function pendingForAbandonment( string $before, int $limit ): array {
		return $this->orders->pendingForAbandonment( $before, $limit );
	}

	/**
	 * Get pending purchases that have not received a recovery email.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $before      A `Y-m-d H:i:s` UTC cutoff; orders created at/before it match.
	 * @param string      $sentMetaKey The order-meta key marking an order already emailed.
	 * @param int         $limit       The maximum number of orders to return (batch cap).
	 * @param string|null $after       An optional `Y-m-d H:i:s` UTC lower bound; orders created before
	 *                                 it are excluded (too stale to recover). Null applies no lower bound.
	 * @return array<int, Order> The matching orders, oldest first.
	 */
	public function pendingAwaitingRecoveryEmail( string $before, string $sentMetaKey, int $limit, ?string $after = null ): array {
		return $this->orders->pendingAwaitingRecoveryEmail( $before, $sentMetaKey, $limit, $after );
	}

	/**
	 * Get purchases due for one cart-recovery step.
	 *
	 * @since 1.0.0
	 *
	 * @param string             $before           A `Y-m-d H:i:s` UTC cutoff; orders created at/before it match.
	 * @param string|null        $after            An optional `Y-m-d H:i:s` UTC lower bound; older orders are excluded.
	 * @param int                $stepsSent        The recovery `steps_sent` count an order must be at for this step.
	 * @param array<int, string> $terminalStatuses Recovery statuses that make a row terminal (excluded).
	 * @param string             $legacyMetaKey    The single-email-era sent marker, excluded on the first step only.
	 * @param int                $limit            The maximum number of orders to return (batch cap).
	 * @return array<int, Order> The matching orders, oldest first.
	 */
	public function purchaseOrdersDueForRecovery( string $before, ?string $after, int $stepsSent, array $terminalStatuses, string $legacyMetaKey, int $limit ): array {
		return $this->orders->purchaseOrdersDueForRecovery( $before, $after, $stepsSent, $terminalStatuses, $legacyMetaKey, $limit );
	}

	/**
	 * Check whether an email has a recent order metadata marker.
	 *
	 * @since 1.0.0
	 *
	 * @param string $email   The buyer email to match.
	 * @param string $metaKey The order-meta key to look for.
	 * @param string $since   A `Y-m-d H:i:s` UTC cutoff the marker value must be at/after.
	 * @return bool True when such an order exists.
	 */
	public function emailHasOrderWithMetaSince( string $email, string $metaKey, string $since ): bool {
		return $this->orders->emailHasOrderWithMetaSince( $email, $metaKey, $since );
	}

	/**
	 * Run a discount code through the discount engine against priced items.
	 *
	 * @since 1.0.0
	 *
	 * @param string                                        $code            The submitted code.
	 * @param array<int, OrderItem>                         $items           The live-priced line items.
	 * @param \DrPlugin\WP\TierMint\Customers\Customer|null $customer        The resolved buyer, or null
	 *                                                                       pre-resolution (skips the
	 *                                                                       once-per-customer rule only).
	 * @param array<int, int>|null                          $chargeSubtotals Optional reduced per-line
	 *        subtotals (index-aligned) to compute the reduction against when the code stacks on an
	 *        automatic discount; the code's gates still measure the original items.
	 * @return \DrPlugin\WP\TierMint\Discounts\DiscountApplication The computed application.
	 *
	 * @throws OrderValidationException When no discount engine is wired but a code was submitted:
	 *                                  charging full price against an entered code is never acceptable.
	 */
	private function applyDiscount( string $code, array $items, ?\DrPlugin\WP\TierMint\Customers\Customer $customer, ?array $chargeSubtotals = null ): \DrPlugin\WP\TierMint\Discounts\DiscountApplication {
		if ( null === $this->discounts ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured validation message, surfaced as an API error, never echoed as HTML.
			throw OrderValidationException::withErrors( array( __( 'Discount codes are not available on this store.', 'tiermint' ) ) );
		}

		return $this->discounts->applyToCart( $code, $this->discountItems( $items ), $customer, $chargeSubtotals );
	}

	/**
	 * Compose automatic and typed-code discounts into order lines.
	 *
	 * Stack automatic reductions before code discounts.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, OrderItem>                                    $items           The priced line items.
	 * @param \DrPlugin\WP\TierMint\Customers\Customer|null            $customer        The resolved buyer, or null on a quote.
	 * @param \DrPlugin\WP\TierMint\Discounts\DiscountApplication|null $codeApplication The validated code application, or null.
	 * @return array{items: array<int, OrderItem>, primary: \DrPlugin\WP\TierMint\Discounts\Discount|null, auto_meta: array{id: int, amount: int}|null, code_component: \DrPlugin\WP\TierMint\Discounts\DiscountApplication|null, auto_component: \DrPlugin\WP\TierMint\Discounts\DiscountApplication|null}
	 *         The allocated items, the primary discount, the stacked automatic component for order
	 *         meta, and the code/automatic contributions as they landed on the final price.
	 */
	private function composeWithAutomatic( array $items, ?\DrPlugin\WP\TierMint\Customers\Customer $customer, ?\DrPlugin\WP\TierMint\Discounts\DiscountApplication $codeApplication ): array {
		// Without an automatic engine, only the code can apply.
		if ( null === $this->autoResolver ) {
			$items = null !== $codeApplication ? $this->allocateDiscount( $items, $codeApplication ) : $items;

			return $this->composition( $items, null !== $codeApplication ? $codeApplication->discount() : null, null, $codeApplication, null );
		}

		// The shared composer keeps storefront and checkout discount results aligned.
		$composed = $this->discountComposer()->compose( $codeApplication, $this->discountItems( $items ), $customer );

		$items = $this->applyLineDiscounts( $items, $composed->perLine() );

		return $this->composition( $items, $composed->primary(), $composed->autoMeta(), $composed->codeComponent(), $composed->autoComponent() );
	}

	/**
	 * Get the shared automatic-discount composer.
	 *
	 * @since 1.0.0
	 *
	 * @return \DrPlugin\WP\TierMint\Discounts\DiscountComposer
	 */
	private function discountComposer(): \DrPlugin\WP\TierMint\Discounts\DiscountComposer {
		if ( null === $this->discountComposer ) {
			$this->discountComposer = new \DrPlugin\WP\TierMint\Discounts\DiscountComposer(
				$this->autoResolver,
				$this->discountSettings,
				$this->discounts
			);
		}

		return $this->discountComposer;
	}

	/**
	 * Build a composition with a stable return shape.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, OrderItem>                                    $items         The allocated line items.
	 * @param \DrPlugin\WP\TierMint\Discounts\Discount|null            $primary       The primary discount.
	 * @param array{id: int, amount: int}|null                         $autoMeta      The stacked automatic component for order meta.
	 * @param \DrPlugin\WP\TierMint\Discounts\DiscountApplication|null $codeComponent The code's contribution to the final price.
	 * @param \DrPlugin\WP\TierMint\Discounts\DiscountApplication|null $autoComponent The automatic discount's contribution.
	 * @return array{items: array<int, OrderItem>, primary: \DrPlugin\WP\TierMint\Discounts\Discount|null, auto_meta: array{id: int, amount: int}|null, code_component: \DrPlugin\WP\TierMint\Discounts\DiscountApplication|null, auto_component: \DrPlugin\WP\TierMint\Discounts\DiscountApplication|null}
	 */
	private function composition( array $items, ?\DrPlugin\WP\TierMint\Discounts\Discount $primary, ?array $autoMeta, ?\DrPlugin\WP\TierMint\Discounts\DiscountApplication $codeComponent, ?\DrPlugin\WP\TierMint\Discounts\DiscountApplication $autoComponent ): array {
		return array(
			'items'          => $items,
			'primary'        => $primary,
			'auto_meta'      => $autoMeta,
			'code_component' => $codeComponent,
			'auto_component' => $autoComponent,
		);
	}

	/**
	 * Get zero-filled, index-aligned reduction amounts.
	 *
	 * @since 1.0.0
	 *
	 * @param \DrPlugin\WP\TierMint\Discounts\DiscountApplication|null $application The application, or null.
	 * @param int                                                      $count       The number of lines.
	 * @return array<int, int> The per-line amounts, index-aligned with the lines.
	 */
	private function perLineAmounts( ?\DrPlugin\WP\TierMint\Discounts\DiscountApplication $application, int $count ): array {
		$amounts = array_fill( 0, max( 0, $count ), 0 );

		if ( null === $application ) {
			return $amounts;
		}

		for ( $i = 0; $i < $count; $i++ ) {
			$amounts[ $i ] = $application->amountForItem( $i );
		}

		return $amounts;
	}

	/**
	 * Get the notice for a saved code dropped at checkout.
	 *
	 * @since 1.0.0
	 *
	 * @param \DrPlugin\WP\TierMint\Discounts\DiscountValidationException $e The rejection.
	 * @return string The translated advisory.
	 */
	private function discountDroppedNotice( \DrPlugin\WP\TierMint\Discounts\DiscountValidationException $e ): string {
		$errors = array_values( array_filter( $e->getErrors(), 'is_string' ) );

		return array() !== $errors
			? $errors[0]
			: __( 'The saved discount code is no longer valid and was not applied.', 'tiermint' );
	}

	/**
	 * Build matching code and automatic-discount payloads.
	 *
	 * @since 1.0.0
	 *
	 * @param \DrPlugin\WP\TierMint\Discounts\DiscountApplication|null $codeComponent The code's landed contribution.
	 * @param \DrPlugin\WP\TierMint\Discounts\DiscountApplication|null $autoComponent The automatic discount's contribution.
	 * @return array{discount: array<string, mixed>|null, auto_discount: array<string, mixed>|null}
	 */
	private function discountPayload( ?\DrPlugin\WP\TierMint\Discounts\DiscountApplication $codeComponent, ?\DrPlugin\WP\TierMint\Discounts\DiscountApplication $autoComponent ): array {
		$discount = null !== $codeComponent
			? array(
				'valid'  => true,
				'code'   => $codeComponent->discount()->code(),
				'label'  => $this->discountLabel( $codeComponent->discount() ),
				'amount' => $codeComponent->total(),
			)
			: null;

		$autoDiscount = null !== $autoComponent
			? array(
				'label'  => $this->discountLabel( $autoComponent->discount() ),
				'amount' => $autoComponent->total(),
			)
			: null;

		return array(
			'discount'      => $discount,
			'auto_discount' => $autoDiscount,
		);
	}

	/**
	 * Build a buyer-facing discount label.
	 *
	 * @since 1.0.0
	 *
	 * @param \DrPlugin\WP\TierMint\Discounts\Discount $discount The discount to label.
	 * @return string The translated label.
	 */
	private function discountLabel( \DrPlugin\WP\TierMint\Discounts\Discount $discount ): string {
		$name = $discount->name();

		if ( null !== $name && '' !== $name ) {
			return $name;
		}

		if ( $discount->isAuto() ) {
			return __( 'Automatic discount', 'tiermint' );
		}

		return sprintf(
			/* translators: %s: the discount code the buyer applied. */
			__( 'Discount (%s)', 'tiermint' ),
			$discount->code()
		);
	}

	/**
	 * Apply an application allocation to order lines.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, OrderItem>                               $items       The undiscounted items, index-aligned
	 *                                                                         with the application's allocations.
	 * @param \DrPlugin\WP\TierMint\Discounts\DiscountApplication $application The computed application.
	 * @return array<int, OrderItem> The discounted items.
	 */
	private function allocateDiscount( array $items, \DrPlugin\WP\TierMint\Discounts\DiscountApplication $application ): array {
		return $this->applyLineDiscounts( $items, $this->perLineAmounts( $application, count( $items ) ) );
	}

	/**
	 * Apply per-line discount amounts to order lines.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, OrderItem> $items    The undiscounted items.
	 * @param array<int, int>       $perLine  The per-line reduction, index-aligned with the items.
	 * @return array<int, OrderItem> The discounted items.
	 */
	private function applyLineDiscounts( array $items, array $perLine ): array {
		$discounted = array();

		foreach ( array_values( $items ) as $index => $item ) {
			$amount = (int) ( $perLine[ $index ] ?? 0 );

			$discounted[] = new OrderItem(
				null,
				null,
				$item->productId(),
				$item->priceId(),
				$item->productName(),
				$item->priceName(),
				$item->quantity(),
				$item->subtotal(),
				$amount,
				0,
				$item->subtotal() - $amount
			);
		}

		return $discounted;
	}

	/**
	 * Apply extension-provided tax to discounted order lines.
	 *
	 * Invalid tax values become zero.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, OrderItem> $items   The priced, discount-allocated line items.
	 * @param array<string, mixed>  $context The request context (ip_address, country, discount_code,
	 *                                        plus anything an extension added at the REST boundary).
	 * @return array<int, OrderItem> The same lines, each carrying its (possibly zero) tax.
	 */
	private function applyTaxes( array $items, array $context ): array {
		$items = array_values( $items );

		/**
		 * Filters per-line checkout tax in minor units.
		 *
		 * Items already include discounts. Invalid values become zero.
		 *
		 * Net mode: each value is minor units added on top. Gross mode (marked by
		 * `$context['prices_include_tax'] === true`): each value is an int or an
		 * `array{contained: int, charged?: int}` pair. `contained` is the tax inside
		 * the line's gross-after-discount amount; `charged` is the tax taken
		 * (`contained` for a consumer, 0 for a reverse charge). A bare int means
		 * `contained = charged`. Core caps `contained` at gross-after and `charged`
		 * at `contained`. Do not do int arithmetic on a pair.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, int|array{contained: int, charged?: int}> $taxes Per-line
		 *        tax in minor units, all zeros by default.
		 * @param array<int, OrderItem> $items   The priced, discount-allocated line items.
		 * @param array<string, mixed>  $context The request context (ip_address, country,
		 *        discount_code, `prices_include_tax` in gross mode, plus any keys an
		 *        extension added through `tiermint_checkout_intent_context`).
		 */
		$taxes = apply_filters( 'tiermint_checkout_calculate_totals', array_fill( 0, count( $items ), 0 ), $items, $context );

		if ( ! is_array( $taxes ) ) {
			return $items;
		}

		$inclusive = true === ( $context['prices_include_tax'] ?? false );
		$taxed     = array();

		foreach ( $items as $index => $item ) {
			$value = $taxes[ $index ] ?? 0;

			if ( $inclusive ) {
				$taxed[] = $this->applyInclusiveTax( $item, $value );
				continue;
			}

			// Accept numeric values only, then convert them to whole minor units.
			$taxed[] = $item->withTax( is_numeric( $value ) ? max( 0, (int) $value ) : 0 );
		}

		return $taxed;
	}

	/**
	 * Rebase one line to net from a gross-mode filter value.
	 *
	 * @since 1.0.0
	 *
	 * @param OrderItem $item  The priced, discount-allocated gross line.
	 * @param mixed     $value The filter value: an int, or a `{contained, charged?}` pair.
	 * @return OrderItem The line rebased to net.
	 */
	private function applyInclusiveTax( OrderItem $item, $value ): OrderItem {
		$grossAfter = $item->subtotal() - $item->discount();

		if ( is_array( $value ) ) {
			$contained = is_numeric( $value['contained'] ?? null ) ? (int) $value['contained'] : 0;
			// No `charged` means charge the contained tax.
			$charged = array_key_exists( 'charged', $value ) && is_numeric( $value['charged'] )
				? (int) $value['charged']
				: $contained;
		} else {
			// A bare int is contained = charged.
			$contained = is_numeric( $value ) ? (int) $value : 0;
			$charged   = $contained;
		}

		// Clamp: negatives to zero, contained to gross-after, charged to contained.
		$contained = max( 0, min( $contained, $grossAfter ) );
		$charged   = max( 0, min( $charged, $contained ) );

		return $item->withInclusiveTax( $contained, $charged );
	}

	/**
	 * Mark the context with the applied pricing mode.
	 *
	 * A caller-supplied marker is overwritten: the filter decides gross mode, not
	 * a request param.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $context The checkout context.
	 * @return array<string, mixed> The context, marked when gross mode is on.
	 */
	private function markPricingMode( array $context ): array {
		unset( $context['prices_include_tax'] );

		if ( PricingMode::includesTax() ) {
			$context['prices_include_tax'] = true;
		}

		return $context;
	}

	/**
	 * Sum one money field across the line items.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, OrderItem> $items The line items.
	 * @param string                $field One of subtotal, discount, tax, total.
	 * @return int The summed minor units.
	 */
	private function sumField( array $items, string $field ): int {
		$total = 0;

		foreach ( $items as $item ) {
			$total += match ( $field ) {
				'subtotal' => $item->subtotal(),
				'discount' => $item->discount(),
				'tax'      => $item->tax(),
				'total'    => $item->total(),
				default    => 0,
			};
		}

		return $total;
	}

	/**
	 * The gross discount the buyer sees, from the code and automatic amounts.
	 *
	 * This is the REST `discount_total` in both modes, not the order row's net share.
	 *
	 * @since 1.0.0
	 *
	 * @param array{discount: array<string, mixed>|null, auto_discount: array<string, mixed>|null} $payload The discount payload.
	 * @return int The displayed reduction in minor units.
	 */
	private function displayedDiscountTotal( array $payload ): int {
		$total = 0;

		$code = $payload['discount'] ?? null;

		if ( is_array( $code ) && true === ( $code['valid'] ?? false ) && is_numeric( $code['amount'] ?? null ) ) {
			$total += (int) $code['amount'];
		}

		$auto = $payload['auto_discount'] ?? null;

		if ( is_array( $auto ) && is_numeric( $auto['amount'] ?? null ) ) {
			$total += (int) $auto['amount'];
		}

		return $total;
	}

	/**
	 * Reduce order lines to the discount engine input shape.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, OrderItem> $items The line items.
	 * @return array<int, array<string, int>> product_id and subtotal per line, index-aligned.
	 */
	private function discountItems( array $items ): array {
		$lines = array();

		foreach ( array_values( $items ) as $item ) {
			$lines[] = array(
				'product_id' => $item->productId(),
				'subtotal'   => $item->subtotal(),
			);
		}

		return $lines;
	}

	/**
	 * Re-price cart lines from the live catalog into order items.
	 *
	 * Clamp stale quantities to current tier rules.
	 *
	 * @since 1.0.0
	 *
	 * @param Cart                    $cart         The cart to convert.
	 * @param array<int, string>|null $clampNotices Out parameter: receives one buyer-facing
	 *                                 advisory per line whose quantity the tier rule adjusted.
	 * @return array<int, OrderItem> The authoritative line items.
	 *
	 * @throws OrderValidationException When any line is no longer purchasable.
	 */
	private function buildItems( Cart $cart, ?array &$clampNotices = null ): array {
		$items        = array();
		$errors       = array();
		$clampNotices = array();

		// Do not sell a recurring tier when this installation cannot bill renewals.
		$recurringSupported = ProductService::recurringTiersSupported();

		foreach ( $cart->lines() as $line ) {
			$product = $this->products->get( $line->productId() );

			if ( null === $product || ProductStatus::Published !== $product->status() ) {
				/* translators: %d: the product id. */
				$errors[] = sprintf( __( 'Product %d is no longer available for purchase.', 'tiermint' ), $line->productId() );
				continue;
			}

			$price = $this->activePrice( $product, $line->priceId() );

			if ( null === $price ) {
				/* translators: %d: the product id. */
				$errors[] = sprintf( __( 'The selected price for product %d is no longer available.', 'tiermint' ), $line->productId() );
				continue;
			}

			// Reject recurring tiers when recurring billing is unavailable.
			if ( ! $price->isPurchasable( $recurringSupported ) ) {
				$errors[] = __( 'This subscription plan is temporarily unavailable.', 'tiermint' );
				continue;
			}

			// A tier rule can change after the item enters the cart.
			$requested = $line->quantity();
			$quantity  = $price->clampQuantity( $requested );

			if ( $quantity !== $requested ) {
				$clampNotices[] = self::quantityAdjustedNotice( $product->name(), $quantity );
			}

			$subtotal = $price->amount() * $quantity;

			$items[] = new OrderItem(
				null,
				null,
				$line->productId(),
				$line->priceId(),
				$product->name(),
				$price->name(),
				$quantity,
				$subtotal,
				0,
				0,
				$subtotal
			);
		}

		if ( array() !== $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured validation messages, surfaced as API errors, never echoed as HTML.
			throw OrderValidationException::withErrors( $errors );
		}

		return $items;
	}

	/**
	 * Get the shared notice for a clamped quantity.
	 *
	 * @since 1.0.0
	 *
	 * @param string $productName The product name.
	 * @param int    $quantity    The adjusted quantity.
	 * @return string The translated advisory.
	 */
	public static function quantityAdjustedNotice( string $productName, int $quantity ): string {
		return sprintf(
			/* translators: 1: the product name, 2: the adjusted quantity. */
			__( 'The quantity for %1$s was adjusted to %2$d.', 'tiermint' ),
			$productName,
			$quantity
		);
	}

	/**
	 * Find an active price tier by id.
	 *
	 * @since 1.0.0
	 *
	 * @param Product $product The product to search.
	 * @param int     $priceId The price tier id.
	 * @return ProductPrice|null The active tier, or null.
	 */
	private function activePrice( Product $product, int $priceId ): ?ProductPrice {
		foreach ( $product->prices() as $price ) {
			if ( $price->id() === $priceId && $price->isActive() ) {
				return $price;
			}
		}

		return null;
	}

	/**
	 * Get the configured store currency, defaulting to USD.
	 *
	 * @since 1.0.0
	 *
	 * @return string The three-letter uppercase currency code.
	 */
	private function storeCurrency(): string {
		$settings = get_option( 'tiermint_settings' );
		$currency = ( is_array( $settings ) && ! empty( $settings['currency'] ) ) ? (string) $settings['currency'] : 'USD';
		$currency = strtoupper( substr( $currency, 0, 3 ) );

		return 1 === preg_match( '/^[A-Z]{3}$/', $currency ) ? $currency : 'USD';
	}

	/**
	 * Read a trimmed non-empty string from the request context, or null.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $context The request context.
	 * @param string               $key     The context key to read.
	 * @return string|null The value, or null when absent or blank.
	 */
	private function contextValue( array $context, string $key ): ?string {
		if ( ! isset( $context[ $key ] ) ) {
			return null;
		}

		$value = trim( (string) $context[ $key ] );

		return '' === $value ? null : $value;
	}

	/**
	 * Reload an order after a write.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The order id.
	 * @return Order The reloaded order.
	 *
	 * @throws OrderNotFoundException When the order cannot be reloaded.
	 */
	private function requireOrder( int $id ): Order {
		$order = $this->orders->find( $id );

		if ( null === $order ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer/validation message, surfaced as a structured error, never echoed as HTML.
			throw OrderNotFoundException::forId( $id );
		}

		return $order;
	}
}
