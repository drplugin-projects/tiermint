<?php
/**
 * Outbound webhook event dispatcher.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Webhooks;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Core\Hookable;
use DrPlugin\WP\TierMint\Customers\Customer;
use DrPlugin\WP\TierMint\Licensing\Activation;
use DrPlugin\WP\TierMint\Licensing\License;
use DrPlugin\WP\TierMint\Orders\Order;

/**
 * Queue one outbound webhook per subscribed endpoint.
 *
 * Snapshot JSON at dispatch so every retry sends the same body.
 */
class WebhookDispatcher implements Hookable {

	/**
	 * The Action Scheduler hook queued per delivery.
	 *
	 * @var string
	 */
	public const JOB_HOOK = 'tiermint_job_send_webhook';

	/**
	 * The registered endpoint store.
	 *
	 * @var WebhookEndpoints
	 */
	private WebhookEndpoints $endpoints;

	/**
	 * Records contained enqueue failures.
	 *
	 * @var \DrPlugin\WP\TierMint\Database\Logger
	 */
	private \DrPlugin\WP\TierMint\Database\Logger $logger;

	/**
	 * Wire the endpoint store and the audit logger.
	 *
	 * @param WebhookEndpoints                      $endpoints Registered endpoint configs.
	 * @param \DrPlugin\WP\TierMint\Database\Logger $logger    Audit log writer for contained failures.
	 */
	public function __construct( WebhookEndpoints $endpoints, \DrPlugin\WP\TierMint\Database\Logger $logger ) {
		$this->endpoints = $endpoints;
		$this->logger    = $logger;
	}

	/**
	 * Bind every catalog event's internal action.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'tiermint_order_completed', array( $this, 'onOrderCompleted' ) );
		add_action( 'tiermint_order_refunded', array( $this, 'onOrderRefunded' ), 10, 2 );
		add_action( 'tiermint_license_generated', array( $this, 'onLicenseGenerated' ), 10, 2 );
		add_action( 'tiermint_license_activated', array( $this, 'onLicenseActivated' ), 10, 2 );
		add_action( 'tiermint_license_deactivated', array( $this, 'onLicenseDeactivated' ), 10, 2 );
		add_action( 'tiermint_license_expired', array( $this, 'onLicenseExpired' ) );
		add_action( 'tiermint_license_renewed', array( $this, 'onLicenseRenewed' ) );
		add_action( 'tiermint_customer_created', array( $this, 'onCustomerCreated' ) );
	}

	/**
	 * Fan out order.completed.
	 *
	 * @param Order $order The completed order.
	 * @return void
	 */
	public function onOrderCompleted( Order $order ): void {
		$this->dispatch( 'order.completed', $this->orderData( $order ) );
	}

	/**
	 * Fan out order.refunded with the refunded amount.
	 *
	 * @param Order $order  The parent order after the refund advanced it.
	 * @param int   $amount The amount refunded in minor units.
	 * @return void
	 */
	public function onOrderRefunded( Order $order, int $amount ): void {
		$data                    = $this->orderData( $order );
		$data['amount_refunded'] = $amount;

		$this->dispatch( 'order.refunded', $data );
	}

	/**
	 * Fan out license.generated with the order reference.
	 *
	 * @param License $license The minted license.
	 * @param Order   $order   The order that drove the mint.
	 * @return void
	 */
	public function onLicenseGenerated( License $license, Order $order ): void {
		$data               = $this->licenseData( $license );
		$data['order_uuid'] = $order->uuid();

		$this->dispatch( 'license.generated', $data );
	}

	/**
	 * Fan out license.activated with the site URL.
	 *
	 * @param License    $license    The license after the activation.
	 * @param Activation $activation The active activation.
	 * @return void
	 */
	public function onLicenseActivated( License $license, Activation $activation ): void {
		$data             = $this->licenseData( $license );
		$data['site_url'] = $activation->siteUrl();

		$this->dispatch( 'license.activated', $data );
	}

	/**
	 * Fan out license.deactivated with the site URL.
	 *
	 * @param License    $license    The license after the deactivation.
	 * @param Activation $activation The deactivated activation.
	 * @return void
	 */
	public function onLicenseDeactivated( License $license, Activation $activation ): void {
		$data             = $this->licenseData( $license );
		$data['site_url'] = $activation->siteUrl();

		$this->dispatch( 'license.deactivated', $data );
	}

