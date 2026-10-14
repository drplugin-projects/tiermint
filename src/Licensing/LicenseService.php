<?php
/**
 * License domain service: generation, lifecycle, and refund policy.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Licensing;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DateTimeImmutable;
use DateTimeZone;
use DrPlugin\WP\TierMint\Database\AdvisoryLock;
use DrPlugin\WP\TierMint\Database\Logger;
use DrPlugin\WP\TierMint\Orders\Order;
use DrPlugin\WP\TierMint\Orders\OrderItem;
use DrPlugin\WP\TierMint\Orders\OrderStatus;
use DrPlugin\WP\TierMint\Orders\OrderType;
use DrPlugin\WP\TierMint\Products\Product;
use DrPlugin\WP\TierMint\Products\ProductPrice;
use DrPlugin\WP\TierMint\Products\ProductService;
use DrPlugin\WP\TierMint\Products\ProductType;
use Throwable;

/**
 * Applies license purchase and lifecycle rules.
 *
 * Guarded transitions fire hooks once. Minting retries only missing licenses.
 *
 * @since 1.0.0
 */
class LicenseService {

	/**
	 * Meta key remembering a disabled license's prior status.
	 *
	 * @var string
	 */
	public const META_PRE_DISABLE = '_tiermint_pre_disable_status';

	/**
	 * Meta key linking a bundle child license to the bundle product that granted it.
	 *
	 * @var string
	 */
	public const META_BUNDLE_PRODUCT = '_tiermint_bundle_product_id';

	/**
	 * How many fresh keys to try when the astronomically unlikely collision hits.
	 *
	 * @var int
	 */
	private const KEY_RETRIES = 5;

	/**
	 * Batch size for the expiry sweep.
	 *
	 * @var int
	 */
	private const EXPIRY_BATCH = 200;

	/**
	 * Wire the service with its collaborators.
	 *
	 * @since 1.0.0
	 *
	 * @param LicenseRepository   $licenses The license repository.
	 * @param ProductService      $products The product catalog (licensing config, bundles).
	 * @param KeyGenerator        $keys     The random key generator.
	 * @param LicenseStateMachine $machine  The pure lifecycle transition guard.
	 * @param LastKnownGood       $lkg      The last-known-good validation cache to invalidate on state changes.
	 * @param AdvisoryLock        $mintLock The per-order lock serializing count-and-mint.
	 * @param Logger              $logger   The `_logs` audit writer.
	 */
	public function __construct(
		private readonly LicenseRepository $licenses,
		private readonly ProductService $products,
		private readonly KeyGenerator $keys,
		private readonly LicenseStateMachine $machine,
		private readonly LastKnownGood $lkg,
		private readonly AdvisoryLock $mintLock,
		private readonly Logger $logger
	) {
	}

	/**
	 * Load a license by id.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The license id.
	 * @return License|null The license, or null when it does not exist.
	 */
	public function get( int $id ): ?License {
		return $this->licenses->find( $id );
	}

	/**
	 * Load a license by its key (the cache-backed hot-path lookup).
	 *
	 * @since 1.0.0
	 *
	 * @param string $licenseKey The license key.
	 * @return License|null The license, or null when no license carries the key.
	 */
	public function findByKey( string $licenseKey ): ?License {
		return $this->licenses->findByKey( $licenseKey );
	}

	/**
	 * Load a customer's licenses, newest first.
	 *
	 * Anonymous visitors return an empty list.
	 *
	 * @since 1.0.0
	 *
	 * @param int $customerId The customer id.
	 * @return array<int, License> The customer's licenses, newest first.
	 */
	public function listByCustomer( int $customerId ): array {
		if ( $customerId <= 0 ) {
			return array();
		}

		return $this->licenses->findByCustomerId( $customerId );
	}

	/**
	 * Load an order's licenses, oldest first.
	 *
	 * A non-positive id returns an empty list.
	 *
	 * @since 1.0.0
	 *
	 * @param int $orderId The order id.
	 * @return array<int, License> The order's licenses, oldest first.
	 */
	public function listByOrder( int $orderId ): array {
		if ( $orderId <= 0 ) {
			return array();
		}

		return $this->licenses->findByOrderId( $orderId );
	}

