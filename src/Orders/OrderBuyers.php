<?php
/**
 * Resolves the buyers of a page of orders in one batched read.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Orders;

use DrPlugin\WP\TierMint\Customers\Customer;
use DrPlugin\WP\TierMint\Customers\CustomerService;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Resolves all buyers for an order page in one query.
 * Callers use the order email when a customer is missing.
 *
 * @since 1.0.0
 */
final class OrderBuyers {

	/**
	 * The customers domain service the batched read runs through.
	 *
	 * @var CustomerService
	 */
	private CustomerService $customers;

	/**
	 * Wire the resolver over the customer service.
	 *
	 * @since 1.0.0
	 *
	 * @param CustomerService $customers The customers domain service.
	 */
	public function __construct( CustomerService $customers ) {
		$this->customers = $customers;
	}

	/**
	 * Resolve the buyers of a set of orders, keyed by customer id, in one batched read.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, Order> $orders The orders whose buyers to resolve.
	 * @return array<int, Customer> Customer id => customer (absent when the customer is gone).
	 */
	public function forOrders( array $orders ): array {
		return $this->customers->getMany(
			array_map( static fn ( Order $order ): int => (int) $order->customerId(), $orders )
		);
	}
}
