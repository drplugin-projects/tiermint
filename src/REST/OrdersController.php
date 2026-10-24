<?php
/**
 * REST controller for the Orders management resource.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\REST;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Customers\Customer;
use DrPlugin\WP\TierMint\Orders\Order;
use DrPlugin\WP\TierMint\Orders\OrderBuyers;
use DrPlugin\WP\TierMint\Orders\OrderException;
use DrPlugin\WP\TierMint\Orders\OrderItem;
use DrPlugin\WP\TierMint\Orders\OrderNotFoundException;
use DrPlugin\WP\TierMint\Orders\OrderPersistenceException;
use DrPlugin\WP\TierMint\Orders\OrderService;
use DrPlugin\WP\TierMint\Orders\OrderStatus;
use DrPlugin\WP\TierMint\Orders\OrderValidationException;
use DrPlugin\WP\TierMint\Payments\GatewayConfigurationException;
use DrPlugin\WP\TierMint\Payments\PaymentException;
use DrPlugin\WP\TierMint\Payments\RefundCoordinator;
use DrPlugin\WP\TierMint\Payments\RefundLockException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST routes for order lists, details and refunds.
 *
 * Services own business rules. Manual orders and receipt emails have no route yet.
 *
 * @since 1.0.0
 */
final class OrdersController extends AbstractRestController {

	/**
	 * The order domain service this controller reads through.
	 *
	 * @var OrderService
	 */
	private OrderService $orders;

	/**
	 * The refund coordinator this controller issues refunds through.
	 *
	 * @var RefundCoordinator
	 */
	private RefundCoordinator $refunds;

	/**
	 * The buyer resolver the collection names its buyers through.
	 *
	 * @var OrderBuyers
	 */
	private OrderBuyers $buyers;

	/**
	 * Set the order services.
	 *
	 * @since 1.0.0
	 *
	 * @param OrderService      $orders  The orders domain service.
	 * @param RefundCoordinator $refunds The gateway/ledger refund coordinator.
	 * @param OrderBuyers       $buyers  The batched buyer resolver.
	 */
	public function __construct( OrderService $orders, RefundCoordinator $refunds, OrderBuyers $buyers ) {
		$this->orders  = $orders;
		$this->refunds = $refunds;
		$this->buyers  = $buyers;
	}

	/**
	 * Register the Orders collection, single-item, and refund routes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			'/orders',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'managePermission' ),
					'args'                => $this->collectionArgs(),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/orders/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'show' ),
					'permission_callback' => array( $this, 'managePermission' ),
					'args'                => $this->idArgs(),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/orders/(?P<id>\d+)/refund',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'refund' ),
					'permission_callback' => array( $this, 'managePermission' ),
					'args'                => array_merge( $this->idArgs(), $this->refundArgs() ),
				),
			)
		);
	}

	/**
	 * List orders with filters and pagination headers.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response The collection response.
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		$perPage = (int) $request->get_param( 'per_page' );

		$args = array(
			'page'        => (int) $request->get_param( 'page' ),
			'per_page'    => $perPage,
			'search'      => $request->get_param( 'search' ),
			'status'      => $request->get_param( 'status' ),
			'customer_id' => $request->get_param( 'customer_id' ),
			'product_id'  => $request->get_param( 'product_id' ),
			'orderby'     => $request->get_param( 'orderby' ),
			'order'       => $request->get_param( 'order' ),
		);

		$orders = $this->orders->list( $args );
		$total  = $this->orders->count( $args );

		// Prime metadata once for the page.
		if ( array() !== $orders ) {
			update_meta_cache( 'tiermint_order', array_map( static fn ( Order $order ): int => (int) $order->id(), $orders ) );
		}

		$response = new WP_REST_Response( $this->presentCollection( $orders ), 200 );

		return $this->withPagination( $response, $total, $perPage );
	}

	/**
	 * Shape a page of orders with buyer details.
	 *
	 * Deleted buyers keep null details. The order email remains available.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, Order> $orders The orders on this page.
	 * @return array<int, array<string, mixed>> The response representations.
	 */
	private function presentCollection( array $orders ): array {
		$buyers = $this->buyers->forOrders( $orders );

		return array_map(
			function ( Order $order ) use ( $buyers ): array {
				$buyer = $buyers[ (int) $order->customerId() ] ?? null;
				$data  = $this->presentOrder( $order );

				$data['customer_name']    = $buyer instanceof Customer ? $buyer->name() : null;
				$data['customer_user_id'] = $buyer instanceof Customer ? $buyer->userId() : null;

				return $data;
			},
			$orders
		);
	}