	/**
	 * Mint licenses for a completed purchase.
	 *
	 * Existing rows fill the shortfall on retries. Bundles mint a license per child product.
	 *
	 * @since 1.0.0
	 *
	 * @param Order                  $order The completed purchase order.
	 * @param DateTimeImmutable|null $now   The minting instant (tests inject; null means now).
	 * @return array<int, License> The licenses minted by this call (empty on a full re-run).
	 *
	 * @throws LicensePersistenceException When a license write fails for a reason
	 *                                     other than a retried key collision.
	 */
	public function mintForOrder( Order $order, ?DateTimeImmutable $now = null ): array {
		if ( OrderType::Purchase !== $order->type() || null === $order->id() ) {
			return array();
		}

		$now = $now ?? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );

		// The idempotency check is a count with no unique constraint behind it
		// (quantity > 1 legitimately makes several rows per item/product), so
		// two overlapping runs (WP-Cron can fire the same retry event in two
		// concurrent cron processes) could both count 0 and both mint. The
		// per-order advisory lock serializes count-and-mint so the second run
		// sees the first's rows and mints nothing.
		return $this->mintLock->withLock(
			(int) $order->id(),
			fn (): array => $this->doMintForOrder( $order, $now )
		);
	}

	/**
	 * Mint while the caller holds the per-order lock.
	 *
	 * @since 1.0.0
	 *
	 * @param Order             $order The completed purchase order.
	 * @param DateTimeImmutable $now   The minting instant.
	 * @return array<int, License> The licenses minted by this call.
	 */
	private function doMintForOrder( Order $order, DateTimeImmutable $now ): array {
		$existing = $this->countExistingPerItemAndProduct( (int) $order->id() );
		$minted   = array();

		foreach ( $order->items() as $item ) {
			$product = $this->products->get( $item->productId() );

			if ( null === $product ) {
				$this->logger->log(
					'license',
					sprintf( 'Skipped minting for order %d item %d: product %d no longer exists.', $order->id(), (int) $item->id(), $item->productId() ),
					array( 'order_id' => (int) $order->id() ),
					'order',
					(int) $order->id()
				);

				continue;
			}

			if ( ProductType::Bundle === $product->type() ) {
				$minted = array_merge( $minted, $this->mintBundleItem( $order, $item, $product, $existing, $now ) );

				continue;
			}

			if ( ! $product->isLicensed() ) {
				continue;
			}

			$price  = $this->resolvePrice( $product, $item->priceId() );
			$minted = array_merge(
				$minted,
				$this->mintShortfall( $order, $item, $product, $price, $existing, $now, null )
			);
		}

		return $minted;
	}

	/**
	 * Disable a license and remember its prior status.
	 *
	 * @since 1.0.0
	 *
	 * @param License $license The license to disable.
	 * @param string  $reason  Why, for the audit trail: 'admin' or 'refund'.
	 * @return License The reloaded, disabled license.
	 *
	 * @throws LicenseTransitionException When the license could not be disabled and
	 *                                    is not disabled now (a truly conflicting state).
	 */
	public function disable( License $license, string $reason = 'admin' ): License {
		if ( LicenseStatus::Disabled === $license->status() ) {
			return $license;
		}

		$won = $this->licenses->transitionStatus( $license, $this->machine->sourcesFor( LicenseStatus::Disabled ), LicenseStatus::Disabled );

		if ( ! $won ) {
			// A concurrent writer changed the row. If it ended up disabled the
			// intent is satisfied; anything else is a real conflict.
			$fresh = $this->reload( $license );

			if ( LicenseStatus::Disabled === $fresh->status() ) {
				return $fresh;
			}

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured domain message, surfaced as an API error, never echoed as HTML.
			throw new LicenseTransitionException( __( 'The license could not be disabled because it changed state concurrently.', 'tiermint' ) );
		}

		// Remember what the license was, so enable() can restore expired as
		// expired instead of granting time back. The entity's status is what
		// the caller read a moment ago; a racing lazy expiry flip could make it
		// stale by one step, which self-heals on the next validation.
		update_metadata( 'tiermint_license', (int) $license->id(), self::META_PRE_DISABLE, $license->status()->value );

		$this->lkg->forget( $license->licenseKey() );

		$fresh = $this->reload( $license );

		/**
		 * Fires after a license was disabled (admin action or full refund).
		 *
		 * @since 1.0.0
		 *
		 * @param License $fresh The disabled license.
		 */
		do_action( 'tiermint_license_disabled', $fresh );

		$this->logger->log(
			'license',
			sprintf( 'License %d disabled (%s).', $fresh->id(), $reason ),
			array( 'reason' => $reason ),
			'license',
			(int) $fresh->id()
		);

		return $fresh;
	}

	/**
	 * Re-enable a license with its saved prior status.
	 *
	 * Expired licenses stay expired.
	 *
	 * @since 1.0.0
	 *
	 * @param License                $license The disabled license.
	 * @param DateTimeImmutable|null $now     The instant for the staleness correction (tests inject).
	 * @return License The reloaded license in its restored (possibly corrected) status.
	 */
	public function enable( License $license, ?DateTimeImmutable $now = null ): License {
		if ( LicenseStatus::Disabled !== $license->status() ) {
			return $license;
		}

		$remembered = get_metadata( 'tiermint_license', (int) $license->id(), self::META_PRE_DISABLE, true );
		$restoreTo  = LicenseStatus::tryFrom( (string) $remembered ) ?? LicenseStatus::Active;

		// Only active/expired are restorable states; a corrupt memo falls back
		// to active and the staleness correction below fixes any overreach.
		if ( LicenseStatus::Disabled === $restoreTo ) {
			$restoreTo = LicenseStatus::Active;
		}

		$won = $this->licenses->transitionStatus( $license, array( LicenseStatus::Disabled ), $restoreTo );

		if ( ! $won ) {
			return $this->reload( $license );
		}

		delete_metadata( 'tiermint_license', (int) $license->id(), self::META_PRE_DISABLE );

		$this->lkg->forget( $license->licenseKey() );

		$fresh = $this->reload( $license );

		/**
		 * Fires after a disabled license was re-enabled (its prior status restored).
		 *
		 * @since 1.0.0
		 *
		 * @param License $fresh The re-enabled license.
		 */
		do_action( 'tiermint_license_enabled', $fresh );

		$this->logger->log(
			'license',
			sprintf( 'License %d re-enabled to %s.', $fresh->id(), $fresh->status()->value ),
			array(),
			'license',
			(int) $fresh->id()
		);

		if ( LicenseStatus::Active === $fresh->status() ) {
			$fresh = $this->expireLazily( $fresh, $now );
		}

		return $fresh;
	}

	/**
	 * Renew from the later of now or the current expiry.
	 *
	 * Renewal reactivates expired licenses but not disabled ones.
	 *
	 * @since 1.0.0
	 *
	 * @param License                $license The license to renew.
	 * @param string|null            $length  A strtotime-compatible relative length
	 *                                        (e.g. '+1 year'), or null to make it lifetime.
	 * @param Order|null             $order   The renewal order driving this, when there is one.
	 * @param DateTimeImmutable|null $now     The renewal instant (tests inject).
	 * @return License The reloaded, renewed license. A database fault propagates as
	 *                 LicensePersistenceException from the repository.
	 *
	 * @throws LicenseTransitionException When the license is disabled (or vanished) and
	 *                                    the renewal did not land.
	 */
	public function renew( License $license, ?string $length, ?Order $order = null, ?DateTimeImmutable $now = null ): License {
		$now = $now ?? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );

		$newExpiresAt = null;

		if ( null !== $length ) {
			// Early renewals extend from the old expiry, not from today, so a
			// customer renewing a month early still gets a full added period.
			$base    = ( null !== $license->expiresAt() && $license->expiresAt() > $now ) ? $license->expiresAt() : $now;
			$instant = $this->applyLength( $base, $length );

			// An unparseable length is a seller configuration error; failing the
			// renewal would punish the customer, so fall back to lifetime and log.
			if ( null === $instant ) {
				$this->logger->log(
					'error',
					sprintf( 'Unparseable license length "%s" while renewing license %d; granted lifetime.', $length, (int) $license->id() ),
					array(),
					'license',
					(int) $license->id()
				);
			}

			$newExpiresAt = null === $instant ? null : $instant->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		}

		$won = $this->licenses->renew( $license, $newExpiresAt );

		if ( ! $won ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured domain message, surfaced as an API error, never echoed as HTML.
			throw new LicenseTransitionException( __( 'A disabled license cannot be renewed. Re-enable it first.', 'tiermint' ) );
		}

		$this->lkg->forget( $license->licenseKey() );

		$fresh = $this->reload( $license );

		/**
		 * Fires after a license's expiry was extended by a renewal.
		 *
		 * @since 1.0.0
		 *
		 * @param License    $fresh The renewed license.
		 * @param Order|null $order The renewal order, when one drove the renewal.
		 */
		do_action( 'tiermint_license_renewed', $fresh, $order );

		$this->logger->log(
			'license',
			sprintf( 'License %d renewed until %s.', $fresh->id(), null === $fresh->expiresAt() ? 'lifetime' : $fresh->expiresAt()->format( 'Y-m-d H:i:s' ) ),
			array( 'order_id' => null !== $order ? (int) $order->id() : null ),
			'license',
			(int) $fresh->id()
		);

		return $fresh;
	}

	/**
	 * Renew a license to an exact expiry time.
	 *
	 * Subscription renewals follow the gateway period end without drift.
	 *
	 * @since 1.0.0
	 *
	 * @param License           $license   The license to renew.
	 * @param DateTimeImmutable $expiresAt The new absolute expiry (any timezone; stored as UTC).
	 * @param Order|null        $order     The renewal order driving this, when there is one.
	 * @return License The reloaded, renewed license. A database fault propagates as
	 *                 LicensePersistenceException from the repository.
	 *
	 * @throws LicenseTransitionException When the license is disabled (or vanished) and
	 *                                    the renewal did not land.
	 */
	public function renewUntil( License $license, DateTimeImmutable $expiresAt, ?Order $order = null ): License {
		$newExpiresAt = $expiresAt->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );

		$won = $this->licenses->renew( $license, $newExpiresAt );

		if ( ! $won ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured domain message, surfaced as an API error, never echoed as HTML.
			throw new LicenseTransitionException( __( 'A disabled license cannot be renewed. Re-enable it first.', 'tiermint' ) );
		}

		$this->lkg->forget( $license->licenseKey() );

		$fresh = $this->reload( $license );

		/** This action is documented in {@see renew()}. */
		do_action( 'tiermint_license_renewed', $fresh, $order );

		$this->logger->log(
			'license',
			sprintf( 'License %d renewed until %s.', $fresh->id(), null === $fresh->expiresAt() ? 'lifetime' : $fresh->expiresAt()->format( 'Y-m-d H:i:s' ) ),
			array( 'order_id' => null !== $order ? (int) $order->id() : null ),
			'license',
			(int) $fresh->id()
		);

		return $fresh;
	}

	/**
	 * Change a license to another tier of the same product.
	 *
	 * Keep its key and existing activations. New claims use the new cap.
	 *
	 * @since 1.0.0
	 *
	 * @param License      $license The license to re-point.
	 * @param ProductPrice $newTier The tier to move to (must belong to the license's product).
	 * @param Order|null   $order   The order driving the change (a proration invoice), when there is one.
	 * @return License The reloaded license carrying the new tier's price id and cap. A
	 *                 database fault propagates as LicensePersistenceException from the repository.
	 *
	 * @throws LicenseValidationException When the tier does not belong to the license's product.
	 */
	public function changeTier( License $license, ProductPrice $newTier, ?Order $order = null ): License {
		// A license can only move between tiers of its own product; a tier from
		// another product would point the entitlement at an unrelated catalog item.
		if ( $newTier->productId() !== $license->productId() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured domain message, surfaced as an API error, never echoed as HTML.
			throw new LicenseValidationException( __( 'A license can only change to a price tier of its own product.', 'tiermint' ) );
		}

		$this->licenses->changeTier( $license, $newTier->id(), $newTier->activationLimit() );

		$this->lkg->forget( $license->licenseKey() );

		$fresh = $this->reload( $license );

		/**
 * Fires after a license changes price tier.
		 *
		 * @since 1.0.0
		 *
		 * @param License      $fresh   The re-snapshotted license.
		 * @param ProductPrice $newTier The tier the license now carries.
		 * @param Order|null   $order   The order driving the change, when one drove it.
		 */
		do_action( 'tiermint_license_tier_changed', $fresh, $newTier, $order );

		$this->logger->log(
			'license',
			sprintf( 'License %d tier changed to price %s.', $fresh->id(), null === $newTier->id() ? 'none' : (string) $newTier->id() ),
			array( 'order_id' => null !== $order ? (int) $order->id() : null ),
			'license',
			(int) $fresh->id()
		);

		return $fresh;
	}

	/**
	 * Expire active licenses whose effective expiry has passed.
	 *
	 * Compare-and-swap avoids duplicate hooks. {@see expireLazily()} applies filtered grace
	 * periods.
	 *
	 * @since 1.0.0
	 *
	 * @param DateTimeImmutable|null $now The sweep instant (tests inject).
	 * @return int How many licenses this run expired.
	 */
	public function expireDueLicenses( ?DateTimeImmutable $now = null ): int {
		$now     = $now ?? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$due     = $this->licenses->findDueForExpiry( $now->format( 'Y-m-d H:i:s' ), self::EXPIRY_BATCH );
		$expired = 0;

		foreach ( $due as $license ) {
			if ( LicenseStatus::Expired === $this->expireLazily( $license, $now )->status() ) {
				++$expired;
			}
		}

		return $expired;
	}

	/**
	 * Expire an active license when its filtered expiry has passed.
	 *
	 * @since 1.0.0
	 *
	 * @param License                $license The license to check.
	 * @param DateTimeImmutable|null $now     The instant to compare against (tests inject).
	 * @return License The license, flipped to expired when it was due (reloaded), or as given.
	 */
	public function expireLazily( License $license, ?DateTimeImmutable $now = null ): License {
		if ( LicenseStatus::Active !== $license->status() ) {
			return $license;
		}

		$now = $now ?? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );

		/**
 * Filter the expiry date used by license checks.
		 *
		 * @since 1.0.0
		 *
		 * @param DateTimeImmutable|null $expiresAt The stored expiry, or null for lifetime.
		 * @param License                $license   The license being evaluated.
		 */
		$effective = apply_filters( 'tiermint_license_expires_at', $license->expiresAt(), $license );

		if ( ! $effective instanceof DateTimeImmutable ) {
			return $license;
		}

		/**
 * Filter post-expiry validation grace in days.
		 *
		 * @since 1.0.0
		 *
		 * @param int     $days    The grace window in days.
		 * @param License $license The license being evaluated.
		 */
		$graceDays = (int) apply_filters( 'tiermint_expiry_grace_days', 0, $license );

		$cutoff = $graceDays > 0 ? $effective->modify( sprintf( '+%d days', $graceDays ) ) : $effective;

		if ( $cutoff >= $now ) {
			return $license;
		}

		$fresh = $this->flipToExpired( $license );

		return $fresh ?? $this->reload( $license );
	}

	/**
	 * Disable licenses after a full refund when policy allows it.
	 *
	 * @since 1.0.0
	 *
	 * @param Order $order          The refunded purchase order (already advanced in status).
	 * @param int   $amountRefunded The amount refunded in this refund, minor units.
	 * @return void
	 */
	public function onOrderRefunded( Order $order, int $amountRefunded ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The signature mirrors the tiermint_order_refunded hook; the policy keys off the order's status, not the amount.
		if ( OrderStatus::Refunded !== $order->status() || null === $order->id() ) {
			return;
		}

		/**
		 * Filters whether a full refund disables the order's licenses.
		 *
		 * @since 1.0.0
		 *
		 * @param bool  $disables Default true.
		 * @param Order $order    The fully refunded order.
		 */
		if ( ! apply_filters( 'tiermint_refund_disables_license', true, $order ) ) {
			return;
		}

		foreach ( $this->licenses->findByOrderId( (int) $order->id() ) as $license ) {
			if ( LicenseStatus::Disabled === $license->status() ) {
				continue;
			}

			$this->disable( $license, 'refund' );
		}
	}

	/**
	 * Expire a license when this caller wins the guarded update.
	 *
	 * @since 1.0.0
	 *
	 * @param License $license The active license to expire.
	 * @return License|null The reloaded expired license when this call flipped it, or null.
	 */
	private function flipToExpired( License $license ): ?License {
		$won = $this->licenses->transitionStatus( $license, array( LicenseStatus::Active ), LicenseStatus::Expired );

		if ( ! $won ) {
			return null;
		}

		$this->lkg->forget( $license->licenseKey() );

		$fresh = $this->reload( $license );

		/**
		 * Fires after a license's expiry date passed and its status flipped to expired.
		 *
		 * @since 1.0.0
		 *
		 * @param License $fresh The expired license.
		 */
		do_action( 'tiermint_license_expired', $fresh );

		$this->logger->log(
			'license',
			sprintf( 'License %d expired.', $fresh->id() ),
			array(),
			'license',
			(int) $fresh->id()
		);

		return $fresh;
	}

	/**
	 * Mint bundle-child licenses for an order item.
	 *
	 * @since 1.0.0
	 *
	 * @param Order              $order    The purchase order.
	 * @param OrderItem          $item     The bundle line item.
	 * @param Product            $bundle   The bundle product.
	 * @param array<string, int> $existing Existing license counts, mutated as minting proceeds.
	 * @param DateTimeImmutable  $now      The minting instant.
	 * @return array<int, License> The licenses minted for this item.
	 */
	private function mintBundleItem( Order $order, OrderItem $item, Product $bundle, array &$existing, DateTimeImmutable $now ): array {
		$minted = array();

		foreach ( $bundle->bundleItems() as $bundleItem ) {
			$child = $this->products->get( $bundleItem->childProductId() );

			if ( null === $child ) {
				$this->logger->log(
					'license',
					sprintf( 'Skipped minting for order %d: bundle %d child product %d no longer exists.', $order->id(), (int) $bundle->id(), $bundleItem->childProductId() ),
					array( 'order_id' => (int) $order->id() ),
					'order',
					(int) $order->id()
				);

				continue;
			}

			if ( ! $child->isLicensed() ) {
				continue;
			}

			// The bundle grants a specific child tier; NULL means the child's
			// default price decides limit and length.
			$price = $this->resolvePrice( $child, $bundleItem->childPriceId() );

			$minted = array_merge(
				$minted,
				$this->mintShortfall( $order, $item, $child, $price, $existing, $now, (int) $bundle->id() )
			);
		}

		return $minted;
	}

	/**
	 * Mint the missing licenses for one item and product.
	 *
	 * @since 1.0.0
	 *
	 * @param Order              $order           The purchase order.
	 * @param OrderItem          $item            The order line item.
	 * @param Product            $product         The concrete licensed product.
	 * @param ProductPrice|null  $price           The granting price tier, or null.
	 * @param array<string, int> $existing        Existing license counts, mutated as minting proceeds.
	 * @param DateTimeImmutable  $now             The minting instant.
	 * @param int|null           $bundleProductId The granting bundle's id, for lineage meta.
	 * @return array<int, License> The licenses minted by this call.
	 */
	private function mintShortfall( Order $order, OrderItem $item, Product $product, ?ProductPrice $price, array &$existing, DateTimeImmutable $now, ?int $bundleProductId ): array {
		$key    = $this->planKey( (int) $item->id(), (int) $product->id() );
		$have   = $existing[ $key ] ?? 0;
		$needed = max( 0, $item->quantity() - $have );
		$minted = array();

		for ( $i = 0; $i < $needed; $i++ ) {
			$minted[] = $this->mintOne( $order, $item, $product, $price, $now, $bundleProductId );
		}

		$existing[ $key ] = $have + $needed;

		return $minted;
	}

	/**
	 * Mint one license with a unique key.
	 *
	 * @since 1.0.0
	 *
	 * @param Order             $order           The purchase order.
	 * @param OrderItem         $item            The order line item.
	 * @param Product           $product         The concrete licensed product.
	 * @param ProductPrice|null $price           The granting price tier, or null.
	 * @param DateTimeImmutable $now             The minting instant.
	 * @param int|null          $bundleProductId The granting bundle's id, for lineage meta.
	 * @return License The freshly minted license.
	 *
	 * @throws LicensePersistenceException When the write keeps failing (including
	 *                                     exhausted key-collision retries).
	 */
	private function mintOne( Order $order, OrderItem $item, Product $product, ?ProductPrice $price, DateTimeImmutable $now, ?int $bundleProductId ): License {
		$settings = $product->settings();

		// The tier snapshot decides the policy; a tier-less item falls back to
		// the product's settings defaults. A tier whose length is NULL means
		// lifetime by schema, so no settings fallback applies then.
		$limit  = null !== $price ? $price->activationLimit() : $this->intOrNull( $settings['default_activation_limit'] ?? null );
		$length = null !== $price ? $price->resolvedLicenseLength() : $this->stringOrNull( $settings['default_license_length'] ?? null );

		/**
		 * Filters the activation limit a purchase grants (null = unlimited).
		 *
		 * @since 1.0.0
		 *
		 * @param int|null          $limit   The tier's activation limit.
		 * @param Product           $product The licensed product.
		 * @param ProductPrice|null $price   The granting tier, or null.
		 * @param Order             $order   The purchase order.
		 */
		$limit = apply_filters( 'tiermint_activation_limit', $limit, $product, $price, $order );

		/**
 * Filter the license period granted by a purchase.
		 *
		 * @since 1.0.0
		 *
		 * @param string|null       $length  The tier's license length.
		 * @param Product           $product The licensed product.
		 * @param ProductPrice|null $price   The granting tier, or null.
		 * @param Order             $order   The purchase order.
		 */
		$length = apply_filters( 'tiermint_license_length', $length, $product, $price, $order );

		$expiresAt = null;

		if ( null !== $length && '' !== $length ) {
			$expiresAt = $this->applyLength( $now, (string) $length );

			if ( null === $expiresAt ) {
				// A misconfigured length must not block the customer's license;
				// grant lifetime and surface the config problem in the log.
				$this->logger->log(
					'error',
					sprintf( 'Unparseable license length "%s" for product %d; minted lifetime.', (string) $length, (int) $product->id() ),
					array( 'order_id' => (int) $order->id() ),
					'order',
					(int) $order->id()
				);
			}
		}

		$id      = $this->createWithFreshKey(
			(int) $order->id(),
			(int) $order->customerId(),
			(int) $product->id(),
			null !== $price ? $price->id() : null,
			$item->id(),
			$limit,
			$expiresAt,
			null
		);
		$license = $this->licenses->find( $id );

		if ( null === $license ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only literal; controllers map persistence failures to a generic message.
			throw new LicensePersistenceException( sprintf( 'License %d vanished immediately after minting.', $id ) );
		}

		if ( null !== $bundleProductId ) {
			// Lineage: which bundle granted this child license.
			update_metadata( 'tiermint_license', $id, self::META_BUNDLE_PRODUCT, $bundleProductId );
		}

		/**
 * Filter the seats granted by a purchased license.
		 *
		 * @since 1.0.0
		 *
		 * @param int|null          $seatLimit The seat limit, or null for an ordinary license.
		 * @param Product           $product   The licensed product.
		 * @param ProductPrice|null $price     The granting tier, or null.
		 * @param Order             $order     The purchase order.
		 */
		$seatLimit = apply_filters( 'tiermint_license_seat_limit', null, $product, $price, $order );

		if ( is_int( $seatLimit ) && $seatLimit > 0 ) {
			update_metadata( 'tiermint_license', $id, SeatService::META_IS_ORG, 1 );
			update_metadata( 'tiermint_license', $id, SeatService::META_SEAT_LIMIT, $seatLimit );
		}

		/**
		 * Fires after a license was generated for a completed order.
		 *
		 * @since 1.0.0
		 *
		 * @param License $license The freshly minted license.
		 * @param Order   $order   The completed order that earned it.
		 */
		do_action( 'tiermint_license_generated', $license, $order );

		$this->logger->log(
			'license',
			sprintf( 'License %d generated for order %d, product %d.', $id, $order->id(), $product->id() ),
			array(
				'order_id'   => (int) $order->id(),
				'product_id' => (int) $product->id(),
				'bundle_id'  => $bundleProductId,
			),
			'license',
			$id
		);

		return $license;
	}

	/**
	 * Mint a child license for an organization seat.
	 *
	 * The child has its own key and starts with no activations.
	 *
	 * @since 1.0.0
	 *
	 * @param License $org The organization license (its own row, `is_org` meta set).
	 * @return License The freshly minted child license.
	 *
	 * @throws LicensePersistenceException When the write keeps failing (including
	 *                                     exhausted key-collision retries).
	 */
	public function mintChild( License $org ): License {
		$id    = $this->createWithFreshKey(
			$org->orderId(),
			$org->customerId(),
			$org->productId(),
			$org->priceId(),
			$org->orderItemId(),
			$org->activationLimit(),
			$org->expiresAt(),
			(int) $org->id()
		);
		$child = $this->licenses->find( $id );

		if ( null === $child ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only literal; controllers map persistence failures to a generic message.
			throw new LicensePersistenceException( sprintf( 'Child license %d vanished immediately after minting.', $id ) );
		}

		$this->logger->log(
			'license',
			sprintf( 'Child seat license %d minted for org license %d.', $id, (int) $org->id() ),
			array( 'parent_id' => (int) $org->id() ),
			'license',
			$id
		);

		return $child;
	}

	/**
	 * Insert a license with a new key, retrying collisions.
	 *
	 * @since 1.0.0
	 *
	 * @param int                    $orderId         The owning order id.
	 * @param int                    $customerId      The owning customer id.
	 * @param int                    $productId       The concrete product id.
	 * @param int|null               $priceId         The granting tier id, or null.
	 * @param int|null               $orderItemId     The owning order line id, or null.
	 * @param int|null               $activationLimit The effective activation limit.
	 * @param DateTimeImmutable|null $expiresAt       The effective expiry, or null for lifetime.
	 * @param int|null               $parentId        The parent license id (seat lineage), or null.
	 * @return int The new license id.
	 *
	 * @throws LicensePersistenceException When every retry collided (which in practice
	 *                                     means the random source is broken).
	 */
	private function createWithFreshKey( int $orderId, int $customerId, int $productId, ?int $priceId, ?int $orderItemId, ?int $activationLimit, ?DateTimeImmutable $expiresAt, ?int $parentId ): int {
		$lastCollision = null;

		for ( $attempt = 0; $attempt < self::KEY_RETRIES; $attempt++ ) {
			$license = new License(
				null,
				$this->keys->generate(),
				LicenseStatus::Active,
				$productId,
				$priceId,
				$orderId,
				$orderItemId,
				$customerId,
				$activationLimit,
				0,
				$expiresAt,
				$parentId,
				null,
				null
			);

			try {
				return $this->licenses->create( $license );
			} catch ( DuplicateLicenseKeyException $collision ) {
				$lastCollision = $collision;
			}
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only literal; controllers map persistence failures to a generic message.
		throw new LicensePersistenceException( 'Exhausted license key collision retries.', 0, $lastCollision );
	}

	/**
	 * Count existing licenses by order item and product.
	 *
	 * @since 1.0.0
	 *
	 * @param int $orderId The order id.
	 * @return array<string, int> Counts keyed by {@see planKey()}.
	 */
	private function countExistingPerItemAndProduct( int $orderId ): array {
		$counts = array();

		foreach ( $this->licenses->findByOrderId( $orderId ) as $license ) {
			$key            = $this->planKey( (int) $license->orderItemId(), $license->productId() );
			$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
		}

		return $counts;
	}

	/**
	 * The shortfall-count key for an (order item, product) pair.
	 *
	 * @since 1.0.0
	 *
	 * @param int $orderItemId The order item id.
	 * @param int $productId   The concrete product id.
	 * @return string The count key.
	 */
	private function planKey( int $orderItemId, int $productId ): string {
		return $orderItemId . '|' . $productId;
	}

	/**
	 * Resolve the chosen price tier or the product default.
	 *
	 * @since 1.0.0
	 *
	 * @param Product  $product The product.
	 * @param int|null $priceId The requested tier id, or null.
	 * @return ProductPrice|null The tier, or null when the product has none.
	 */
	private function resolvePrice( Product $product, ?int $priceId ): ?ProductPrice {
		if ( null !== $priceId ) {
			foreach ( $product->prices() as $price ) {
				if ( $price->id() === $priceId ) {
					return $price;
				}
			}
		}

		return $product->defaultPrice();
	}

	/**
	 * Apply a relative period to an instant.
	 *
	 * @since 1.0.0
	 *
	 * @param DateTimeImmutable $base   The base instant.
	 * @param string            $length The relative length, e.g. '+1 year'.
	 * @return DateTimeImmutable|null The moved instant, or null on a parse failure.
	 */
	private function applyLength( DateTimeImmutable $base, string $length ): ?DateTimeImmutable {
		// A positive bare integer is a corrupted length from an older build:
		// the admin SPA once wrote a day count into this strtotime-string column, which
		// modify() reads as false (or, for values like "1000"/"20270101", as a
		// same-day time or an absolute date), so an un-repaired row would
		// silently mint lifetime. Interpret it as a day count and flag the row
		// for repair. The check runs before modify() so those misread numerics
		// are caught. "0" is excluded: it is unrecoverable and falls through to
		// the lifetime path, matching the data repair that maps it to NULL.
		if ( 1 === preg_match( '/^[1-9][0-9]*$/', $length ) ) {
			$this->logger->log(
				'error',
				sprintf( 'Interpreted bare-numeric license length "%s" as "+%s day"; the price tier should be repaired.', $length, $length )
			);

			$length = '+' . $length . ' day';
		}

		try {
			$moved = @$base->modify( $length ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Pre-8.3 modify() warns instead of throwing; the false return is handled right below.
		} catch ( Throwable $t ) {
			return null;
		}

		return false === $moved ? null : $moved;
	}

	/**
	 * Reload a license, retaining it when concurrently deleted.
	 *
	 * @since 1.0.0
	 *
	 * @param License $license The license to reload.
	 * @return License The fresh entity, or the given one as a last resort.
	 */
	private function reload( License $license ): License {
		return $this->licenses->find( (int) $license->id() ) ?? $license;
	}

	/**
	 * Cast a settings value to a non-negative int, or null.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value The raw settings value.
	 * @return int|null The int, or null when absent/invalid.
	 */
	private function intOrNull( $value ): ?int {
		return is_numeric( $value ) ? max( 0, (int) $value ) : null;
	}

	/**
	 * Cast a settings value to a non-empty string, or null.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value The raw settings value.
	 * @return string|null The string, or null when absent/empty.
	 */
	private function stringOrNull( $value ): ?string {
		return ( is_string( $value ) && '' !== trim( $value ) ) ? $value : null;
	}
}
