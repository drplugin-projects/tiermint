<?php
/**
 * Shared per-tier automatic-discount resolution for the tier-selector components.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend\Components\Support;

use DrPlugin\WP\TierMint\Discounts\AutomaticDiscountResolver;
use DrPlugin\WP\TierMint\Discounts\DiscountPersistenceException;
use DrPlugin\WP\TierMint\Discounts\DiscountSettings;
use DrPlugin\WP\TierMint\Products\Product;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves automatic discounts for tier selectors.
 *
 * Failed reads leave tier prices unchanged.
 *
 * @since 1.0.0
 */
trait ResolvesTierDiscounts {

	/**
	 * The automatic-discount resolver, or null in a minimal wiring (tiers then render plain).
	 *
	 * @var AutomaticDiscountResolver|null
	 */
	private ?AutomaticDiscountResolver $tierDiscounts = null;

	/**
	 * The strikethrough display-toggle reader, or null (defaulting on) in a minimal wiring.
	 *
	 * @var DiscountSettings|null
	 */
	private ?DiscountSettings $tierDiscountSettings = null;

	/**
	 * Record the discount collaborators (called from the using component's constructor).
	 *
	 * @param AutomaticDiscountResolver|null $discounts The automatic-discount resolver.
	 * @param DiscountSettings|null          $settings  The strikethrough display-toggle reader.
	 * @return void
	 */
	protected function setTierDiscountDeps( ?AutomaticDiscountResolver $discounts, ?DiscountSettings $settings ): void {
		$this->tierDiscounts        = $discounts;
		$this->tierDiscountSettings = $settings;
	}

	/**
	 * Whether price strikes are enabled.
	 *
	 * @return bool
	 */
	protected function tierStrikeEnabled(): bool {
		return null === $this->tierDiscountSettings || $this->tierDiscountSettings->strikethroughEnabled();
	}

	/**
	 * Discounted unit prices keyed by tier id.
	 *
	 * @param Product $product The product whose tiers to price.
	 * @return array<int, int> The discounted unit price keyed by price id (only covered tiers present).
	 */
	protected function tierDiscountMap( Product $product ): array {
		if ( null === $this->tierDiscounts ) {
			return array();
		}

		$nodes    = array();
		$priceIds = array();
		foreach ( $product->prices() as $price ) {
			if ( ! $price->isPurchasable() ) {
				continue;
			}

			$nodes[]    = array(
				'product_id' => (int) $product->id(),
				'subtotal'   => $price->amount(),
			);
			$priceIds[] = (int) $price->id();
		}

		if ( array() === $nodes ) {
			return array();
		}

		try {
			$best = $this->tierDiscounts->bestForNodes( $nodes, null );
		} catch ( DiscountPersistenceException $e ) {
			return array();
		}

		$result = array();
		foreach ( array_values( $nodes ) as $index => $node ) {
			$application = $best[ $index ] ?? null;
			if ( null !== $application ) {
				$result[ $priceIds[ $index ] ] = (int) $node['subtotal'] - $application->total();
			}
		}

		return $result;
	}
}
