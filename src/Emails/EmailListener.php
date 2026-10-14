<?php
/**
 * Hook listener wiring transactional emails to order and license events.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Emails;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Core\Hookable;
use DrPlugin\WP\TierMint\Database\Logger;
use DrPlugin\WP\TierMint\Licensing\License;
use DrPlugin\WP\TierMint\Licensing\LicenseService;
use DrPlugin\WP\TierMint\Orders\Order;
use DrPlugin\WP\TierMint\Orders\OrderService;
use DrPlugin\WP\TierMint\Orders\OrderStatus;
use DrPlugin\WP\TierMint\Products\ProductService;
use DrPlugin\WP\TierMint\Updates\CustomerDownloads;
use DrPlugin\WP\TierMint\Util\Money;
use Throwable;

/**
 * Queue receipts and late licence deliveries away from the payment request.
 *
 * Markers make queue retries idempotent. Jobs reload data and contain failures.
 *
 * @since 1.0.0
 */
final class EmailListener implements Hookable {

	/**
	 * The queued email dispatch hook.
	 *
	 * @var string
	 */
	public const JOB_HOOK = 'tiermint_job_send_email';

	/**
	 * The Action Scheduler group all TierMint jobs share.
	 *
	 * @var string
	 */
	private const JOB_GROUP = 'tiermint';

	/**
	 * The meta type the metadata API resolves order meta through ({@see \DrPlugin\WP\TierMint\Database\MetaTables}).
	 *
	 * @var string
	 */
	private const ORDER_META_TYPE = 'tiermint_order';

	/**
	 * Receipt marker and gate for late licence delivery.
	 *
	 * @var string
	 */
	private const RECEIPT_SENT_META = '_tiermint_receipt_sent';

	/**
	 * Licence delivery marker for queue retries.
	 *
	 * @var string
	 */
	private const DELIVERY_SENT_META = '_tiermint_delivery_sent';

	/**
	 * The metadata type mapped onto the licenses meta table.
	 *
	 * @var string
	 */
	private const LICENSE_META_TYPE = 'tiermint_license';

	/**
	 * Emailed download-link lifetime in seconds, 72 hours.
	 *
	 * @var int
	 */
	private const EMAIL_DOWNLOAD_TTL = 259200;

	/**
	 * Wire the listener with its collaborators.
	 *
	 * @since 1.0.0
	 *
	 * @param EmailRegistry     $emails    The email registry (resolves an email by id).
	 * @param Mailer            $mailer    The mailer that renders and sends.
	 * @param OrderService      $orders    Order reads (jobs reload the order fresh).
	 * @param LicenseService    $licenses  License reads (order keys, single license reload).
	 * @param ProductService    $products  Product reads (product names for keys/delivery).
	 * @param CustomerDownloads $downloads The signed-download assembler for the links section.
	 * @param Money             $money     Minor-unit money formatting for the receipt.
	 * @param Logger            $logger    The `_logs` audit writer.
	 */
	public function __construct(
		private readonly EmailRegistry $emails,
		private readonly Mailer $mailer,
		private readonly OrderService $orders,
		private readonly LicenseService $licenses,
		private readonly ProductService $products,
		private readonly CustomerDownloads $downloads,
		private readonly Money $money,
		private readonly Logger $logger
	) {
	}

	/**
	 * Register all hook wiring for the email layer.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'tiermint_order_completed', array( $this, 'onOrderCompleted' ), 10, 1 );
		add_action( 'tiermint_license_generated', array( $this, 'onLicenseGenerated' ), 10, 2 );
		add_action( self::JOB_HOOK, array( $this, 'onSendEmailJob' ), 10, 2 );
	}

	/**
	 * Queue a completed order receipt outside the payment request.
	 *
	 * @since 1.0.0
	 *
	 * @param Order $order The completed order.
	 * @return void
	 */
	public function onOrderCompleted( Order $order ): void {
		if ( null === $order->id() ) {
			return;
		}

		as_enqueue_async_action(
			self::JOB_HOOK,
			array( PurchaseReceiptEmail::ID, (int) $order->id() ),
			self::JOB_GROUP
		);
	}

	/**
	 * Queue delivery for a licence minted after the order receipt.
	 *
	 * During checkout, the queued receipt carries the licence key.
	 *
	 * @since 1.0.0
	 *
	 * @param License $license The freshly minted license.
	 * @param Order   $order   The order that earned it.
	 * @return void
	 */
	public function onLicenseGenerated( License $license, Order $order ): void {
		if ( null === $license->id() || null === $order->id() ) {
			return;
		}

		$receiptSent = get_metadata( self::ORDER_META_TYPE, (int) $order->id(), self::RECEIPT_SENT_META, true );

		if ( '' === $receiptSent || false === $receiptSent ) {
			// The receipt has not gone out yet; it will include this key.
			return;
		}

		as_enqueue_async_action(
			self::JOB_HOOK,
			array( LicenseDeliveryEmail::ID, (int) $license->id() ),
			self::JOB_GROUP
		);
	}

