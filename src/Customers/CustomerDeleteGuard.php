<?php
/**
 * TierMint's own reasons a customer cannot be deleted.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Customers;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Core\Hookable;
use DrPlugin\WP\TierMint\Licensing\LicenseRepository;
use DrPlugin\WP\TierMint\Licensing\LicenseStatus;
use DrPlugin\WP\TierMint\Orders\OrderRepository;

/**
 * Prevents deletion while core orders or active licenses exist.
 * Count failures also prevent deletion.
 *
 * @since 1.0.0
 */
final class CustomerDeleteGuard implements Hookable {

	/**
	 * Wire the guard with the two counts it makes.
	 *
	 * @since 1.0.0
	 *
	 * @param OrderRepository   $orders   The order reads.
	 * @param LicenseRepository $licenses The license reads.
	 */
	public function __construct(
		private readonly OrderRepository $orders,
		private readonly LicenseRepository $licenses
	) {
	}

	/**
	 * Register the blocker on the customer-delete filter.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_filter( 'tiermint_customer_delete_blockers', array( $this, 'blockers' ), 10, 2 );
	}

	/**
	 * Add core's reasons for refusing to delete a customer.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string> $reasons    The reasons collected so far.
	 * @param int                $customerId The customer id about to be deleted.
	 * @return array<int, string> The reasons, with core's appended.
	 */
	public function blockers( array $reasons, int $customerId ): array {
		$orders = $this->orders->countForCustomer( $customerId );

		if ( $orders > 0 ) {
			$reasons[] = sprintf(
				/* translators: %d: the number of orders the customer has placed. */
				_n(
					'This customer has %d order. Use Tools then Erase Personal Data to remove their personal details, which keeps the order record for accounting.',
					'This customer has %d orders. Use Tools then Erase Personal Data to remove their personal details, which keeps the order records for accounting.',
					$orders,
					'tiermint'
				),
				$orders
			);
		}

		$live = $this->licenses->countForCustomer( $customerId, LicenseStatus::Disabled );

		if ( $live > 0 ) {
			$reasons[] = sprintf(
				/* translators: %d: the number of licenses the customer holds that are not disabled. */
				_n(
					'This customer still holds %d license that is not disabled.',
					'This customer still holds %d licenses that are not disabled.',
					$live,
					'tiermint'
				),
				$live
			);
		}

		return $reasons;
	}
}
