<?php
/**
 * Bundle composition value object.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Products;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * A bundle entry and its optional child price tier.
 *
 * {@see ProductService} validates the child product.
 *
 * @since 1.0.0
 */
final class BundleItem {

	/**
	 * Owning bundle product id, or null before the item is attached to a bundle.
	 *
	 * @var int|null
	 */
	private ?int $bundleProductId;

	/**
	 * The contained child product id.
	 *
	 * @var int
	 */
	private int $childProductId;

	/**
	 * Which child price tier the bundle grants, or null for the child's default.
	 *
	 * @var int|null
	 */
	private ?int $childPriceId;

	/**
	 * Display sort order within the bundle.
	 *
	 * @var int
	 */
	private int $sortOrder;

	/**
	 * Construct and validate a bundle item.
	 *
	 * @since 1.0.0
	 *
	 * @param int|null $bundleProductId Owning bundle id, or null when detached.
	 * @param int      $childProductId  The contained product id (must be positive).
	 * @param int|null $childPriceId    Granted child tier, or null for its default.
	 * @param int      $sortOrder       Display sort order.
	 *
	 * @throws ProductException When the child product id is not a positive integer.
	 */
	public function __construct( ?int $bundleProductId, int $childProductId, ?int $childPriceId, int $sortOrder ) {
		if ( $childProductId <= 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new ProductException( __( 'A bundle item must reference a valid child product.', 'tiermint' ) );
		}

		$this->bundleProductId = $bundleProductId;
		$this->childProductId  = $childProductId;
		$this->childPriceId    = $childPriceId;
		$this->sortOrder       = $sortOrder;
	}

	/**
	 * Build an item from a loosely-typed input array.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $data Input keyed by column name.
	 * @return self
	 *
	 * @throws ProductException When the resulting item is invalid.
	 */
	public static function fromArray( array $data ): self {
		return new self(
			isset( $data['bundle_product_id'] ) ? (int) $data['bundle_product_id'] : null,
			(int) ( $data['child_product_id'] ?? 0 ),
			isset( $data['child_price_id'] ) && null !== $data['child_price_id'] ? (int) $data['child_price_id'] : null,
			(int) ( $data['sort_order'] ?? 0 )
		);
	}

	/**
	 * Build an item from a database row.
	 *
	 * @since 1.0.0
	 *
	 * @param object $row A `tiermint_bundle_items` row.
	 * @return self
	 *
	 * @throws ProductException When the row is invalid.
	 */
	public static function fromRow( object $row ): self {
		return self::fromArray( (array) $row );
	}

	/**
	 * Return child columns. The repository adds the bundle id.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed> Column name => value.
	 */
	public function toRow(): array {
		return array(
			'child_product_id' => $this->childProductId,
			'child_price_id'   => $this->childPriceId,
			'sort_order'       => $this->sortOrder,
		);
	}

	/**
	 * The owning bundle product id, or null when detached.
	 *
	 * @since 1.0.0
	 *
	 * @return int|null
	 */
	public function bundleProductId(): ?int {
		return $this->bundleProductId;
	}

	/**
	 * The contained child product id.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function childProductId(): int {
		return $this->childProductId;
	}

	/**
	 * The granted child price tier, or null for the child's default.
	 *
	 * @since 1.0.0
	 *
	 * @return int|null
	 */
	public function childPriceId(): ?int {
		return $this->childPriceId;
	}

	/**
	 * The display sort order.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function sortOrder(): int {
		return $this->sortOrder;
	}
}
