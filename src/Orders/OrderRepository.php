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
	 * Wire the repository with its database handle.
	 *
	 * @since 1.0.0
	 *
	 * @param \wpdb $wpdb The WordPress database abstraction.
	 */
	public function __construct( \wpdb $wpdb ) {
		$this->wpdb = $wpdb;
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

		$row = $this->fetchRow(
			'SELECT * FROM ' . $this->ordersTable() . ' WHERE id = %d',
			array( $id )
		);

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

		$row = $this->fetchRow(
			'SELECT * FROM ' . $this->ordersTable() . ' WHERE uuid = %s',
			array( $uuid )
		);

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
		$row = $this->fetchRow(
			'SELECT * FROM ' . $this->ordersTable() . ' WHERE gateway = %s AND gateway_txn_id = %s ORDER BY id ASC LIMIT 1',
			array( $gateway, $txnId )
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
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer message; never echoed as HTML.
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
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer message; never echoed as HTML.
			throw new OrderException( __( 'Cannot transition an order that has no id.', 'tiermint' ) );
		}

		if ( array() === $fromStatuses ) {
			// No legal source means the move can never apply; nothing to write.
			return false;
		}

		$now = gmdate( 'Y-m-d H:i:s' );

		$set    = 'status = %s, updated_at = %s';
		$params = array( $to->value, $now );

		if ( null !== $completedAt ) {
			$set     .= ', completed_at = %s';
			$params[] = $completedAt;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $fromStatuses ), '%s' ) );

		$params[] = $id;
		foreach ( $fromStatuses as $status ) {
			$params[] = $status->value;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The SQL embeds only Schema-derived identifiers and %s placeholders; every runtime value is bound via prepare() below.
		$sql = 'UPDATE ' . $this->ordersTable() . ' SET ' . $set . ' WHERE id = %d AND status IN (' . $placeholders . ')';

		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Values bound via prepare(); the caller's write invalidates the object cache just below.
		$affected = $wpdb->query( $wpdb->prepare( $sql, $params ) );

		// Invalidate both cache keys: the id and uuid copies are now stale whether or not
		// this call won, since a racing writer may have changed the row either way.
		$this->forget( $id, $order->uuid() );

		if ( false === $affected ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $table is a Schema-derived internal identifier, never user input or HTML; kept out of the client response by the controller.
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
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer message; never echoed as HTML.
			throw new OrderException( __( 'Cannot stamp an order that has no id.', 'tiermint' ) );
		}

		$wpdb = $this->wpdb;
		$now  = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The SQL embeds only the Schema-derived table name; every runtime value is bound via prepare() below.
		$sql = 'UPDATE ' . $this->ordersTable() . ' SET gateway = %s, gateway_txn_id = %s, updated_at = %s WHERE id = %d';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Values bound via prepare(); the cache is invalidated just below.
		$result = $wpdb->query( $wpdb->prepare( $sql, $gateway, $gatewayTxnId, $now, $id ) );

		$this->forget( $id, $order->uuid() );

		if ( false === $result ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $table is a Schema-derived internal identifier, never user input or HTML; kept out of the client response by the controller.
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
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- A compensating delete faulted; there is no return channel to the caller (this is cleanup on an already-failing path), so record it so an orphan/partial row does not go unnoticed.
			error_log( sprintf( 'TierMint: failed to delete order %d during compensating cleanup; a partial or orphan row may remain.', $id ) );
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
		$sum = $this->fetchVar(
			'SELECT COALESCE( SUM( ABS( total ) ), 0 ) FROM ' . $this->ordersTable()
				. ' WHERE parent_id = %d AND type = %s',
			array( $parentId, OrderType::Refund->value )
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
		$count = $this->fetchVar(
			'SELECT COUNT(*) FROM ' . $this->ordersTable()
				. ' WHERE parent_id = %d AND type = %s AND gateway_txn_id IS NULL AND ABS( total ) = %d',
			array( $parentId, OrderType::Refund->value, $amount )
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

		$sql = 'SELECT COUNT(*) FROM ' . $this->ordersTable()
			. ' WHERE customer_id = %d AND discount_id = %d AND type = %s'
			. " AND status NOT IN ('failed','abandoned')";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Values bound via prepare(); the two excluded statuses are fixed literals, not input; a once-per-customer probe has nothing to cache.
		$count = $wpdb->get_var( $wpdb->prepare( $sql, $customerId, $discountId, OrderType::Purchase->value ) );

		if ( '' !== $wpdb->last_error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $table is a Schema-derived internal identifier, never user input or HTML; kept out of the client response by the controller.
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

		$sql = 'SELECT COUNT(*) FROM ' . $this->ordersTable() . ' WHERE customer_id = %d';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Value bound via prepare(); the SQL embeds only the Schema-derived table name; a one-off admin count has nothing to cache.
		$count = $wpdb->get_var( $wpdb->prepare( $sql, $customerId ) );

		if ( '' !== $wpdb->last_error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The table name is a Schema-derived internal identifier, never user input or HTML; kept out of the client response by the controller.
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
		$rows = $this->fetchRows(
			'SELECT id FROM ' . $this->ordersTable() . ' WHERE customer_id = %d ORDER BY id ASC',
			array( $customerId )
		);

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
		$rows = $this->fetchRows(
			'SELECT * FROM ' . $this->ordersTable()
				. ' WHERE status = %s AND type = %s AND gateway_txn_id IS NULL AND created_at <= %s'
				. ' ORDER BY created_at ASC, id ASC LIMIT %d',
			array( OrderStatus::Pending->value, OrderType::Purchase->value, $before, max( 0, $limit ) )
		);

		return $this->hydratePage( $rows );
	}

	/**
	 * Get pending purchases due for a recovery email.
	 *
	 * Filter sent and stale orders in SQL so they do not block fresh orders.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $before      A `Y-m-d H:i:s` UTC cutoff; orders created at/before it match.
	 * @param string      $sentMetaKey The order-meta key marking an order already emailed.
	 * @param int         $limit       The maximum number of orders to return.
	 * @param string|null $after       An optional `Y-m-d H:i:s` UTC lower bound; orders created before
	 *                                 it are excluded (too stale to recover). Null applies no lower bound.
	 * @return array<int, Order> The matching, hydrated orders, oldest first.
	 */
	public function pendingAwaitingRecoveryEmail( string $before, string $sentMetaKey, int $limit, ?string $after = null ): array {
		// The `email <> ''` guard is defensive: an order always carries a validated buyer email
		// today, but a recovery email must never be addressed to an empty recipient.
		$sql = 'SELECT o.* FROM ' . $this->ordersTable() . ' o'
			. ' WHERE o.status = %s AND o.type = %s AND o.email <> %s AND o.created_at <= %s';

		$params = array( OrderStatus::Pending->value, OrderType::Purchase->value, '', $before );

		if ( null !== $after ) {
			$sql     .= ' AND o.created_at >= %s';
			$params[] = $after;
		}

		$sql     .= ' AND NOT EXISTS ( SELECT 1 FROM ' . $this->metaTable()
			. ' m WHERE m.tiermint_order_id = o.id AND m.meta_key = %s )'
			. ' ORDER BY o.created_at ASC, o.id ASC LIMIT %d';
		$params[] = $sentMetaKey;
		$params[] = max( 0, $limit );

		return $this->hydratePage( $this->fetchRows( $sql, $params ) );
	}

	/**
	 * Get orders due for a cart-recovery step.
	 *
	 * Pending and abandoned orders remain eligible until the drip ends. The first step skips
	 * orders emailed by the former single-email flow.
	 *
	 * @since 1.0.0
	 *
	 * @param string             $before           A `Y-m-d H:i:s` UTC cutoff; orders created at/before it match.
	 * @param string|null        $after            An optional `Y-m-d H:i:s` UTC lower bound; older orders are excluded.
	 * @param int                $stepsSent        The recovery `steps_sent` count an order must be at for this step.
	 * @param array<int, string> $terminalStatuses Recovery statuses that make a row terminal (excluded).
	 * @param string             $legacyMetaKey    The single-email-era sent marker, excluded on the first step only.
	 * @param int                $limit            The maximum number of orders to return (batch cap).
	 * @return array<int, Order> The matching, hydrated orders, oldest first.
	 */
	public function purchaseOrdersDueForRecovery( string $before, ?string $after, int $stepsSent, array $terminalStatuses, string $legacyMetaKey, int $limit ): array {
		$sql = 'SELECT o.* FROM ' . $this->ordersTable() . ' o'
			. ' LEFT JOIN ' . $this->recoveriesTable() . ' r ON r.order_id = o.id'
			. ' WHERE o.status IN ( %s, %s ) AND o.type = %s AND o.email <> %s AND o.created_at <= %s';

		$params = array(
			OrderStatus::Pending->value,
			OrderStatus::Abandoned->value,
			OrderType::Purchase->value,
			'',
			$before,
		);

		if ( null !== $after ) {
			$sql     .= ' AND o.created_at >= %s';
			$params[] = $after;
		}

		if ( array() !== $terminalStatuses ) {
			$placeholders = implode( ', ', array_fill( 0, count( $terminalStatuses ), '%s' ) );
			$sql         .= ' AND ( r.id IS NULL OR r.status NOT IN ( ' . $placeholders . ' ) )';
			foreach ( $terminalStatuses as $status ) {
				$params[] = (string) $status;
			}
		}

		if ( 0 === $stepsSent ) {
			// The first step: the row is absent or still at zero, and the order was never
			// emailed under the old single-email system (the meta guard).
			$sql     .= ' AND ( r.id IS NULL OR r.steps_sent = 0 )'
				. ' AND NOT EXISTS ( SELECT 1 FROM ' . $this->metaTable()
				. ' m WHERE m.tiermint_order_id = o.id AND m.meta_key = %s )';
			$params[] = $legacyMetaKey;
		} else {
			// A later step: the previous steps have been sent, so the row is present at the
			// expected count.
			$sql     .= ' AND r.steps_sent = %d';
			$params[] = $stepsSent;
		}

		$sql     .= ' ORDER BY o.created_at ASC, o.id ASC LIMIT %d';
		$params[] = max( 0, $limit );

		return $this->hydratePage( $this->fetchRows( $sql, $params ) );
	}

	/**
	 * Whether an email has a recovery marker after the cutoff.
	 *
	 * @since 1.0.0
	 *
	 * @param string $email   The buyer email to match.
	 * @param string $metaKey The order-meta key to look for.
	 * @param string $since   A `Y-m-d H:i:s` UTC cutoff the marker value must be at/after.
	 * @return bool True when such an order exists.
	 */
	public function emailHasOrderWithMetaSince( string $email, string $metaKey, string $since ): bool {
		$found = $this->fetchVar(
			'SELECT 1 FROM ' . $this->ordersTable() . ' o'
				. ' INNER JOIN ' . $this->metaTable() . ' m ON m.tiermint_order_id = o.id'
				. ' WHERE o.email = %s AND o.type = %s AND m.meta_key = %s AND m.meta_value >= %s'
				. ' LIMIT 1',
			array( $email, OrderType::Purchase->value, $metaKey, $since )
		);

		return null !== $found;
	}

	/**
	 * Whether a customer bought a product.
	 *
	 * Fully refunded orders, renewals and upgrades do not count.
	 *
	 * @since 1.0.0
	 *
	 * @param int $customerId The customer to check.
	 * @param int $productId  The product to check.
	 * @return bool True when a qualifying purchase exists.
	 */
	public function hasPurchasedProduct( int $customerId, int $productId ): bool {
		if ( $customerId <= 0 || $productId <= 0 ) {
			return false;
		}

		$found = $this->fetchVar(
			'SELECT 1 FROM ' . $this->ordersTable() . ' o'
				. ' INNER JOIN ' . $this->itemsTable() . ' oi ON oi.order_id = o.id'
				. ' WHERE o.customer_id = %d AND oi.product_id = %d'
				. ' AND o.type = %s AND o.status IN ( %s, %s )'
				. ' LIMIT 1',
			array(
				$customerId,
				$productId,
				OrderType::Purchase->value,
				OrderStatus::Completed->value,
				OrderStatus::PartiallyRefunded->value,
			)
		);

		return null !== $found;
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
		list( $where, $params ) = $this->buildFilters( $args );

		$orderby = $this->whitelistOrderby( $args['orderby'] ?? 'created_at' );
		$order   = $this->whitelistOrder( $args['order'] ?? 'DESC' );
		$perPage = $this->perPage( $args );
		$page    = max( 1, (int) ( $args['page'] ?? 1 ) );

		$sql = 'SELECT * FROM ' . $this->ordersTable() . $where
			. ' ORDER BY ' . $orderby . ' ' . $order . ', id ' . $order
			. ' LIMIT %d OFFSET %d';

		$params[] = $perPage;
		$params[] = ( $page - 1 ) * $perPage;

		return $this->hydratePage( $this->fetchRows( $sql, $params ) );
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
		$rows = $this->fetchRows(
			'SELECT * FROM ' . $this->ordersTable()
				. ' WHERE created_at >= %s AND created_at <= %s'
				. ' ORDER BY created_at DESC, id DESC LIMIT %d',
			array( $from . ' 00:00:00', $to . ' 23:59:59', max( 0, $limit ) )
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
		list( $where, $params ) = $this->buildFilters( $args );

		$sql = 'SELECT COUNT(*) FROM ' . $this->ordersTable() . $where;

		return (int) $this->fetchVar( $sql, $params );
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
		$rows = $this->fetchRows(
			'SELECT * FROM ' . $this->itemsTable() . ' WHERE order_id = %d ORDER BY id ASC',
			array( $orderId )
		);

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

		$rows = $this->fetchRows(
			'SELECT * FROM ' . $this->itemsTable()
				. ' WHERE order_id IN (' . implode( ',', array_fill( 0, count( $unique ), '%d' ) ) . ')'
				. ' ORDER BY order_id ASC, id ASC',
			$unique
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
	 * Build the WHERE clause and bound params for the collection query.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $args The query args.
	 * @return array{0: string, 1: array<int, mixed>} The clause (with leading space) and params.
	 */
	private function buildFilters( array $args ): array {
		$clauses = array();
		$params  = array();

		if ( isset( $args['status'] ) && '' !== (string) $args['status'] ) {
			$status = (string) $args['status'];

			if ( null !== OrderStatus::tryFrom( $status ) ) {
				$clauses[] = 'status = %s';
				$params[]  = $status;
			}
		}

		if ( isset( $args['customer_id'] ) && '' !== (string) $args['customer_id'] ) {
			$clauses[] = 'customer_id = %d';
			$params[]  = (int) $args['customer_id'];
		}

		if ( isset( $args['product_id'] ) && (int) $args['product_id'] > 0 ) {
			// EXISTS rather than a join: an order holding the product on two lines must
			// still be one row on the page, and the correlated lookup is served by the
			// order_items `product_id` key.
			$clauses[] = 'EXISTS ( SELECT 1 FROM ' . $this->itemsTable() . ' oi'
				. ' WHERE oi.order_id = ' . $this->ordersTable() . '.id AND oi.product_id = %d )';
			$params[]  = (int) $args['product_id'];
		}

		if ( isset( $args['search'] ) && '' !== trim( (string) $args['search'] ) ) {
			$like      = '%' . $this->wpdb->esc_like( trim( (string) $args['search'] ) ) . '%';
			$clauses[] = '(email LIKE %s OR uuid LIKE %s)';
			$params[]  = $like;
			$params[]  = $like;
		}

		$where = array() === $clauses ? '' : ' WHERE ' . implode( ' AND ', $clauses );

		return array( $where, $params );
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
	 * Get the order-meta table name for recovery and attribution reads.
	 *
	 * @since 1.0.0
	 *
	 * @return string The table name.
	 */
	private function metaTable(): string {
		return Schema::tableName( $this->wpdb, 'order_meta' );
	}

	/**
	 * The fully-qualified `tiermint_recoveries` table name. Joined by the multi-step
	 * cart-recovery eligibility read; the table is core-created and Pro-written.
	 *
	 * @since 1.0.0
	 *
	 * @return string The table name.
	 */
	private function recoveriesTable(): string {
		return Schema::tableName( $this->wpdb, 'recoveries' );
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Insert into a Schema-defined table; $wpdb->insert escapes every value; there is nothing to cache on a write.
		$inserted = $wpdb->insert( $table, $data );

		if ( false === $inserted ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $table is a Schema-derived internal identifier, never user input or HTML output; this is a developer-facing failure kept out of the client response by the controller.
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Update on a Schema-defined table; $wpdb->update escapes values and conditions; the write path invalidates the cache in the caller.
		$updated = $wpdb->update( $table, $data, $where );

		if ( false === $updated ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $table is a Schema-derived internal identifier, never user input or HTML output; this is a developer-facing failure kept out of the client response by the controller.
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Delete on a Schema-defined table; $wpdb->delete escapes the conditions; the write path invalidates the cache in the caller.
		return $wpdb->delete( $table, $where );
	}

	/**
	 * Fetch a single row, binding all values through prepare().
	 *
	 * @since 1.0.0
	 *
	 * @param string            $sql  SQL with Schema-derived identifiers and %d/%s placeholders.
	 * @param array<int, mixed> $args Values bound into the placeholders.
	 * @return object|null The row object, or null.
	 */
	private function fetchRow( string $sql, array $args = array() ): ?object {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SQL embeds only Schema-derived table names; runtime values are bound via prepare(); find()/findByUuid() own the read-through cache.
		return $wpdb->get_row( array() === $args ? $sql : $wpdb->prepare( $sql, $args ) );
	}

	/**
	 * Fetch a result set, binding all values through prepare().
	 *
	 * @since 1.0.0
	 *
	 * @param string            $sql  SQL with Schema-derived identifiers and %d/%s placeholders.
	 * @param array<int, mixed> $args Values bound into the placeholders.
	 * @return array<int, object> The rows (empty on no match).
	 */
	private function fetchRows( string $sql, array $args = array() ): array {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SQL embeds only Schema-derived table names; runtime values are bound via prepare(); these are bounded admin/collection reads.
		$rows = $wpdb->get_results( array() === $args ? $sql : $wpdb->prepare( $sql, $args ) );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Fetch a single scalar, binding all values through prepare().
	 *
	 * @since 1.0.0
	 *
	 * @param string            $sql  SQL with Schema-derived identifiers and %d/%s placeholders.
	 * @param array<int, mixed> $args Values bound into the placeholders.
	 * @return string|null The scalar value, or null.
	 */
	private function fetchVar( string $sql, array $args = array() ): ?string {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SQL embeds only Schema-derived table names; runtime values are bound via prepare(); count/sum probes have nothing to cache.
		$value = $wpdb->get_var( array() === $args ? $sql : $wpdb->prepare( $sql, $args ) );

		return null === $value ? null : (string) $value;
	}
}
