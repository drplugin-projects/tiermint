<?php
/**
 * GDPR personal-data exporter for TierMint customers.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Privacy;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Customers\Customer;
use DrPlugin\WP\TierMint\Customers\CustomerRepository;
use DrPlugin\WP\TierMint\Database\LogRepository;
use DrPlugin\WP\TierMint\Licensing\Activation;
use DrPlugin\WP\TierMint\Licensing\ActivationRepository;
use DrPlugin\WP\TierMint\Licensing\License;
use DrPlugin\WP\TierMint\Licensing\LicenseRepository;
use DrPlugin\WP\TierMint\Orders\Order;
use DrPlugin\WP\TierMint\Orders\OrderRepository;
use DrPlugin\WP\TierMint\REST\CheckoutController;

/**
 * Export TierMint personal data for the WordPress exporter.
 *
 * Orders and downloads are paged. Profile, licenses and activations are on page one.
 *
 * @since 1.0.0
 */
class PersonalDataExporter {

	/**
	 * The core metadata type the order-meta table is mapped under.
	 *
	 * @var string
	 */
	private const ORDER_META_TYPE = 'tiermint_order';

	/**
	 * The export group id for the customer profile.
	 *
	 * @var string
	 */
	private const GROUP_CUSTOMER = 'tiermint-customer';

	/**
	 * The export group id for orders.
	 *
	 * @var string
	 */
	private const GROUP_ORDERS = 'tiermint-orders';

	/**
	 * The export group id for licenses.
	 *
	 * @var string
	 */
	private const GROUP_LICENSES = 'tiermint-licenses';

	/**
	 * The export group id for activations.
	 *
	 * @var string
	 */
	private const GROUP_ACTIVATIONS = 'tiermint-activations';

	/**
	 * The export group id for download log rows.
	 *
	 * @var string
	 */
	private const GROUP_DOWNLOADS = 'tiermint-downloads';

	/**
	 * How many orders and download rows to export per page.
	 *
	 * @var int
	 */
	private const PER_PAGE = 100;

	/**
	 * Wire the exporter with the reads it needs.
	 *
	 * @since 1.0.0
	 *
	 * @param CustomerRepository   $customers   Customer profile reads.
	 * @param OrderRepository      $orders      Order reads and the customer's order-id set.
	 * @param LicenseRepository    $licenses    License reads.
	 * @param ActivationRepository $activations Activation reads.
	 * @param LogRepository        $logs        Download log reads.
	 */
	public function __construct(
		private readonly CustomerRepository $customers,
		private readonly OrderRepository $orders,
		private readonly LicenseRepository $licenses,
		private readonly ActivationRepository $activations,
		private readonly LogRepository $logs
	) {
	}

	/**
	 * The friendly name WordPress shows for this exporter in the export report.
	 *
	 * @since 1.0.0
	 *
	 * @return string The translated exporter name.
	 */
	public function friendlyName(): string {
		return __( 'TierMint store data', 'tiermint' );
	}

