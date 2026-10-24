<?php
/**
 * Shared assembly of a customer's downloadable files and available updates.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Updates;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DateTimeImmutable;
use DateTimeZone;
use DrPlugin\WP\TierMint\Customers\Customer;
use DrPlugin\WP\TierMint\Licensing\License;
use DrPlugin\WP\TierMint\Licensing\LicenseService;
use DrPlugin\WP\TierMint\Licensing\LicenseStatus;
use DrPlugin\WP\TierMint\Orders\Order;
use DrPlugin\WP\TierMint\Orders\OrderRepository;
use DrPlugin\WP\TierMint\Orders\OrderStatus;
use DrPlugin\WP\TierMint\Orders\OrderType;
use DrPlugin\WP\TierMint\Products\Product;
use DrPlugin\WP\TierMint\Products\ProductRepository;

/**
 * Builds customer downloads and update entries for REST and the dashboard.
 * Signs fresh URLs without writing data.
 *
 * @since 1.0.0
 */
class CustomerDownloads {

	/**
	 * Orders in each purchased-file page.
	 *
	 * @var int
	 */
	private const ORDERS_PER_PAGE = 100;

	/**
	 * Order reads for the purchased-file scan.
	 *
	 * @var OrderRepository
	 */
	private OrderRepository $orders;

	/**
	 * Product reader.
	 *
	 * @var ProductRepository
	 */
	private ProductRepository $products;

	/**
	 * License reader.
	 *
	 * @var LicenseService
	 */
	private LicenseService $licenses;

	/**
	 * Release reader.
	 *
	 * @var ReleaseRepository
	 */
	private ReleaseRepository $releases;

	/**
	 * Download URL signer.
	 *
	 * @var PackageSigner
	 */
	private PackageSigner $signer;

	/**
	 * Sets readers and the URL signer.
	 *
	 * @since 1.0.0
	 *
	 * @param OrderRepository   $orders   Order reads for the purchased-file scan.
	 * @param ProductRepository $products Product reads (files, bundle expansion, names).
	 * @param LicenseService    $licenses Licensing service (listByCustomer read).
	 * @param ReleaseRepository $releases Release reads (latest published per product).
	 * @param PackageSigner     $signer   Signed download URL minting.
	 */
	public function __construct(
		OrderRepository $orders,
		ProductRepository $products,
		LicenseService $licenses,
		ReleaseRepository $releases,
		PackageSigner $signer
	) {
		$this->orders   = $orders;
		$this->products = $products;
		$this->licenses = $licenses;
		$this->releases = $releases;
		$this->signer   = $signer;
	}

	/**
	 * Lists delivered files from one customer order page.
	 *
	 * @since 1.0.0
	 *
	 * @param Customer $customer The customer whose files to list.
	 * @param int      $page     The 1-based history page (100 orders per page).
	 * @param int|null $ttl      Per-URL lifetime override in seconds; null keeps the signer's
	 *                           default. The dashboard passes a longer window than the REST list.
	 * @return array<int, array<string, mixed>> Entries shaped
	 *         `{order_id, order_item_id, product_id, product_name, files:[{label, download_url}]}`.
	 */
	public function purchasedFileEntries( Customer $customer, int $page = 1, ?int $ttl = null ): array {
		$customerId = (int) $customer->id();

		if ( $customerId <= 0 ) {
			return array();
		}

		$orders = $this->orders->query(
			array(
				'customer_id' => $customerId,
				'per_page'    => self::ORDERS_PER_PAGE,
				'page'        => max( 1, $page ),
			)
		);

		$entries = array();

		foreach ( $orders as $order ) {
			// Refund ledger rows do not deliver files.
			if ( OrderType::Refund === $order->type() || ! $this->orderDelivers( $order ) ) {
				continue;
			}

			$entries = array_merge( $entries, $this->orderEntries( $order, $customerId, $ttl ) );
		}

		return $entries;
	}

	/**
	 * Lists files delivered by one order.
	 *
	 * @since 1.0.0
	 *
	 * @param Order    $order The order to list files for.
	 * @param int|null $ttl   Per-URL lifetime override in seconds; null keeps the signer's default.
	 * @return array<int, array<string, mixed>> Entries shaped
	 *         `{order_id, order_item_id, product_id, product_name, files:[{label, download_url}]}`.
	 */
	public function entriesForOrder( Order $order, ?int $ttl = null ): array {
		$customerId = (int) $order->customerId();

		if ( $customerId <= 0 ) {
			return array();
		}

		// Use the same delivery rule as order-history downloads.
		if ( OrderType::Refund === $order->type() || ! $this->orderDelivers( $order ) ) {
			return array();
		}

		return $this->orderEntries( $order, $customerId, $ttl );
	}