	/**
	 * Fan out license.expired.
	 *
	 * @param License $license The expired license.
	 * @return void
	 */
	public function onLicenseExpired( License $license ): void {
		$this->dispatch( 'license.expired', $this->licenseData( $license ) );
	}

	/**
	 * Fan out license.renewed.
	 *
	 * @param License $license The renewed license (order context optional and
	 *                         deliberately omitted from the snapshot: the
	 *                         renewal order, when any, emits its own
	 *                         order.completed).
	 * @return void
	 */
	public function onLicenseRenewed( License $license ): void {
		$this->dispatch( 'license.renewed', $this->licenseData( $license ) );
	}

	/**
	 * Fan out customer.created.
	 *
	 * @param Customer $customer The newly created customer.
	 * @return void
	 */
	public function onCustomerCreated( Customer $customer ): void {
		$this->dispatch(
			'customer.created',
			array(
				'id'         => $customer->id(),
				'user_id'    => $customer->userId(),
				'email'      => $customer->email(),
				'name'       => $customer->name(),
				'created_at' => $customer->createdAt(),
			)
		);
	}

	/**
	 * Snapshot an event and enqueue deliveries for subscribed endpoints.
	 *
	 * @param string               $event The catalog event name.
	 * @param array<string, mixed> $data  The entity snapshot.
	 * @return void
	 */
	private function dispatch( string $event, array $data ): void {
		$subscribers = $this->endpoints->activeFor( $event );

		if ( array() === $subscribers ) {
			return;
		}

		$body = (string) wp_json_encode(
			array(
				'id'         => 'whk_' . wp_generate_uuid4(),
				'event'      => $event,
				'created_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'data'       => $data,
			)
		);

		foreach ( $subscribers as $endpoint ) {
			// One enqueue failure must not block other endpoints or payment processing.
			try {
				as_enqueue_async_action(
					self::JOB_HOOK,
					array( $endpoint['id'], $event, 'whd_' . wp_generate_uuid4(), $body, 0 ),
					'tiermint'
				);
			} catch ( \Throwable $failure ) {
				$this->logger->log(
					'error',
					sprintf( 'Outbound webhook enqueue failed for endpoint %1$s (event %2$s): %3$s', $endpoint['id'], $event, $failure->getMessage() ),
					array(
						'endpoint' => $endpoint['id'],
						'event'    => $event,
					)
				);
			}
		}
	}

	/**
	 * Build an order snapshot without gateway transaction IDs.
	 *
	 * @param Order $order The order to snapshot.
	 * @return array<string, mixed> JSON-safe snapshot.
	 */
	private function orderData( Order $order ): array {
		$items = array();

		foreach ( $order->items() as $item ) {
			$items[] = array(
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

		return array(
			'id'             => $order->id(),
			'uuid'           => $order->uuid(),
			'status'         => $order->status()->value,
			'type'           => $order->type()->value,
			'customer_id'    => $order->customerId(),
			'email'          => $order->email(),
			'currency'       => $order->currency(),
			'subtotal'       => $order->subtotal(),
			'discount_total' => $order->discountTotal(),
			'tax_total'      => $order->taxTotal(),
			'total'          => $order->total(),
			'discount_code'  => $order->discountCode(),
			'gateway'        => $order->gateway(),
			'created_at'     => $order->createdAt(),
			'completed_at'   => $order->completedAt(),
			'items'          => $items,
		);
	}

	/**
	 * Build a licence snapshot with its full key for signed admin-controlled endpoints.
	 *
	 * @param License $license The license to snapshot.
	 * @return array<string, mixed> JSON-safe snapshot.
	 */
	private function licenseData( License $license ): array {
		return array(
			'id'               => $license->id(),
			'license_key'      => $license->licenseKey(),
			'status'           => $license->status()->value,
			'product_id'       => $license->productId(),
			'price_id'         => $license->priceId(),
			'order_id'         => $license->orderId(),
			'customer_id'      => $license->customerId(),
			'activation_limit' => $license->activationLimit(),
			'activation_count' => $license->activationCount(),
			'expires_at'       => null !== $license->expiresAt() ? $license->expiresAt()->format( 'Y-m-d H:i:s' ) : null,
			'created_at'       => null !== $license->createdAt() ? $license->createdAt()->format( 'Y-m-d H:i:s' ) : null,
		);
	}
}