	/**
	 * Get one order and its items.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error The order, or an error envelope.
	 */
	public function show( WP_REST_Request $request ) {
		$order = $this->orders->get( (int) $request->get_param( 'id' ) );

		if ( null === $order ) {
			return $this->notFound();
		}

		return new WP_REST_Response( $this->presentOrder( $order ), 200 );
	}

	/**
	 * Refund a completed order.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error The refunded order, or an error envelope.
	 */
	public function refund( WP_REST_Request $request ) {
		$context = array();

		$idempotencyKey = trim( (string) $request->get_param( 'idempotency_key' ) );
		if ( '' !== $idempotencyKey ) {
			// A supplied key keeps identical partial refunds separate.
			$context['idempotency_key'] = $idempotencyKey;
		}

		try {
			$order = $this->refunds->refund(
				(int) $request->get_param( 'id' ),
				(int) $request->get_param( 'amount' ),
				$context
			);
		} catch ( OrderNotFoundException $e ) {
			return $this->notFound();
		} catch ( OrderValidationException $e ) {
			return $this->invalidParams( $e->getErrors() );
		} catch ( OrderPersistenceException $e ) {
			// Do not expose database details.
			return $this->serverError( __( 'The refund could not be completed. Please try again.', 'tiermint' ) );
		} catch ( OrderException $e ) {
			// Other domain errors are invalid input.
			return $this->invalidParams( array( $e->getMessage() ) );
		} catch ( RefundLockException $e ) {
			// A concurrent refund holds the order lock.
			return $this->error(
				'tiermint_refund_in_progress',
				__( 'Another refund for this order is currently being processed. Please try again in a moment.', 'tiermint' ),
				409
			);
		} catch ( GatewayConfigurationException $e ) {
			// The gateway rejected its configuration before the request.
			return $this->error(
				'tiermint_gateway_not_configured',
				__( 'The payment gateway is not configured, so the refund was not attempted. Nothing was charged or refunded.', 'tiermint' ),
				502
			);
		} catch ( PaymentException $e ) {
			// The gateway result is uncertain. The webhook reconciles a completed refund.
			return $this->error(
				'tiermint_gateway_error',
				__( 'The payment gateway did not confirm the refund. The money may or may not have moved; check the order and your gateway dashboard before trying again.', 'tiermint' ),
				502
			);
		}

		return new WP_REST_Response( $this->presentOrder( $order ), 200 );
	}

