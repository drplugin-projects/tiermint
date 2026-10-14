<?php
/**
 * The post-checkout purchase-confirmation (receipt) front-end component.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend\Components;

use DrPlugin\WP\TierMint\Frontend\AbstractComponent;
use DrPlugin\WP\TierMint\Frontend\RenderContext;
use DrPlugin\WP\TierMint\Customers\CustomerService;
use DrPlugin\WP\TierMint\Licensing\License;
use DrPlugin\WP\TierMint\Licensing\LicenseService;
use DrPlugin\WP\TierMint\Orders\Order;
use DrPlugin\WP\TierMint\Orders\OrderService;
use DrPlugin\WP\TierMint\Orders\OrderStatus;
use DrPlugin\WP\TierMint\Orders\OrderType;
use DrPlugin\WP\TierMint\Updates\CustomerDownloads;
use DrPlugin\WP\TierMint\Util\Money;
use DrPlugin\WP\TierMint\Util\RequestUrl;

defined( 'ABSPATH' ) || exit;

/**
 * Receipt for a returned checkout order.
 *
 * The UUID is the capability for a guest receipt. Linked accounts need a login.
 *
 * @since 1.0.0
 */
final class PurchaseConfirmation extends AbstractComponent {

	/**
	 * Order reads (uuid resolution).
	 *
	 * @var OrderService
	 */
	private OrderService $orders;

	/**
	 * License reads (the order's minted keys).
	 *
	 * @var LicenseService
	 */
	private LicenseService $licenses;

	/**
	 * The shared purchased-files assembler (the order's download links).
	 *
	 * @var CustomerDownloads
	 */
	private CustomerDownloads $downloads;

	/**
	 * Reads the order customer for the linked-account guard.
	 *
	 * @var CustomerService
	 */
	private CustomerService $customers;

	/**
	 * Money formatting for line totals and the order total.
	 *
	 * @var Money
	 */
	private Money $money;

	/**
	 * Wire the component with its read collaborators.
	 *
	 * @since 1.0.0
	 *
	 * @param OrderService      $orders    Order reads (uuid resolution).
	 * @param LicenseService    $licenses  License reads (the order's keys).
	 * @param CustomerDownloads $downloads The purchased-files/download assembler.
	 * @param CustomerService   $customers Customer reads (the order's buyer, for the guard).
	 * @param Money             $money     Money formatting.
	 */
	public function __construct(
		OrderService $orders,
		LicenseService $licenses,
		CustomerDownloads $downloads,
		CustomerService $customers,
		Money $money
	) {
		$this->orders    = $orders;
		$this->licenses  = $licenses;
		$this->downloads = $downloads;
		$this->customers = $customers;
		$this->money     = $money;
	}

	/**
	 * Get the component slug.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'purchase-confirmation';
	}

	/**
	 * The attribute schema adapters map their controls onto.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function schema(): array {
		return array(
			'title'          => array(
				'type'        => 'string',
				'default'     => __( 'Thank you for your purchase', 'tiermint' ),
				'control'     => 'text',
				'label'       => __( 'Heading', 'tiermint' ),
				'description' => __( 'The heading shown above the receipt. Leave empty to hide it.', 'tiermint' ),
			),
			'show_licenses'  => array(
				'type'        => 'boolean',
				'default'     => true,
				'control'     => 'toggle',
				'label'       => __( 'Show license keys', 'tiermint' ),
				'description' => __( 'List the license keys this order generated.', 'tiermint' ),
			),
			'show_downloads' => array(
				'type'        => 'boolean',
				'default'     => true,
				'control'     => 'toggle',
				'label'       => __( 'Show download links', 'tiermint' ),
				'description' => __( 'List the download links for the files this order includes.', 'tiermint' ),
			),
		);
	}

	/**
	 * Render the confirmation for the current request state.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $attrs Resolved attribute values.
	 * @param RenderContext        $ctx   Per-request customer state.
	 * @return string Escaped HTML.
	 */
	public function render( array $attrs, RenderContext $ctx ): string {
		$uuid = $this->returnedOrderUuid();

		// Keep the front end blank but show a receipt in the editor.
		if ( null === $uuid ) {
			if ( $ctx->isEditorPreview() ) {
				return $this->renderSample( $this->sampleReceiptData( $attrs ) );
			}

			return '';
		}

		$order = $this->orders->getByUuid( $uuid );

		// Never expose unknown UUIDs or refund-ledger rows.
		if ( null === $order || OrderType::Refund === $order->type() ) {
			return $this->renderTemplate( $this->stateData( 'not_found', $attrs ) );
		}

		// A linked-account order also needs the matching login.
		$signIn = $this->linkedAccountGuard( $attrs, $order, $ctx );

		if ( null !== $signIn ) {
			return $this->renderTemplate( $signIn );
		}

		switch ( $order->status() ) {
			case OrderStatus::Pending:
			case OrderStatus::Failed:
				return $this->renderTemplate( $this->processingData( $attrs, $order ) );

			case OrderStatus::Completed:
			case OrderStatus::PartiallyRefunded:
				return $this->renderTemplate( $this->receiptData( $attrs, $order ) );

			case OrderStatus::Refunded:
				return $this->renderTemplate( $this->refundedData( $attrs, $order ) );

			default:
				// Never show detail for unsupported order states.
				return $this->renderTemplate( $this->stateData( 'not_found', $attrs ) );
		}
	}

