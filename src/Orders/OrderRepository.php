<?php
/**
 * Orders repository: the only Orders class that touches $wpdb.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Orders;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Database\Logger;
use DrPlugin\WP\TierMint\Database\Schema;

/**
 * Stores orders, line items and their id and UUID cache entries.
 *
 * Table names come from Schema and query values use $wpdb->prepare(). Not final for service tests.
 *
 * @since 1.0.0
 */
class OrderRepository {

	/**
	 * The object-cache group orders are cached under.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'tiermint_orders';

	/**
	 * The WordPress database abstraction.
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * Audit logger for cleanup faults.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Wire the repository with its database handle.
	 *
	 * @since 1.0.0
	 *
	 * @param \wpdb       $wpdb   The WordPress database abstraction.
	 * @param Logger|null $logger Audit logger; null builds one over the same handle.
	 */
	public function __construct( \wpdb $wpdb, ?Logger $logger = null ) {
		$this->wpdb   = $wpdb;
		$this->logger = $logger ?? new Logger( $wpdb );
	}

	/**
	 * Insert an order and its line items.
	 *
	 * Remove the order if an item write fails, so partial orders do not remain.
	 *
	 * @since 1.0.0
	 *
	 * @param Order $order The order to persist.
	 * @return int The new order id.
	 *
	 * @throws OrderPersistenceException When any insert (order or item) fails. An item
	 *                          failure is compensated first, leaving no orphan.
	 */
	public function create( Order $order ): int {
		$now = gmdate( 'Y-m-d H:i:s' );
		$row = $order->toRow();

		// Imported orders keep their source timestamps.
		if ( ! isset( $row['created_at'] ) ) {
			$row['created_at'] = $now;
		}

		$row['updated_at'] = $order->updatedAt() ?? $order->createdAt() ?? $now;

		$id = $this->insertInto( $this->ordersTable(), $row );

		try {
			$this->insertItems( $id, $order->items() );
		} catch ( OrderPersistenceException $e ) {
			// Remove the partial order before rethrowing the item write failure.
			$this->delete( $id );

			throw $e;
		}

		$this->forget( $id, $order->uuid() );

		return $id;
	}