	/**
	 * The `args` schema for the collection query.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>> The parameter schema.
	 */
	private function collectionArgs(): array {
		return array(
			'page'        => array(
				'description'       => __( 'Current page of the collection.', 'tiermint' ),
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'per_page'    => array(
				'description'       => __( 'Maximum number of items to return per page.', 'tiermint' ),
				'type'              => 'integer',
				'default'           => 10,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'search'      => array(
				'description'       => __( 'Limit results to those matching an email or order id.', 'tiermint' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'status'      => array(
				'description'       => __( 'Limit results to a single order status.', 'tiermint' ),
				'type'              => 'string',
				'enum'              => $this->statusValues(),
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'customer_id' => array(
				'description'       => __( 'Limit results to a single customer.', 'tiermint' ),
				'type'              => 'integer',
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'product_id'  => array(
				'description'       => __( 'Limit results to orders holding a line item for a product.', 'tiermint' ),
				'type'              => 'integer',
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'orderby'     => array(
				'description'       => __( 'Sort collection by order attribute.', 'tiermint' ),
				'type'              => 'string',
				'enum'              => array( 'id', 'created_at', 'total' ),
				'default'           => 'created_at',
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'order'       => array(
				'description'       => __( 'Order sort attribute ascending or descending.', 'tiermint' ),
				'type'              => 'string',
				'enum'              => array( 'asc', 'desc' ),
				'default'           => 'desc',
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * Return the refund-body schema.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>> The parameter schema.
	 */
	private function refundArgs(): array {
		return array(
			'amount'          => array(
				'description'       => __( 'Amount to refund, in the minor units of the order currency.', 'tiermint' ),
				'type'              => 'integer',
				'required'          => true,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'idempotency_key' => array(
				'description'       => __( 'Optional per-refund key so two deliberate identical refunds are distinct at the gateway. Omit to use the server default (which replays an identical amount).', 'tiermint' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * The `args` schema for the `{id}` path parameter.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>> The parameter schema.
	 */
	private function idArgs(): array {
		return array(
			'id' => array(
				'description'       => __( 'Unique order identifier.', 'tiermint' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * Return valid order statuses.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, string> The status values.
	 */
	private function statusValues(): array {
		return array_map( static fn ( OrderStatus $s ): string => $s->value, OrderStatus::cases() );
	}

	/**
	 * Shape an order and its line items for JSON.
	 *
	 * The collection adds buyer fields.
	 *
	 * @since 1.0.0
	 *
	 * @param Order $order The order to present.
	 * @return array<string, mixed> The response representation.
	 */
	private function presentOrder( Order $order ): array {
		return array(
			'id'                => (int) $order->id(),
			'uuid'              => $order->uuid(),
			'status'            => $order->status()->value,
			'type'              => $order->type()->value,
			'customer_id'       => $order->customerId(),
			'email'             => $order->email(),
			'currency'          => $order->currency(),
			'subtotal'          => $order->subtotal(),
			'discount_total'    => $order->discountTotal(),
			'tax_total'         => $order->taxTotal(),
			'total'             => $order->total(),
			'gateway'           => $order->gateway(),
			'gateway_txn_id'    => $order->gatewayTxnId(),
			'discount_code'     => $order->discountCode(),
			'parent_id'         => $order->parentId(),
			'created_at'        => $order->createdAt(),
			'completed_at'      => $order->completedAt(),
			'updated_at'        => $order->updatedAt(),
			// Compliance fields stay null when checkout did not collect them.
			'country'           => $order->country(),
			'terms_accepted_at' => $this->orderMeta( $order, CheckoutController::TERMS_META ),
			'billing_address'   => $this->orderMeta( $order, CheckoutController::ADDRESS_META ),
			'company_name'      => $this->orderMeta( $order, CheckoutController::COMPANY_META ),
			'tax_id'            => $this->orderMeta( $order, CheckoutController::TAX_ID_META ),
			'ip_address'        => $order->ipAddress(),
			'items'             => array_map( array( $this, 'presentItem' ), $order->items() ),
		);
	}

	/**
	 * Read a single order-meta value, or null when it is absent.
	 *
	 * @since 1.0.0
	 *
	 * @param Order  $order The order.
	 * @param string $key   The order-meta key.
	 * @return mixed The stored value, or null.
	 */
	private function orderMeta( Order $order, string $key ) {
		$id = $order->id();

		if ( null === $id ) {
			return null;
		}

		$value = get_metadata( 'tiermint_order', $id, $key, true );

		return '' === $value ? null : $value;
	}

	/**
	 * Shape one order line item for the JSON response.
	 *
	 * @since 1.0.0
	 *
	 * @param OrderItem $item The line item to present.
	 * @return array<string, mixed> The response representation.
	 */
	private function presentItem( OrderItem $item ): array {
		return array(
			'id'           => (int) $item->id(),
			'product_id'   => $item->productId(),
			'price_id'     => $item->priceId(),
			'product_name' => $item->productName(),
			'price_name'   => $item->priceName(),
			'quantity'     => $item->quantity(),
			'subtotal'     => $item->subtotal(),
			'discount'     => $item->discount(),
			'tax'          => $item->tax(),
			'total'        => $item->total(),
		);
	}
}