	/**
	 * Get a valid payment-return UUID, if present.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null The 36-character uuid, or null.
	 */
	private function returnedOrderUuid(): ?string {
		if ( ! isset( $_GET['tiermint_order'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of the payment-return marker; no state change.
			return null;
		}

		$raw = sanitize_text_field( wp_unslash( $_GET['tiermint_order'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of the payment-return marker; no state change.

		if ( 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $raw ) ) {
			return null;
		}

		return $raw;
	}

	/**
	 * Build a sign-in prompt for another customer's linked account.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $attrs Resolved attribute values.
	 * @param Order                $order The resolved order.
	 * @param RenderContext        $ctx   Per-request customer state (the visitor).
	 * @return array<string, mixed>|null The sign-in view data, or null to proceed.
	 */
	private function linkedAccountGuard( array $attrs, Order $order, RenderContext $ctx ): ?array {
		$customerId = $order->customerId();

		if ( null === $customerId ) {
			return null;
		}

		$customer = $this->customers->get( $customerId );

		if ( null === $customer || ! $customer->isLinked() ) {
			return null;
		}

		// A UUID alone cannot show a linked-account order.
		$visitor = $ctx->customer();

		if ( null !== $visitor && (int) $visitor->id() === (int) $customerId ) {
			return null;
		}

		return array(
			'state'     => 'sign_in',
			'title'     => (string) $attrs['title'],
			'login_url' => wp_login_url( $this->currentUrl() ),
		);
	}

	/**
	 * Build the pending-payment view data.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $attrs Resolved attribute values.
	 * @param Order                $order The unpaid order.
	 * @return array<string, mixed>
	 */
	private function processingData( array $attrs, Order $order ): array {
		$message = OrderStatus::Failed === $order->status()
			? __( 'Your payment has not completed. If you retried the payment or your bank is still confirming it, this page will update automatically.', 'tiermint' )
			: __( 'We are processing your payment. This page will update automatically once it is confirmed.', 'tiermint' );

		return array(
			'state'      => 'processing',
			'title'      => (string) $attrs['title'],
			'order_uuid' => $order->uuid(),
			'message'    => $message,
		);
	}

	/**
	 * Build complete receipt view data.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $attrs Resolved attribute values.
	 * @param Order                $order The delivering order.
	 * @return array<string, mixed>
	 */
	private function receiptData( array $attrs, Order $order ): array {
		$currency = $order->currency();

		$items = array();

		foreach ( $order->items() as $item ) {
			$items[] = array(
				'name'       => $item->productName(),
				'quantity'   => $item->quantity(),
				'line_total' => $this->money->format( $item->total(), $currency ),
			);
		}

		$showLicenses  = (bool) $attrs['show_licenses'];
		$showDownloads = (bool) $attrs['show_downloads'];

		return array(
			'state'          => 'receipt',
			'title'          => (string) $attrs['title'],
			'order_ref'      => (int) $order->id(),
			'date'           => $this->orderDate( $order ),
			'items'          => $items,
			'total'          => $this->money->format( $order->total(), $currency ),
			'show_licenses'  => $showLicenses,
			'show_downloads' => $showDownloads,
			'licenses'       => $showLicenses ? $this->licenseRows( $order ) : array(),
			// Endpoints check ownership again when the buyer clicks a link.
			'downloads'      => $showDownloads ? $this->downloads->entriesForOrder( $order, 6 * HOUR_IN_SECONDS ) : array(),
		);
	}

	/**
	 * The view data for a fully refunded order: a bare notice with the order
	 * reference and no keys or download links.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $attrs Resolved attribute values.
	 * @param Order                $order The fully refunded order.
	 * @return array<string, mixed>
	 */
	private function refundedData( array $attrs, Order $order ): array {
		return array(
			'state'     => 'refunded',
			'title'     => (string) $attrs['title'],
			'order_ref' => (int) $order->id(),
		);
	}

	/**
	 * Build receipt rows for licenses created by this order.
	 *
	 * @since 1.0.0
	 *
	 * @param Order $order The delivering order.
	 * @return array<int, array<string, string>>
	 */
	private function licenseRows( Order $order ): array {
		$itemNames = array();

		foreach ( $order->items() as $item ) {
			if ( null !== $item->id() ) {
				$itemNames[ (int) $item->id() ] = $item->productName();
			}
		}

		$rows = array();

		foreach ( $this->licenses->listByOrder( (int) $order->id() ) as $license ) {
			$rows[] = array(
				'product_name' => $this->licenseProductName( $license, $itemNames ),
				'license_key'  => $license->licenseKey(),
			);
		}

		return $rows;
	}

	/**
	 * The display name for a license: the purchased order line's product name
	 * when the line is known, otherwise a neutral fallback.
	 *
	 * @since 1.0.0
	 *
	 * @param License            $license   The minted license.
	 * @param array<int, string> $itemNames Order item id to product-name map.
	 * @return string
	 */
	private function licenseProductName( License $license, array $itemNames ): string {
		$itemId = $license->orderItemId();

		if ( null !== $itemId && isset( $itemNames[ $itemId ] ) ) {
			return $itemNames[ $itemId ];
		}

		return __( 'Your purchase', 'tiermint' );
	}

	/**
	 * Format the completed or created order time.
	 *
	 * @since 1.0.0
	 *
	 * @param Order $order The order.
	 * @return string The formatted date, or '' when no timestamp is stored.
	 */
	private function orderDate( Order $order ): string {
		$stamp = $order->completedAt() ?? $order->createdAt();

		if ( null === $stamp ) {
			return '';
		}

		$timestamp = strtotime( $stamp . ' UTC' );

		if ( false === $timestamp ) {
			return '';
		}

		return (string) date_i18n( (string) get_option( 'date_format' ), $timestamp );
	}

	/**
	 * The current page URL, used as the sign-in return target (mirrors
	 * LicenseManager and DownloadsList).
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private function currentUrl(): string {
		return RequestUrl::current();
	}

	/**
	 * The minimal view data for a detail-free state (the generic not-found notice).
	 *
	 * @since 1.0.0
	 *
	 * @param string               $state The state key the template branches on.
	 * @param array<string, mixed> $attrs Resolved attribute values.
	 * @return array<string, mixed>
	 */
	private function stateData( string $state, array $attrs ): array {
		return array(
			'state' => $state,
			'title' => (string) $attrs['title'],
		);
	}

	/**
	 * Build fixed receipt data for an editor preview.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $attrs Resolved attribute values.
	 * @return array<string, mixed>
	 */
	private function sampleReceiptData( array $attrs ): array {
		$showLicenses  = (bool) $attrs['show_licenses'];
		$showDownloads = (bool) $attrs['show_downloads'];

		return array(
			'state'          => 'receipt',
			'title'          => (string) $attrs['title'],
			'order_ref'      => 1042,
			'date'           => (string) date_i18n( (string) get_option( 'date_format' ), 1_722_506_400 ),
			'items'          => array(
				array(
					'name'       => __( 'Sample product', 'tiermint' ),
					'quantity'   => 1,
					'line_total' => $this->money->format( 4900 ),
				),
			),
			'total'          => $this->money->format( 4900 ),
			'show_licenses'  => $showLicenses,
			'show_downloads' => $showDownloads,
			'licenses'       => $showLicenses ? array(
				array(
					'product_name' => __( 'Sample product', 'tiermint' ),
					'license_key'  => 'TIERMINT-XXXX-XXXX-XXXX-XXXX',
				),
			) : array(),
			'downloads'      => $showDownloads ? array(
				array(
					'product_name' => __( 'Sample product', 'tiermint' ),
					'files'        => array(
						array(
							'label'        => __( 'Download (.zip)', 'tiermint' ),
							'download_url' => '#',
						),
					),
				),
			) : array(),
		);
	}
}