	/**
	 * Load a fully-hydrated order by id, reading through the object cache.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The order id.
	 * @return Order|null The order, or null when it does not exist.
	 */
	public function find( int $id ): ?Order {
		$cached = wp_cache_get( $this->idKey( $id ), self::CACHE_GROUP );

		if ( $cached instanceof Order ) {
			return $cached;
		}

		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$row = $this->fetchRow( fn () => $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->ordersTable(), $id ) ) );

		if ( null === $row ) {
			return null;
		}

		$order = $this->hydrate( $row );
		$this->cache( $order );

		return $order;
	}

	/**
	 * Load a fully-hydrated order by its public uuid, reading through the object cache.
	 *
	 * @since 1.0.0
	 *
	 * @param string $uuid The order uuid.
	 * @return Order|null The order, or null when it does not exist.
	 */
	public function findByUuid( string $uuid ): ?Order {
		$cached = wp_cache_get( $this->uuidKey( $uuid ), self::CACHE_GROUP );

		if ( $cached instanceof Order ) {
			return $cached;
		}

		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$row = $this->fetchRow( fn () => $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE uuid = %s', $this->ordersTable(), $uuid ) ) );

		if ( null === $row ) {
			return null;
		}

		$order = $this->hydrate( $row );
		$this->cache( $order );

		return $order;
	}

	/**
	 * Load an order by its gateway transaction id.
	 *
	 * This webhook lookup is uncached. The oldest duplicate wins.
	 *
	 * @since 1.0.0
	 *
	 * @param string $gateway The gateway id the transaction belongs to, e.g. 'stripe'.
	 * @param string $txnId   The gateway-side transaction id.
	 * @return Order|null The order, or null when no order carries the id.
	 */
	public function findByGatewayTxnId( string $gateway, string $txnId ): ?Order {
		$wpdb = $this->wpdb;
		$row  = $this->fetchRow(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
			fn () => $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE gateway = %s AND gateway_txn_id = %s ORDER BY id ASC LIMIT 1', $this->ordersTable(), $gateway, $txnId ) )
		);

		if ( null === $row ) {
			return null;
		}

		$order = $this->hydrate( $row );
		$this->cache( $order );

		return $order;
	}

	/**
	 * Update an order row and stamp updated_at.
	 *
	 * Order items are immutable. Refunds use separate orders.
	 *
	 * @since 1.0.0
	 *
	 * @param Order $order The order to persist (must carry an id).
	 * @return void
	 *
	 * @throws OrderException When the order has no id to update. A failed write surfaces
	 *                          as the {@see OrderPersistenceException} raised by updateWhere().
	 */
	public function update( Order $order ): void {
		$id = $order->id();

		if ( null === $id ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new OrderException( __( 'Cannot update an order that has no id.', 'tiermint' ) );
		}

		$this->updateWhere(
			$this->ordersTable(),
			array_merge(
				$order->toRow(),
				array( 'updated_at' => gmdate( 'Y-m-d H:i:s' ) )
			),
			array( 'id' => $id )
		);

		$this->forget( $id, $order->uuid() );
	}

	/**
	 * Move an order status with a guarded SQL update.
	 *
	 * One racing webhook wins. Do not use this for a source set that includes the target.
	 *
	 * @since 1.0.0
	 *
	 * @param Order                   $order        The order to transition (read a moment ago;
	 *                                              its id and uuid drive the write and cache
	 *                                              invalidation, its stored status is not trusted).
	 * @param array<int, OrderStatus> $fromStatuses The statuses the move is legal from
	 *                                              (typically {@see OrderStateMachine::sourcesFor()}).
	 * @param OrderStatus             $to           The status to move to.
	 * @param string|null             $completedAt  A completion timestamp to stamp alongside the
	 *                                              status (for the move to completed), or null.
	 * @return bool True when this call made the transition (1 row changed); false when the
	 *              order was no longer in a source status (a concurrent writer got there first).
	 *
	 * @throws OrderException            When the order has no id.
	 * @throws OrderPersistenceException When the write faults at the database.
	 */
	public function transitionStatus( Order $order, array $fromStatuses, OrderStatus $to, ?string $completedAt = null ): bool {
		$id = $order->id();

		if ( null === $id ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new OrderException( __( 'Cannot transition an order that has no id.', 'tiermint' ) );
		}

		if ( array() === $fromStatuses ) {
			// No legal source means the move can never apply; nothing to write.
			return false;
		}

		$now     = gmdate( 'Y-m-d H:i:s' );
		$wpdb    = $this->wpdb;
		$sources = array_map( static fn ( OrderStatus $status ): string => $status->value, $fromStatuses );

		if ( null === $completedAt ) {
			$values = array_merge( array( $this->ordersTable(), $to->value, $now, $id ), $sources );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
			$affected = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET status = %s, updated_at = %s WHERE id = %d AND status IN (' .
					implode( ', ', array_fill( 0, count( $fromStatuses ), '%s' ) ) . ')',
					$values
				)
			);
		} else {
			$values = array_merge( array( $this->ordersTable(), $to->value, $now, $completedAt, $id ), $sources );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
			$affected = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET status = %s, updated_at = %s, completed_at = %s WHERE id = %d AND status IN (' .
					implode( ', ', array_fill( 0, count( $fromStatuses ), '%s' ) ) . ')',
					$values
				)
			);
		}

		// Invalidate both cache keys: the id and uuid copies are now stale whether or not
		// this call won, since a racing writer may have changed the row either way.
		$this->forget( $id, $order->uuid() );

		if ( false === $affected ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new OrderPersistenceException( 'Failed to transition an order status in ' . $this->ordersTable() . '.' );
		}

		return 1 === $affected;
	}

	/**
	 * Update only gateway fields.
	 *
	 * Avoids a stale full-row update reverting a webhook status change.
	 *
	 * @since 1.0.0
	 *
	 * @param Order       $order        The order to stamp (its id and uuid drive the write and
	 *                                  cache invalidation; its other fields are not written).
	 * @param string      $gateway      The gateway id, e.g. 'stripe'.
	 * @param string|null $gatewayTxnId The gateway-side transaction id, or null.
	 * @return void
	 *
	 * @throws OrderException            When the order has no id.
	 * @throws OrderPersistenceException When the write faults at the database.
	 */
	public function stampGateway( Order $order, string $gateway, ?string $gatewayTxnId ): void {
		$id = $order->id();

		if ( null === $id ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new OrderException( __( 'Cannot stamp an order that has no id.', 'tiermint' ) );
		}

		$wpdb = $this->wpdb;
		$now  = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$result = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET gateway = %s, gateway_txn_id = %s, updated_at = %s WHERE id = %d', $this->ordersTable(), $gateway, $gatewayTxnId, $now, $id ) );

		$this->forget( $id, $order->uuid() );

		if ( false === $result ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new OrderPersistenceException( 'Failed to stamp an order gateway in ' . $this->ordersTable() . '.' );
		}
	}

	/**
	 * Delete an order and its line items.
	 *
	 * Log database failures because cleanup can leave partial data.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The order id to delete.
	 * @return void
	 */
	public function delete( int $id ): void {
		$existing = $this->find( $id );

		$ordersDeleted = $this->deleteWhere( $this->ordersTable(), array( 'id' => $id ) );
		$itemsDeleted  = $this->deleteWhere( $this->itemsTable(), array( 'order_id' => $id ) );

		if ( false === $ordersDeleted || false === $itemsDeleted ) {
			$this->logger->log(
				'error',
				sprintf( 'Failed to delete order %d during compensating cleanup; a partial or orphan row may remain.', $id ),
				array(),
				'order',
				$id
			);
		}

		$this->forget( $id, null !== $existing ? $existing->uuid() : null );
	}

	/**
	 * Get the positive refunded total for an order.
	 *
	 * @since 1.0.0
	 *
	 * @param int $parentId The purchase order id refunds are chained to.
	 * @return int The sum of refunded amounts, as a positive integer.
	 */
	public function sumRefundedForOrder( int $parentId ): int {
		$wpdb = $this->wpdb;
		$sum  = $this->fetchVar(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
			fn () => $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE( SUM( ABS( total ) ), 0 ) FROM %i WHERE parent_id = %d AND type = %s', $this->ordersTable(), $parentId, OrderType::Refund->value ) )
		);

		return (int) $sum;
	}

	/**
	 * Check for an id-less refund of an exact amount.
	 *
	 * This prevents webhook reconciliation and an admin refund from double-booking it.
	 *
	 * @since 1.0.0
	 *
	 * @param int $parentId The purchase order id refunds are chained to.
	 * @param int $amount   The refund amount in minor units (positive).
	 * @return bool True when an id-less refund of exactly this amount is already recorded.
	 */
	public function hasUnattributedRefundOfAmount( int $parentId, int $amount ): bool {
		$wpdb  = $this->wpdb;
		$count = $this->fetchVar(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
			fn () => $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE parent_id = %d AND type = %s AND gateway_txn_id IS NULL AND ABS( total ) = %d',
					$this->ordersTable(),
					$parentId,
					OrderType::Refund->value,
					$amount
				)
			)
		);

		return (int) $count > 0;
	}

	/**
	 * Whether a customer has already used a given discount on a non-failed order.
	 *
	 * Pending orders count, so concurrent checkouts cannot redeem the code twice.
	 *
	 * @since 1.0.0
	 *
	 * @param int $customerId The customer to check.
	 * @param int $discountId The discount to check.
	 * @return bool True when a qualifying order already used the discount.
	 *
	 * @throws OrderPersistenceException When the read faults at the database.
	 */
	public function customerUsedDiscount( int $customerId, int $discountId ): bool {
		$wpdb             = $this->wpdb;
		$wpdb->last_error = '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE customer_id = %d AND discount_id = %d AND type = %s AND status NOT IN ('failed','abandoned')",
				$this->ordersTable(),
				$customerId,
				$discountId,
				OrderType::Purchase->value
			)
		);

		if ( '' !== $wpdb->last_error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new OrderPersistenceException( 'Failed to read customer discount usage from ' . $this->ordersTable() . '.' );
		}

		return (int) $count > 0;
	}

	/**
	 * Count a customer's orders.
	 *
	 * A database error must not look like no orders.
	 *
	 * @since 1.0.0
	 *
	 * @param int $customerId The customer to count orders for.
	 * @return int The number of orders belonging to the customer.
	 *
	 * @throws OrderPersistenceException When the read faults at the database.
	 */
	public function countForCustomer( int $customerId ): int {
		$wpdb             = $this->wpdb;
		$wpdb->last_error = '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE customer_id = %d', $this->ordersTable(), $customerId ) );

		if ( '' !== $wpdb->last_error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new OrderPersistenceException( 'Failed to count customer orders in ' . $this->ordersTable() . '.' );
		}

		return (int) $count;
	}

	/**
	 * Get a customer's order IDs, oldest first.
	 *
	 * Used by privacy export and erasure.
	 *
	 * @since 1.0.0
	 *
	 * @param int $customerId The customer id.
	 * @return array<int, int> The order ids, oldest first.
	 */
	public function idsForCustomer( int $customerId ): array {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$rows = $this->fetchRows( fn () => $wpdb->get_results( $wpdb->prepare( 'SELECT id FROM %i WHERE customer_id = %d ORDER BY id ASC', $this->ordersTable(), $customerId ) ) );

		return array_map( static fn ( object $r ): int => (int) $r->id, $rows );
	}

	/**
	 * Remove buyer data but keep the transaction record.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $orderId   The order id to anonymize.
	 * @param string $anonEmail The anonymized replacement email.
	 * @return void
	 *
	 * @throws OrderPersistenceException When the update fails.
	 */
	public function anonymize( int $orderId, string $anonEmail ): void {
		$existing = $this->find( $orderId );

		$this->updateWhere(
			$this->ordersTable(),
			array(
				'email'      => $anonEmail,
				'ip_address' => null,
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $orderId )
		);

		$this->forget( $orderId, null !== $existing ? $existing->uuid() : null );
	}

	/**
	 * Get stale pending purchases for the abandonment sweep.
	 *
	 * Ignore orders with a gateway transaction because payment can still complete.
	 *
	 * @since 1.0.0
	 *
	 * @param string $before A `Y-m-d H:i:s` UTC cutoff; orders created at/before it match.
	 * @param int    $limit  The maximum number of orders to return.
	 * @return array<int, Order> The matching, hydrated orders, oldest first.
	 */
	public function pendingForAbandonment( string $before, int $limit ): array {
		$wpdb = $this->wpdb;
		$rows = $this->fetchRows(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
			fn () => $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE status = %s AND type = %s AND gateway_txn_id IS NULL AND created_at <= %s' .
					' ORDER BY created_at ASC, id ASC LIMIT %d',
					$this->ordersTable(),
					OrderStatus::Pending->value,
					OrderType::Purchase->value,
					$before,
					max( 0, $limit )
				)
			)
		);

		return $this->hydratePage( $rows );
	}

	/**
	 * Query orders for a collection view.
	 *
	 * Filters, pagination and sort values are validated before the query.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $args The query args.
	 * @return array<int, Order> The matching, hydrated orders.
	 */
	public function query( array $args ): array {
		$orderby = $this->whitelistOrderby( $args['orderby'] ?? 'created_at' );
		$perPage = $this->perPage( $args );
		$offset  = ( max( 1, (int) ( $args['page'] ?? 1 ) ) - 1 ) * $perPage;
		$wpdb    = $this->wpdb;

		list( $status, $byCustomer, $customerId, $productId, $bySearch, $like ) = $this->filters( $args );

		// The WHERE is the one in countQuery(); keep the copies in step.
		if ( 'ASC' === $this->whitelistOrder( $args['order'] ?? 'DESC' ) ) {
			$rows = $this->fetchRows(
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
				fn () => $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM %i o WHERE ( %s = '' OR o.status = %s ) AND ( %d = 0 OR o.customer_id = %d )" .
						' AND ( %d = 0 OR EXISTS ( SELECT 1 FROM %i oi WHERE oi.order_id = o.id AND oi.product_id = %d ) )' .
						' AND ( %d = 0 OR o.email LIKE %s OR o.uuid LIKE %s )' .
						' ORDER BY %i ASC, o.id ASC LIMIT %d OFFSET %d',
						$this->ordersTable(),
						$status,
						$status,
						$byCustomer,
						$customerId,
						$productId,
						$this->itemsTable(),
						$productId,
						$bySearch,
						$like,
						$like,
						$orderby,
						$perPage,
						$offset
					)
				)
			);
		} else {
			$rows = $this->fetchRows(
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
				fn () => $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM %i o WHERE ( %s = '' OR o.status = %s ) AND ( %d = 0 OR o.customer_id = %d )" .
						' AND ( %d = 0 OR EXISTS ( SELECT 1 FROM %i oi WHERE oi.order_id = o.id AND oi.product_id = %d ) )' .
						' AND ( %d = 0 OR o.email LIKE %s OR o.uuid LIKE %s )' .
						' ORDER BY %i DESC, o.id DESC LIMIT %d OFFSET %d',
						$this->ordersTable(),
						$status,
						$status,
						$byCustomer,
						$customerId,
						$productId,
						$this->itemsTable(),
						$productId,
						$bySearch,
						$like,
						$like,
						$orderby,
						$perPage,
						$offset
					)
				)
			);
		}

		return $this->hydratePage( $rows );
	}

	/**
	 * Get a limited date range for CSV export, newest first.
	 *
	 * The caller uses `cap + 1` to reject oversized ranges.
	 *
	 * @since 1.0.0
	 *
	 * @param string $from  The inclusive start date, `Y-m-d` in UTC.
	 * @param string $to    The inclusive end date, `Y-m-d` in UTC.
	 * @param int    $limit The maximum number of orders to return.
	 * @return array<int, Order> The matching, hydrated orders, newest first.
	 */
	public function exportRange( string $from, string $to, int $limit ): array {
		$wpdb = $this->wpdb;
		$rows = $this->fetchRows(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
			fn () => $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE created_at >= %s AND created_at <= %s ORDER BY created_at DESC, id DESC LIMIT %d',
					$this->ordersTable(),
					$from . ' 00:00:00',
					$to . ' 23:59:59',
					max( 0, $limit )
				)
			)
		);

		return $this->hydratePage( $rows );
	}

	/**
	 * Count orders matching a collection query (for pagination).
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $args The query args (status, customer_id, product_id, search).
	 * @return int The total matching order count.
	 */
	public function countQuery( array $args ): int {
		$wpdb = $this->wpdb;

		list( $status, $byCustomer, $customerId, $productId, $bySearch, $like ) = $this->filters( $args );

		return (int) $this->fetchVar(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
			fn () => $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i o WHERE ( %s = '' OR o.status = %s ) AND ( %d = 0 OR o.customer_id = %d )" .
					' AND ( %d = 0 OR EXISTS ( SELECT 1 FROM %i oi WHERE oi.order_id = o.id AND oi.product_id = %d ) )' .
					' AND ( %d = 0 OR o.email LIKE %s OR o.uuid LIKE %s )',
					$this->ordersTable(),
					$status,
					$status,
					$byCustomer,
					$customerId,
					$productId,
					$this->itemsTable(),
					$productId,
					$bySearch,
					$like,
					$like
				)
			)
		);
	}

	/**
	 * Hydrate an order row into a full aggregate, loading its line items.
	 *
	 * @since 1.0.0
	 *
	 * @param object $row A `tiermint_orders` row.
	 * @return Order The hydrated order.
	 */
	private function hydrate( object $row ): Order {
		return Order::fromRow( $row, $this->loadItems( (int) $row->id ) );
	}

	/**
	 * Hydrate a page of order rows, reading every line item on the page in one query.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, object> $rows The `tiermint_orders` rows on this page.
	 * @return array<int, Order> The hydrated orders, in the order given.
	 */
	private function hydratePage( array $rows ): array {
		$grouped = $this->loadItemsFor( array_map( static fn ( object $row ): int => (int) $row->id, $rows ) );

		return array_map(
			static fn ( object $row ): Order => Order::fromRow( $row, $grouped[ (int) $row->id ] ?? array() ),
			$rows
		);
	}

	/**
	 * Load an order's line items, ordered by id for a stable presentation.
	 *
	 * @since 1.0.0
	 *
	 * @param int $orderId The owning order id.
	 * @return array<int, OrderItem> The ordered line items.
	 */
	private function loadItems( int $orderId ): array {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$rows = $this->fetchRows( fn () => $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d ORDER BY id ASC', $this->itemsTable(), $orderId ) ) );

		return array_map( static fn ( object $r ): OrderItem => OrderItem::fromRow( $r ), $rows );
	}

	/**
	 * Load items for several orders in one query, grouped by order ID.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, int> $orderIds The owning order ids.
	 * @return array<int, array<int, OrderItem>> Order id => its ordered line items.
	 */
	private function loadItemsFor( array $orderIds ): array {
		$unique = array_values( array_unique( array_filter( array_map( 'intval', $orderIds ), static fn ( int $id ): bool => $id > 0 ) ) );

		if ( array() === $unique ) {
			return array();
		}

		$wpdb   = $this->wpdb;
		$values = array_merge( array( $this->itemsTable() ), $unique );
		$rows   = $this->fetchRows(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
			fn () => $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE order_id IN (' . implode( ',', array_fill( 0, count( $unique ), '%d' ) ) . ') ORDER BY order_id ASC, id ASC',
					$values
				)
			)
		);

		$grouped = array();

		foreach ( $rows as $row ) {
			$grouped[ (int) $row->order_id ][] = OrderItem::fromRow( $row );
		}

		return $grouped;
	}

	/**
	 * Insert an order's line items, stamping the owning order id.
	 *
	 * @since 1.0.0
	 *
	 * @param int                   $orderId The owning order id.
	 * @param array<int, OrderItem> $items   The line items to insert.
	 * @return void
	 *
	 * @throws OrderPersistenceException When an item insert fails.
	 */
	private function insertItems( int $orderId, array $items ): void {
		foreach ( $items as $item ) {
			$data             = $item->toRow();
			$data['order_id'] = $orderId;

			$this->insertInto( $this->itemsTable(), $data );
		}
	}

	/**
	 * Read the collection filters for the fixed WHERE in query() and countQuery().
	 *
	 * An empty status, a 0 product id or a 0 customer or search flag turns that filter
	 * off. The customer filter has its own flag because any given value, 0 included,
	 * filters; the search has one because MySQL collations compare a zero-width space
	 * equal to ''. MySQL drops a clause that is off before it plans the query.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $args The query args.
	 * @return array{0: string, 1: int, 2: int, 3: int, 4: int, 5: string} Status, customer
	 *         flag, customer id, product id, search flag and the LIKE pattern.
	 */
	private function filters( array $args ): array {
		$status = isset( $args['status'] ) ? (string) $args['status'] : '';
		$search = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';

		// The product filter is an EXISTS rather than a join: an order holding the product
		// on two lines must still be one row on the page, and the correlated lookup is
		// served by the order_items `product_id` key.
		return array(
			null !== OrderStatus::tryFrom( $status ) ? $status : '',
			isset( $args['customer_id'] ) && '' !== (string) $args['customer_id'] ? 1 : 0,
			isset( $args['customer_id'] ) ? (int) $args['customer_id'] : 0,
			isset( $args['product_id'] ) ? max( 0, (int) $args['product_id'] ) : 0,
			'' === $search ? 0 : 1,
			'%' . $this->wpdb->esc_like( $search ) . '%',
		);
	}

	/**
	 * Whitelist the orderby column, defaulting to created_at.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $orderby The requested orderby.
	 * @return string A safe column name.
	 */
	private function whitelistOrderby( $orderby ): string {
		$allowed = array( 'id', 'created_at', 'total' );

		return in_array( (string) $orderby, $allowed, true ) ? (string) $orderby : 'created_at';
	}

	/**
	 * Whitelist the sort direction, defaulting to DESC.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $order The requested direction.
	 * @return string 'ASC' or 'DESC'.
	 */
	private function whitelistOrder( $order ): string {
		return 'ASC' === strtoupper( (string) $order ) ? 'ASC' : 'DESC';
	}

	/**
	 * The per-page size, defaulting to 10 (matching the REST collection schema) and
	 * capped at 100.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $args The query args.
	 * @return int The clamped per-page size.
	 */
	private function perPage( array $args ): int {
		$perPage = (int) ( $args['per_page'] ?? 10 );

		if ( $perPage < 1 ) {
			$perPage = 10;
		}

		return min( $perPage, 100 );
	}

	/**
	 * Cache an order under both its lookup keys (id and uuid).
	 *
	 * @since 1.0.0
	 *
	 * @param Order $order The order to cache.
	 * @return void
	 */
	private function cache( Order $order ): void {
		$id = $order->id();

		if ( null !== $id ) {
			wp_cache_set( $this->idKey( $id ), $order, self::CACHE_GROUP );
		}

		wp_cache_set( $this->uuidKey( $order->uuid() ), $order, self::CACHE_GROUP );
	}

	/**
	 * Invalidate the cached id and uuid entries for an order.
	 *
	 * @since 1.0.0
	 *
	 * @param int         $id   The order id.
	 * @param string|null $uuid The order uuid (null to skip the uuid key).
	 * @return void
	 */
	private function forget( int $id, ?string $uuid ): void {
		wp_cache_delete( $this->idKey( $id ), self::CACHE_GROUP );

		if ( null !== $uuid && '' !== $uuid ) {
			wp_cache_delete( $this->uuidKey( $uuid ), self::CACHE_GROUP );
		}
	}

	/**
	 * The cache key for an order id.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The order id.
	 * @return string The cache key.
	 */
	private function idKey( int $id ): string {
		return 'order_' . $id;
	}

	/**
	 * The cache key for an order uuid.
	 *
	 * @since 1.0.0
	 *
	 * @param string $uuid The order uuid.
	 * @return string The cache key.
	 */
	private function uuidKey( string $uuid ): string {
		return 'uuid_' . $uuid;
	}

	/**
	 * The fully-qualified `tiermint_orders` table name.
	 *
	 * @since 1.0.0
	 *
	 * @return string The table name.
	 */
	private function ordersTable(): string {
		return Schema::tableName( $this->wpdb, 'orders' );
	}

	/**
	 * The fully-qualified `tiermint_order_items` table name.
	 *
	 * @since 1.0.0
	 *
	 * @return string The table name.
	 */
	private function itemsTable(): string {
		return Schema::tableName( $this->wpdb, 'order_items' );
	}

	/**
	 * Insert a row into a Schema-defined table and return the new auto-increment id.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $table The fully-qualified table name.
	 * @param array<string, mixed> $data  Column => value pairs.
	 * @return int The inserted row id.
	 *
	 * @throws OrderPersistenceException When the insert fails (e.g. a unique-key clash on
	 *                          the order uuid), so a failed order insert never lets item
	 *                          rows attach to a wrong or zeroed id. The controller reports
	 *                          this as a generic 500, never leaking the table name.
	 */
	private function insertInto( string $table, array $data ): int {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table; this class decides what to cache.
		$inserted = $wpdb->insert( $table, $data );

		if ( false === $inserted ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new OrderPersistenceException( 'Failed to insert a row into ' . $table . '.' );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update rows in a Schema-defined table.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $table The fully-qualified table name.
	 * @param array<string, mixed> $data  Column => value pairs to set.
	 * @param array<string, mixed> $where Column => value equality conditions.
	 * @return void
	 *
	 * @throws OrderPersistenceException When the update fails.
	 */
	private function updateWhere( string $table, array $data, array $where ): void {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$updated = $wpdb->update( $table, $data, $where );

		if ( false === $updated ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new OrderPersistenceException( 'Failed to update a row in ' . $table . '.' );
		}
	}

	/**
	 * Delete rows from a Schema-defined table.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $table The fully-qualified table name.
	 * @param array<string, mixed> $where Column => value equality conditions.
	 * @return int|false The number of rows deleted, or false on a database error.
	 */
	private function deleteWhere( string $table, array $where ) {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		return $wpdb->delete( $table, $where );
	}

	/**
	 * Fetch a single row.
	 *
	 * @since 1.0.0
	 *
	 * @param callable(): mixed $query Runs the prepared query.
	 * @return object|null The row object, or null.
	 */
	private function fetchRow( callable $query ): ?object {
		return $query();
	}

	/**
	 * Fetch a result set.
	 *
	 * @since 1.0.0
	 *
	 * @param callable(): mixed $query Runs the prepared query.
	 * @return array<int, object> The rows (empty on no match).
	 */
	private function fetchRows( callable $query ): array {
		$rows = $query();

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Fetch a single scalar.
	 *
	 * @since 1.0.0
	 *
	 * @param callable(): mixed $query Runs the prepared query.
	 * @return string|null The scalar value, or null.
	 */
	private function fetchVar( callable $query ): ?string {
		$value = $query();

		return null === $value ? null : (string) $value;
	}
}