	/**
	 * Reload data and send a queued email without failing the queue runner.
	 *
	 * @since 1.0.0
	 *
	 * @param string $emailId  The email id to dispatch.
	 * @param int    $objectId The order id (receipt) or license id (delivery).
	 * @return void
	 */
	public function onSendEmailJob( string $emailId, int $objectId ): void {
		try {
			$email = $this->emails->get( $emailId );

			if ( null === $email ) {
				$this->logger->log(
					'error',
					sprintf( 'Cannot dispatch unknown email "%s".', $emailId ),
					array( 'object_id' => $objectId )
				);

				return;
			}

			if ( PurchaseReceiptEmail::ID === $emailId ) {
				$this->sendReceipt( $email, $objectId );

				return;
			}

			if ( LicenseDeliveryEmail::ID === $emailId ) {
				$this->sendLicenseDelivery( $email, $objectId );

				return;
			}

			// A third party enqueued an email the listener does not know how to
			// build context for. Log rather than guess at a context.
			$this->logger->log(
				'error',
				sprintf( 'No context builder for email "%s".', $emailId ),
				array( 'object_id' => $objectId )
			);
		} catch ( Throwable $failure ) {
			$this->logger->log(
				'error',
				sprintf( 'Email job for "%s" (object %d) failed: %s', $emailId, $objectId, $failure->getMessage() ),
				array( 'object_id' => $objectId )
			);
		}
	}

	/**
	 * Send an order receipt once, for a delivering order.
	 *
	 * Mark a receipt only after a successful send.
	 *
	 * @since 1.0.0
	 *
	 * @param Email $email   The receipt email.
	 * @param int   $orderId The order to receipt.
	 * @return void
	 */
	private function sendReceipt( Email $email, int $orderId ): void {
		$order = $this->orders->get( $orderId );

		if ( null === $order ) {
			$this->logger->log(
				'error',
				sprintf( 'Receipt job skipped: order %d no longer exists.', $orderId ),
				array(),
				'order',
				$orderId
			);

			return;
		}

		if ( ! $this->orderDelivers( $order ) ) {
			$this->logger->log(
				'error',
				sprintf( 'Receipt job skipped: order %d is %s, not a delivering status.', $orderId, $order->status()->value ),
				array(),
				'order',
				$orderId
			);

			return;
		}

		// Idempotency: a receipt already sent for this order is a no-op, so a
		// replayed or double-enqueued job never sends a second receipt.
		$alreadySent = get_metadata( self::ORDER_META_TYPE, $orderId, self::RECEIPT_SENT_META, true );

		if ( '' !== $alreadySent && false !== $alreadySent ) {
			return;
		}

		$sent = $this->mailer->send( $email, $this->receiptContext( $order ) );

		if ( $sent ) {
			// Stamp only after a real send so a failed attempt is retried, not
			// swallowed, while a success can never be re-sent.
			update_metadata( self::ORDER_META_TYPE, $orderId, self::RECEIPT_SENT_META, gmdate( 'Y-m-d H:i:s' ) );
		}
	}

	/**
	 * Send a standalone license delivery for one license. Skips (logged) when the
	 * license or its order has vanished.
	 *
	 * @since 1.0.0
	 *
	 * @param Email $email     The license delivery email.
	 * @param int   $licenseId The license to deliver.
	 * @return void
	 */
	private function sendLicenseDelivery( Email $email, int $licenseId ): void {
		// Idempotency mirror of the receipt marker: only the first job for a
		// license sends; replays and double-enqueues skip silently.
		$alreadySent = get_metadata( self::LICENSE_META_TYPE, $licenseId, self::DELIVERY_SENT_META, true );

		if ( '' !== $alreadySent && false !== $alreadySent ) {
			return;
		}

		$license = $this->licenses->get( $licenseId );

		if ( null === $license ) {
			$this->logger->log(
				'error',
				sprintf( 'License delivery job skipped: license %d no longer exists.', $licenseId ),
				array(),
				'license',
				$licenseId
			);

			return;
		}

		$order = $this->orders->get( $license->orderId() );

		if ( null === $order ) {
			$this->logger->log(
				'error',
				sprintf( 'License delivery job skipped: order %d for license %d no longer exists.', $license->orderId(), $licenseId ),
				array(),
				'license',
				$licenseId
			);

			return;
		}

		if ( $this->mailer->send( $email, $this->licenseDeliveryContext( $license, $order ) ) ) {
			update_metadata( self::LICENSE_META_TYPE, $licenseId, self::DELIVERY_SENT_META, gmdate( 'Y-m-d H:i:s' ) );
		}
	}