	/**
	 * Lists updates for currently valid licenses.
	 *
	 * @since 1.0.0
	 *
	 * @param Customer $customer The customer whose updates to list.
	 * @param int|null $ttl      Per-URL lifetime override in seconds; null keeps the signer's default.
	 * @return array<int, array<string, mixed>> Entries shaped
	 *         `{product_id, product_name, version, download_url}`.
	 */
	public function updateEntries( Customer $customer, ?int $ttl = null ): array {
		$customerId = (int) $customer->id();

		if ( $customerId <= 0 ) {
			return array();
		}

		$now     = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$entries = array();

		foreach ( $this->licenses->listByCustomer( $customerId ) as $license ) {
			if ( null === $license->id() || ! $this->isCurrentlyValid( $license, $now ) ) {
				continue;
			}

			$release = $this->releases->latestPublished( $license->productId() );

			if ( null === $release || null === $release->id() ) {
				continue;
			}

			$product = $this->products->find( $license->productId() );

			if ( null === $product ) {
				continue;
			}

			$entries[] = array(
				'product_id'   => (int) $product->id(),
				'product_name' => $product->name(),
				'version'      => $release->version(),
				'download_url' => $this->signer->packageDownloadUrl(
					$license->productId(),
					(int) $release->id(),
					(int) $license->id(),
					'',
					null,
					$ttl
				),
			);
		}

		return $entries;
	}

	/**
	 * Checks a license without triggering lazy expiry.
	 *
	 * @param License           $license The license to judge.
	 * @param DateTimeImmutable $now     The instant to judge validity at.
	 * @return bool True when the license is currently valid.
	 */
	private function isCurrentlyValid( License $license, DateTimeImmutable $now ): bool {
		return LicenseStatus::Active === $license->status() && ! $license->isExpiredAt( $now );
	}

	/**
	 * Checks whether an order can deliver files.
	 *
	 * @param Order $order The order.
	 * @return bool True when files may be listed.
	 */
	private function orderDelivers( Order $order ): bool {
		return in_array( $order->status(), array( OrderStatus::Completed, OrderStatus::PartiallyRefunded ), true );
	}

	/**
	 * Builds file entries for one delivered order.
	 *
	 * @param Order    $order      The delivering order.
	 * @param int      $customerId The owning customer id (bound into each URL).
	 * @param int|null $ttl        Per-URL lifetime override in seconds, or null for the default.
	 * @return array<int, array<string, mixed>> One entry per order item that has files.
	 */
	private function orderEntries( Order $order, int $customerId, ?int $ttl ): array {
		$entries = array();

		foreach ( $order->items() as $item ) {
			foreach ( $this->deliverableProducts( $item->productId() ) as $product ) {
				if ( array() === $product->files() ) {
					continue;
				}

				$files = array();

				foreach ( $product->files() as $file ) {
					$label   = (string) ( $file['label'] ?? '' );
					$files[] = array(
						'label'        => '' !== $label ? $label : basename( (string) $file['key'] ),
						'download_url' => $this->signer->orderFileDownloadUrl(
							(int) $order->id(),
							(int) $item->id(),
							$customerId,
							$this->fileToken( $file ),
							null,
							$ttl
						),
					);
				}

				$entries[] = array(
					'order_id'      => (int) $order->id(),
					'order_item_id' => (int) $item->id(),
					'product_id'    => (int) $product->id(),
					'product_name'  => $product->name(),
					'files'         => $files,
				);
			}
		}

		return $entries;
	}

	/**
	 * Expands bundle items into child products.
	 *
	 * @param int $productId The order item's product id.
	 * @return array<int, Product> The file-carrying candidate products.
	 */
	private function deliverableProducts( int $productId ): array {
		$product = $this->products->find( $productId );

		if ( null === $product ) {
			return array();
		}

		if ( ! $product->isBundle() ) {
			return array( $product );
		}

		$children = array();

		foreach ( $product->bundleItems() as $bundleItem ) {
			$child = $this->products->find( $bundleItem->childProductId() );

			if ( null !== $child ) {
				$children[] = $child;
			}
		}

		return $children;
	}

	/**
	 * Creates a token for the exact stored file.
	 *
	 * @param array<string, mixed> $file The product file reference.
	 * @return string The 64-hex token.
	 */
	private function fileToken( array $file ): string {
		return hash( 'sha256', (string) ( $file['provider'] ?? '' ) . '|' . (string) ( $file['key'] ?? '' ) );
	}
}
