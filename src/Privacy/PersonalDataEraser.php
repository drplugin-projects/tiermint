<?php
/**
 * GDPR personal-data eraser for TierMint customers.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Privacy;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Customers\CustomerRepository;
use DrPlugin\WP\TierMint\Database\LogRepository;
use DrPlugin\WP\TierMint\Licensing\ActivationRepository;
use DrPlugin\WP\TierMint\Licensing\LicenseRepository;
use DrPlugin\WP\TierMint\Licensing\LicenseService;
use DrPlugin\WP\TierMint\Licensing\LicenseStatus;
use DrPlugin\WP\TierMint\Orders\OrderRepository;
use DrPlugin\WP\TierMint\Payments\GatewayEventRepository;
use DrPlugin\WP\TierMint\REST\CheckoutController;

/**
 * Anonymize TierMint data for the WordPress eraser.
 *
 * Orders stay for accounting. The final page anonymizes the customer after all lookups.
 *
 * @since 1.0.0
 */
class PersonalDataEraser {

	/**
	 * How many orders to anonymize per eraser call.
	 *
	 * @var int
	 */
	private const PER_PAGE = 100;

	/**
	 * The core metadata type the order-meta table is mapped under.
	 *
	 * @var string
	 */
	private const ORDER_META_TYPE = 'tiermint_order';

	/**
	 * Order-meta keys that carry buyer PII and are deleted on erasure.
	 *
	 * The closed-world classification is enforced by a reflection test: every
	 * {@see CheckoutController} `*_META` constant must sit in exactly one of this
	 * list or {@see self::ORDER_META_RETAIN}.
	 *
	 * @var array<int, string>
	 */
	public const ORDER_META_ERASE = array(
		CheckoutController::ADDRESS_META,
		CheckoutController::COMPANY_META,
		CheckoutController::TAX_ID_META,
	);

	/**
	 * Order-meta keys kept through erasure as retained records.
	 *
	 * The terms-acceptance time is consent evidence (GDPR Art. 7(1)); with the
	 * order email and IP already anonymized it identifies nobody, so it is
	 * retained and disclosed rather than deleted.
	 *
	 * @var array<int, string>
	 */
	public const ORDER_META_RETAIN = array(
		CheckoutController::TERMS_META,
	);

	/**
	 * Wire the eraser with the reads and writes it needs.
	 *
	 * @since 1.0.0
	 *
	 * @param CustomerRepository     $customers     Customer profile reads and anonymization.
	 * @param OrderRepository        $orders        Order reads and anonymization.
	 * @param LicenseRepository      $licenses      License reads.
	 * @param ActivationRepository   $activations   Activation reads and anonymization.
	 * @param LogRepository          $logs          Log scrubbing (download IPs, license audit rows).
	 * @param LicenseService         $licenseSvc    License revocation (disable).
	 * @param GatewayEventRepository $gatewayEvents Gateway webhook payload redaction.
	 */
	public function __construct(
		private readonly CustomerRepository $customers,
		private readonly OrderRepository $orders,
		private readonly LicenseRepository $licenses,
		private readonly ActivationRepository $activations,
		private readonly LogRepository $logs,
		private readonly LicenseService $licenseSvc,
		private readonly GatewayEventRepository $gatewayEvents
	) {
	}

	/**
	 * The friendly name WordPress shows for this eraser in the erasure report.
	 *
	 * @since 1.0.0
	 *
	 * @return string The translated eraser name.
	 */
	public function friendlyName(): string {
		return __( 'TierMint store data', 'tiermint' );
	}