	/**
	 * Build receipt data from an order, licences and download links.
	 *
	 * @since 1.0.0
	 *
	 * @param Order $order The completed order.
	 * @return array<string, mixed> The receipt view context.
	 */
	private function receiptContext( Order $order ): array {
		$currency = $order->currency();
		$items    = array();

		foreach ( $order->items() as $item ) {
			$items[] = array(
				'name'     => $item->productName(),
				'quantity' => $item->quantity(),
				'subtotal' => $this->money->format( $item->subtotal(), $currency ),
				'total'    => $this->money->format( $item->total(), $currency ),
			);
		}

		$licenses = array();

		foreach ( $this->licenses->listByOrder( (int) $order->id() ) as $license ) {
			$product    = $this->products->get( $license->productId() );
			$licenses[] = array(
				'product_name' => null !== $product ? $product->name() : '',
				'license_key'  => $license->licenseKey(),
			);
		}

		// The discount line only exists when a discount actually reduced the order.
		$discount = null;

		if ( $order->discountTotal() > 0 ) {
			$discount = array(
				'code'   => (string) $order->discountCode(),
				'amount' => $this->money->format( $order->discountTotal(), $currency ),
			);
		}

		return array(
			'email_id'   => PurchaseReceiptEmail::ID,
			'store_name' => (string) get_bloginfo( 'name' ),
			'recipient'  => $order->email(),
			'order'      => array(
				'id'        => (int) $order->id(),
				'reference' => (string) $order->id(),
				'uuid'      => $order->uuid(),
				'date'      => $this->formatDate( $order->createdAt() ),
			),
			'items'      => $items,
			'subtotal'   => $this->money->format( $order->subtotal(), $currency ),
			'discount'   => $discount,
			'total'      => $this->money->format( $order->total(), $currency ),
			'licenses'   => $licenses,
			'downloads'  => $this->downloads->entriesForOrder( $order, self::EMAIL_DOWNLOAD_TTL ),
		);
	}

	/**
	 * Build licence delivery data from a licence and order.
	 *
	 * @since 1.0.0
	 *
	 * @param License $license The license to deliver.
	 * @param Order   $order   The order the license belongs to.
	 * @return array<string, mixed> The license-delivery view context.
	 */
	private function licenseDeliveryContext( License $license, Order $order ): array {
		$product = $this->products->get( $license->productId() );
		$expires = $license->expiresAt();

		return array(
			'email_id'     => LicenseDeliveryEmail::ID,
			'store_name'   => (string) get_bloginfo( 'name' ),
			'recipient'    => $order->email(),
			'product_name' => null !== $product ? $product->name() : '',
			'license_key'  => $license->licenseKey(),
			'expires_at'   => null !== $expires ? $this->formatDate( $expires->format( 'Y-m-d H:i:s' ) ) : '',
			'license_id'   => (int) $license->id(),
			'order'        => array(
				'id'        => (int) $order->id(),
				'reference' => (string) $order->id(),
				'uuid'      => $order->uuid(),
			),
			'downloads'    => $this->downloads->entriesForOrder( $order, self::EMAIL_DOWNLOAD_TTL ),
		);
	}

	/**
	 * Check whether an order status sends a receipt.
	 *
	 * @since 1.0.0
	 *
	 * @param Order $order The order.
	 * @return bool True when a receipt should be sent.
	 */
	private function orderDelivers( Order $order ): bool {
		return in_array( $order->status(), array( OrderStatus::Completed, OrderStatus::PartiallyRefunded ), true );
	}

	/**
	 * Format a UTC timestamp with the site's date settings.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $utc A 'Y-m-d H:i:s' UTC timestamp, or null.
	 * @return string The localized date, or '' when the input was empty/unparseable.
	 */
	private function formatDate( ?string $utc ): string {
		if ( null === $utc || '' === $utc ) {
			return '';
		}

		$timestamp = strtotime( $utc . ' UTC' );

		if ( false === $timestamp ) {
			return '';
		}

		$format = (string) get_option( 'date_format' );

		if ( '' === $format ) {
			$format = 'Y-m-d';
		}

		return (string) wp_date( $format, $timestamp );
	}
}