	/**
	 * Export one page of a customer's TierMint data.
	 *
	 * @since 1.0.0
	 *
	 * @param string $email The email address the request is for.
	 * @param int    $page  The 1-based page number WordPress requests.
	 * @return array{data: array<int, array<string, mixed>>, done: bool} The core exporter response.
	 */
	public function export( string $email, int $page = 1 ): array {
		$page     = max( 1, $page );
		$customer = $this->customers->findByEmail( $email );

		if ( null === $customer || null === $customer->id() ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$customerId = (int) $customer->id();
		$data       = array();

		// The profile and the bounded license/activation sets go out on page one.
		if ( 1 === $page ) {
			$data[] = $this->customerGroup( $customer );

			foreach ( $this->licenses->findByCustomerId( $customerId ) as $license ) {
				$data[] = $this->licenseGroup( $license );

				foreach ( $this->activations->listForLicense( (int) $license->id() ) as $activation ) {
					$data[] = $this->activationGroup( (int) $license->id(), $activation );
				}
			}
		}

		// Orders and download rows are the growing streams, so they are paged.
		$orders = $this->orders->query(
			array(
				'customer_id' => $customerId,
				'page'        => $page,
				'per_page'    => self::PER_PAGE,
				'orderby'     => 'id',
				'order'       => 'ASC',
			)
		);

		foreach ( $orders as $order ) {
			$data[] = $this->orderGroup( $order );
		}

		$downloads = $this->logs->findDownloadsForOrders( $this->orders->idsForCustomer( $customerId ), $page, self::PER_PAGE );

		foreach ( $downloads as $row ) {
			$data[] = $this->downloadGroup( $row );
		}

		// Package/update downloads key off the customer's licenses, not orders.
		$licenseIds = array_map(
			static fn ( $license ): int => (int) $license->id(),
			$this->licenses->findByCustomerId( $customerId )
		);

		$packageDownloads = $this->logs->findDownloadsForLicenses( $licenseIds, $page, self::PER_PAGE );

		foreach ( $packageDownloads as $row ) {
			$data[] = $this->downloadGroup( $row );
		}

		// The request is complete once no paged stream filled a page.
		$done = count( $orders ) < self::PER_PAGE
			&& count( $downloads ) < self::PER_PAGE
			&& count( $packageDownloads ) < self::PER_PAGE;

		return array(
			'data' => $data,
			'done' => $done,
		);
	}

	/**
	 * Build the customer-profile export group.
	 *
	 * @since 1.0.0
	 *
	 * @param Customer $customer The customer.
	 * @return array<string, mixed> The export group.
	 */
	private function customerGroup( Customer $customer ): array {
		$items = array(
			array(
				'name'  => __( 'Email', 'tiermint' ),
				'value' => $customer->email(),
			),
		);

		if ( null !== $customer->name() ) {
			$items[] = array(
				'name'  => __( 'Name', 'tiermint' ),
				'value' => $customer->name(),
			);
		}

		if ( null !== $customer->firstName() ) {
			$items[] = array(
				'name'  => __( 'First name', 'tiermint' ),
				'value' => $customer->firstName(),
			);
		}

		if ( null !== $customer->lastName() ) {
			$items[] = array(
				'name'  => __( 'Last name', 'tiermint' ),
				'value' => $customer->lastName(),
			);
		}

		return $this->group(
			self::GROUP_CUSTOMER,
			__( 'TierMint customer profile', 'tiermint' ),
			'tiermint-customer-' . $customer->id(),
			$items
		);
	}

	/**
	 * Build an order export group.
	 *
	 * @since 1.0.0
	 *
	 * @param Order $order The order.
	 * @return array<string, mixed> The export group.
	 */
	private function orderGroup( Order $order ): array {
		$items = array(
			array(
				'name'  => __( 'Order', 'tiermint' ),
				'value' => $order->uuid(),
			),
			array(
				'name'  => __( 'Email', 'tiermint' ),
				'value' => $order->email(),
			),
			array(
				'name'  => __( 'Status', 'tiermint' ),
				'value' => $order->status()->value,
			),
			array(
				'name'  => __( 'Total', 'tiermint' ),
				/* translators: 1: order total in minor units, 2: uppercased currency code. */
				'value' => sprintf( __( '%1$d (%2$s, in minor units)', 'tiermint' ), $order->total(), strtoupper( $order->currency() ) ),
			),
			array(
				'name'  => __( 'Date', 'tiermint' ),
				'value' => (string) $order->createdAt(),
			),
		);

		if ( null !== $order->ipAddress() ) {
			$items[] = array(
				'name'  => __( 'IP address', 'tiermint' ),
				'value' => $order->ipAddress(),
			);
		}

		$items = array_merge( $items, $this->orderMetaItems( (int) $order->id() ) );

		return $this->group(
			self::GROUP_ORDERS,
			__( 'TierMint orders', 'tiermint' ),
			'tiermint-order-' . $order->id(),
			$items
		);
	}

	/**
	 * Read the buyer PII order-meta the checkout collected, as labelled rows.
	 *
	 * The billing address is stored as an array, so it is flattened into one row
	 * per filled part. The consent time is retained through erasure but still
	 * belongs in a portability export.
	 *
	 * @since 1.0.0
	 *
	 * @param int $orderId The order id.
	 * @return array<int, array<string, string>> The name/value rows, possibly empty.
	 */
	private function orderMetaItems( int $orderId ): array {
		$items   = array();
		$address = get_metadata( self::ORDER_META_TYPE, $orderId, CheckoutController::ADDRESS_META, true );

		if ( is_array( $address ) ) {
			$labels = array(
				'address_1' => __( 'Billing address', 'tiermint' ),
				'address_2' => __( 'Billing address (line 2)', 'tiermint' ),
				'city'      => __( 'Billing city', 'tiermint' ),
				'state'     => __( 'Billing state or region', 'tiermint' ),
				'postcode'  => __( 'Billing postcode', 'tiermint' ),
			);

			foreach ( $labels as $key => $label ) {
				$value = isset( $address[ $key ] ) ? trim( (string) $address[ $key ] ) : '';

				if ( '' !== $value ) {
					$items[] = array(
						'name'  => $label,
						'value' => $value,
					);
				}
			}
		}

		$scalarLabels = array(
			CheckoutController::COMPANY_META => __( 'Company', 'tiermint' ),
			CheckoutController::TAX_ID_META  => __( 'Tax ID', 'tiermint' ),
			CheckoutController::TERMS_META   => __( 'Terms accepted', 'tiermint' ),
		);

		foreach ( $scalarLabels as $metaKey => $label ) {
			$value = trim( (string) get_metadata( self::ORDER_META_TYPE, $orderId, $metaKey, true ) );

			if ( '' !== $value ) {
				$items[] = array(
					'name'  => $label,
					'value' => $value,
				);
			}
		}

		return $items;
	}

	/**
	 * Build a license export group.
	 *
	 * @since 1.0.0
	 *
	 * @param License $license The license.
	 * @return array<string, mixed> The export group.
	 */
	private function licenseGroup( License $license ): array {
		$items = array(
			array(
				'name'  => __( 'License key', 'tiermint' ),
				'value' => $license->licenseKey(),
			),
			array(
				'name'  => __( 'Status', 'tiermint' ),
				'value' => $license->status()->value,
			),
			array(
				'name'  => __( 'Expires', 'tiermint' ),
				'value' => null === $license->expiresAt() ? __( 'Never', 'tiermint' ) : $license->expiresAt()->format( 'Y-m-d H:i:s' ),
			),
		);

		return $this->group(
			self::GROUP_LICENSES,
			__( 'TierMint licenses', 'tiermint' ),
			'tiermint-license-' . $license->id(),
			$items
		);
	}

	/**
	 * Build an activation export group.
	 *
	 * @since 1.0.0
	 *
	 * @param int        $licenseId  The owning license id.
	 * @param Activation $activation The activation.
	 * @return array<string, mixed> The export group.
	 */
	private function activationGroup( int $licenseId, Activation $activation ): array {
		$items = array(
			array(
				'name'  => __( 'Site URL', 'tiermint' ),
				'value' => $activation->siteUrl(),
			),
			array(
				'name'  => __( 'Status', 'tiermint' ),
				'value' => $activation->status()->value,
			),
			array(
				'name'  => __( 'Activated', 'tiermint' ),
				'value' => null === $activation->activatedAt() ? '' : $activation->activatedAt()->format( 'Y-m-d H:i:s' ),
			),
		);

		return $this->group(
			self::GROUP_ACTIVATIONS,
			__( 'TierMint license activations', 'tiermint' ),
			'tiermint-activation-' . $licenseId . '-' . $activation->id(),
			$items
		);
	}

	/**
	 * Build a download-log export group.
	 *
	 * @since 1.0.0
	 *
	 * @param object $row A `tiermint_logs` download row.
	 * @return array<string, mixed> The export group.
	 */
	private function downloadGroup( object $row ): array {
		$items = array(
			array(
				'name'  => __( 'Event', 'tiermint' ),
				'value' => (string) $row->message,
			),
			array(
				'name'  => __( 'Date', 'tiermint' ),
				'value' => (string) $row->created_at,
			),
		);

		if ( null !== $row->ip_address && '' !== $row->ip_address ) {
			$items[] = array(
				'name'  => __( 'IP address', 'tiermint' ),
				'value' => (string) $row->ip_address,
			);
		}

		return $this->group(
			self::GROUP_DOWNLOADS,
			__( 'TierMint downloads', 'tiermint' ),
			'tiermint-download-' . (int) $row->id,
			$items
		);
	}

	/**
	 * Assemble one export group in core's response shape.
	 *
	 * @since 1.0.0
	 *
	 * @param string                            $groupId The stable group id.
	 * @param string                            $label   The translated group label.
	 * @param string                            $itemId  The unique item id within the group.
	 * @param array<int, array<string, string>> $items   The name/value data pairs.
	 * @return array<string, mixed> The export group.
	 */
	private function group( string $groupId, string $label, string $itemId, array $items ): array {
		return array(
			'group_id'    => $groupId,
			'group_label' => $label,
			'item_id'     => $itemId,
			'data'        => $items,
		);
	}
}