	/**
	 * Erase (anonymize) one page of a customer's TierMint data.
	 *
	 * @since 1.0.0
	 *
	 * @param string $email The email address the request is for.
	 * @param int    $page  The 1-based page number WordPress requests.
	 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool} The core eraser response.
	 */
	public function erase( string $email, int $page = 1 ): array {
		$page     = max( 1, $page );
		$customer = $this->customers->findByEmail( $email );

		if ( null === $customer || null === $customer->id() ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$customerId = (int) $customer->id();
		$anonEmail  = $this->anonymizedEmail( $customerId );
		$removed    = false;
		$retained   = false;
		$messages   = array();

		/*
		 * Preserve customer_id so paged order lookups stay stable.
		 */
		$orders   = $this->orders->query(
			array(
				'customer_id' => $customerId,
				'page'        => $page,
				'per_page'    => $this->perPage(),
				'orderby'     => 'id',
				'order'       => 'ASC',
			)
		);
		$orderIds = array();

		foreach ( $orders as $order ) {
			$orderId    = (int) $order->id();
			$orderIds[] = $orderId;
			$this->orders->anonymize( $orderId, $anonEmail );

			// Drop the PII order-meta the checkout collected (billing address,
			// company name, tax ID). The consent timestamp is retained.
			foreach ( self::ORDER_META_ERASE as $metaKey ) {
				delete_metadata( self::ORDER_META_TYPE, $orderId, $metaKey );
			}

			$removed  = true;
			$retained = true;
		}

		// This page's order-keyed download rows lose their IPs alongside.
		$this->logs->anonymizeDownloadIpsForOrders( $orderIds );

		// A full page means more orders may follow; report progress and stop.
		if ( count( $orders ) >= $this->perPage() ) {
			return array(
				'items_removed'  => $removed,
				'items_retained' => $retained,
				'messages'       => array(),
				'done'           => false,
			);
		}

		/*
		 * Keep the profile until all related records are processed.
		 */
		$licenseIds = array();

		foreach ( $this->licenses->findByCustomerId( $customerId ) as $license ) {
			$licenseIds[] = (int) $license->id();

			foreach ( $this->activations->listForLicense( (int) $license->id() ) as $activation ) {
				$this->activations->anonymize( (int) $activation->id() );
				$removed = true;
			}

			if ( LicenseStatus::Disabled !== $license->status() ) {
				$this->licenseSvc->disable( $license, 'gdpr-erasure' );
				$removed = true;
			}
		}

		if ( array() !== $licenseIds ) {
			// Remove personal data from package and license audit records.
			$this->logs->anonymizeDownloadIpsForLicenses( $licenseIds );
			$this->logs->anonymizeLicenseLogsForLicenses( $licenseIds );
			$removed  = true;
			$retained = true;
		}

		// Gateway payloads can contain the buyer email.
		$this->gatewayEvents->redactPayloadsContaining( $email );

		// Finally anonymize the profile itself.
		$this->customers->anonymize( $customerId, $anonEmail );
		$removed = true;

		$messages[] = __( 'TierMint kept your order records for tax and accounting reasons, but removed the personal data from them (email address, IP address, billing address, company name and tax ID).', 'tiermint' );
		$messages[] = __( 'TierMint kept your license and download records for audit purposes, with the licenses revoked and all personal data (website addresses and IP addresses) removed.', 'tiermint' );
		$messages[] = __( 'Payment notification records were kept for bookkeeping, with their personal data redacted. Your payment provider (for example Stripe) holds its own records under its own privacy policy.', 'tiermint' );
		$messages[] = __( 'If you accepted the store terms at checkout, TierMint kept the date of that acceptance as a record of your consent. With your other personal data removed, this date no longer identifies you.', 'tiermint' );

		return array(
			'items_removed'  => $removed,
			'items_retained' => true,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/**
	 * Order page size. Tests can override it.
	 *
	 * @since 1.0.0
	 *
	 * @return int The page size.
	 */
	protected function perPage(): int {
		return self::PER_PAGE;
	}

	/**
	 * Build a unique anonymized customer email.
	 *
	 * @since 1.0.0
	 *
	 * @param int $customerId The customer id.
	 * @return string The anonymized email.
	 */
	private function anonymizedEmail( int $customerId ): string {
		return 'deleted-' . $customerId . '@site.invalid';
	}
}
