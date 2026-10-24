<?php
/**
 * Product aggregate value object.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Products;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Immutable product with price tiers and bundle items.
 *
 * The service handles rules that need storage. File references use storage keys, not paths.
 *
 * @since 1.0.0
 */
final class Product {

	/**
	 * Primary key, or null for a product that has not been persisted yet.
	 *
	 * @var int|null
	 */
	private ?int $id;

	/**
	 * Display name.
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * URL slug, unique across products.
	 *
	 * @var string
	 */
	private string $slug;

	/**
	 * Lifecycle status.
	 *
	 * @var ProductStatus
	 */
	private ProductStatus $status;

	/**
	 * Product type (digital or bundle).
	 *
	 * @var ProductType
	 */
	private ProductType $type;

	/**
	 * Short rich-text overview, or null.
	 *
	 * @var string|null
	 */
	private ?string $overview;

	/**
	 * Long rich-text description, or null.
	 *
	 * @var string|null
	 */
	private ?string $description;

	/**
	 * Whether a purchase of this product generates a license.
	 *
	 * @var bool
	 */
	private bool $isLicensed;

	/**
	 * The slug customer plugins send to `/update-check`, or null.
	 *
	 * @var string|null
	 */
	private ?string $updateSlug;

	/**
	 * Non-software downloadable file references, each a storage pointer.
	 *
	 * @var array<int, array{provider: string, key: string, label: string}>
	 */
	private array $files;

	/**
	 * Per-product settings blob (e.g. default license length overrides).
	 *
	 * @var array<string, mixed>
	 */
	private array $settings;

	/**
	 * UTC creation timestamp (Y-m-d H:i:s), or null before first persist.
	 *
	 * @var string|null
	 */
	private ?string $createdAt;

	/**
	 * UTC last-update timestamp (Y-m-d H:i:s), or null before first persist.
	 *
	 * @var string|null
	 */
	private ?string $updatedAt;

	/**
	 * The product's price tiers.
	 *
	 * @var array<int, ProductPrice>
	 */
	private array $prices;

	/**
	 * The bundle composition (empty for non-bundle products).
	 *
	 * @var array<int, BundleItem>
	 */
	private array $bundleItems;

	/**
	 * Construct and validate a product.
	 *
	 * @since 1.0.0
	 *
	 * @param int|null                 $id          Primary key, or null when unsaved.
	 * @param string                   $name        Display name.
	 * @param string                   $slug        URL slug.
	 * @param ProductStatus            $status      Lifecycle status.
	 * @param ProductType              $type        Product type.
	 * @param string|null              $description Long description, or null.
	 * @param bool                     $isLicensed  Whether purchase mints a license.
	 * @param string|null              $updateSlug  Update-check slug, or null.
	 * @param array<int, mixed>        $files       Raw file references (validated here).
	 * @param array<string, mixed>     $settings    Settings blob.
	 * @param string|null              $createdAt   UTC created timestamp, or null.
	 * @param string|null              $updatedAt   UTC updated timestamp, or null.
	 * @param array<int, ProductPrice> $prices      Price tiers.
	 * @param array<int, BundleItem>   $bundleItems Bundle composition.
	 * @param string|null              $overview    Short rich-text overview, or null.
	 *
	 * @throws ProductException When a file reference is malformed.
	 */
	public function __construct(
		?int $id,
		string $name,
		string $slug,
		ProductStatus $status,
		ProductType $type,
		?string $description,
		bool $isLicensed,
		?string $updateSlug,
		array $files,
		array $settings,
		?string $createdAt,
		?string $updatedAt,
		array $prices,
		array $bundleItems,
		?string $overview = null
	) {
		$this->id          = $id;
		$this->name        = $name;
		$this->slug        = $slug;
		$this->status      = $status;
		$this->type        = $type;
		$this->overview    = $overview;
		$this->description = $description;
		$this->isLicensed  = $isLicensed;
		$this->updateSlug  = $updateSlug;
		$this->files       = $this->normalizeFiles( $files );
		$this->settings    = $settings;
		$this->createdAt   = $createdAt;
		$this->updatedAt   = $updatedAt;
		$this->prices      = array_values( $prices );
		$this->bundleItems = array_values( $bundleItems );
	}

	/**
	 * Build a product from scalar input and typed collections.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed>     $data        Scalar fields keyed by column name.
	 * @param array<int, ProductPrice> $prices      Price tiers.
	 * @param array<int, BundleItem>   $bundleItems Bundle composition.
	 * @return self
	 *
	 * @throws ProductException When a file reference is malformed.
	 */
	public static function fromArray( array $data, array $prices = array(), array $bundleItems = array() ): self {
		$status = ProductStatus::tryFrom( (string) ( $data['status'] ?? ProductStatus::Draft->value ) ) ?? ProductStatus::Draft;
		$type   = ProductType::tryFrom( (string) ( $data['type'] ?? ProductType::Digital->value ) ) ?? ProductType::Digital;

		return new self(
			isset( $data['id'] ) ? (int) $data['id'] : null,
			(string) ( $data['name'] ?? '' ),
			(string) ( $data['slug'] ?? '' ),
			$status,
			$type,
			isset( $data['description'] ) && null !== $data['description'] ? (string) $data['description'] : null,
			(bool) ( $data['is_licensed'] ?? true ),
			isset( $data['update_slug'] ) && null !== $data['update_slug'] && '' !== $data['update_slug'] ? (string) $data['update_slug'] : null,
			is_array( $data['files'] ?? null ) ? $data['files'] : array(),
			is_array( $data['settings'] ?? null ) ? $data['settings'] : array(),
			isset( $data['created_at'] ) && null !== $data['created_at'] ? (string) $data['created_at'] : null,
			isset( $data['updated_at'] ) && null !== $data['updated_at'] ? (string) $data['updated_at'] : null,
			$prices,
			$bundleItems,
			isset( $data['overview'] ) && null !== $data['overview'] ? (string) $data['overview'] : null
		);
	}

	/**
	 * Build a product from a row and its loaded collections.
	 *
	 * @since 1.0.0
	 *
	 * @param object                   $row         A `tiermint_products` row.
	 * @param array<int, ProductPrice> $prices      The product's loaded price tiers.
	 * @param array<int, BundleItem>   $bundleItems The product's loaded bundle items.
	 * @return self
	 *
	 * @throws ProductException When a stored file reference is malformed.
	 */
	public static function fromRow( object $row, array $prices = array(), array $bundleItems = array() ): self {
		$data             = (array) $row;
		$data['files']    = self::decodeJsonArray( $row->files ?? null );
		$data['settings'] = self::decodeJsonArray( $row->settings ?? null );

		return self::fromArray( $data, $prices, $bundleItems );
	}

	/**
	 * Map persistable fields to storage values.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed> Column name => value.
	 */
	public function toRow(): array {
		return array(
			'name'        => $this->name,
			'slug'        => $this->slug,
			'status'      => $this->status->value,
			'type'        => $this->type->value,
			'overview'    => $this->overview,
			'description' => $this->description,
			'is_licensed' => $this->isLicensed ? 1 : 0,
			'update_slug' => $this->updateSlug,
			'files'       => $this->files,
			'settings'    => $this->settings,
		);
	}

	/**
	 * Get the first sellable tier by sort order and id.
	 *
	 * @since 1.0.0
	 *
	 * @return ProductPrice|null The default tier, or null when none is purchasable.
	 */
	public function defaultPrice(): ?ProductPrice {
		$active = array_values( array_filter( $this->prices, static fn ( ProductPrice $p ): bool => $p->isPurchasable() ) );

		if ( array() === $active ) {
			return null;
		}

		usort(
			$active,
			static function ( ProductPrice $a, ProductPrice $b ): int {
				return array( $a->sortOrder(), $a->id() ?? PHP_INT_MAX )
					<=> array( $b->sortOrder(), $b->id() ?? PHP_INT_MAX );
			}
		);

		return $active[0];
	}

	/**
	 * Get an active tier by id.
	 *
	 * @since 1.0.0
	 *
	 * @param int $priceId The price tier id.
	 * @return ProductPrice|null The active tier, or null.
	 */
	public function activePrice( int $priceId ): ?ProductPrice {
		foreach ( $this->prices as $price ) {
			if ( $price->id() === $priceId && $price->isActive() ) {
				return $price;
			}
		}

		return null;
	}

	/**
	 * Whether the product has at least one active price tier.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function hasActivePrice(): bool {
		foreach ( $this->prices as $price ) {
			if ( $price->isActive() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The primary key, or null when unsaved.
	 *
	 * @since 1.0.0
	 *
	 * @return int|null
	 */
	public function id(): ?int {
		return $this->id;
	}

	/**
	 * The display name.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * The URL slug.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function slug(): string {
		return $this->slug;
	}

	/**
	 * The lifecycle status.
	 *
	 * @since 1.0.0
	 *
	 * @return ProductStatus
	 */
	public function status(): ProductStatus {
		return $this->status;
	}

	/**
	 * The product type.
	 *
	 * @since 1.0.0
	 *
	 * @return ProductType
	 */
	public function type(): ProductType {
		return $this->type;
	}

	/**
	 * Whether the product is a bundle.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function isBundle(): bool {
		return ProductType::Bundle === $this->type;
	}

	/**
	 * The short rich-text overview, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public function overview(): ?string {
		return $this->overview;
	}

	/**
	 * The long rich-text description, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public function description(): ?string {
		return $this->description;
	}

	/**
	 * Whether purchasing the product mints a license.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function isLicensed(): bool {
		return $this->isLicensed;
	}

	/**
	 * The update-check slug, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public function updateSlug(): ?string {
		return $this->updateSlug;
	}

	/**
	 * The validated file references.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array{provider: string, key: string, label: string}>
	 */
	public function files(): array {
		return $this->files;
	}

	/**
	 * The settings blob.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>
	 */
	public function settings(): array {
		return $this->settings;
	}

	/**
	 * The UTC creation timestamp, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public function createdAt(): ?string {
		return $this->createdAt;
	}

	/**
	 * The UTC last-update timestamp, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public function updatedAt(): ?string {
		return $this->updatedAt;
	}

	/**
	 * The price tiers.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, ProductPrice>
	 */
	public function prices(): array {
		return $this->prices;
	}

	/**
	 * The bundle composition (empty for non-bundle products).
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, BundleItem>
	 */
	public function bundleItems(): array {
		return $this->bundleItems;
	}

	/**
	 * Validate and normalize storage file references.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, mixed> $files The raw file references.
	 * @return array<int, array{provider: string, key: string, label: string}> Normalized references.
	 *
	 * @throws ProductException When any entry is not a valid storage reference.
	 */
	private function normalizeFiles( array $files ): array {
		$normalized = array();

		foreach ( $files as $file ) {
			if ( ! is_array( $file ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
				throw new ProductException( __( 'A product file must be a storage reference array, not a raw path.', 'tiermint' ) );
			}

			$provider = isset( $file['provider'] ) ? (string) $file['provider'] : '';
			$key      = isset( $file['key'] ) ? (string) $file['key'] : '';

			if ( '' === $provider || '' === $key ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
				throw new ProductException( __( 'A product file reference requires both a provider and a key.', 'tiermint' ) );
			}

			if ( $this->looksLikeRawPath( $key ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
				throw new ProductException( __( 'A product file key must be a storage key, not a raw or absolute path.', 'tiermint' ) );
			}

			$normalized[] = array(
				'provider' => $provider,
				'key'      => $key,
				'label'    => isset( $file['label'] ) ? (string) $file['label'] : '',
			);
		}

		return $normalized;
	}

	/**
	 * Whether a storage key looks like a filesystem path.
	 *
	 * Rejects POSIX absolute paths (`/...`), Windows drive/UNC paths (`C:\...`, `\\...`),
	 * and any parent-directory traversal.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key The storage key to inspect.
	 * @return bool True when the key must be rejected.
	 */
	private function looksLikeRawPath( string $key ): bool {
		if ( 1 === preg_match( '#^([a-zA-Z]:[\\\\/]|[\\\\/])#', $key ) ) {
			return true;
		}

		return false !== strpos( $key, '..' );
	}

	/**
	 * Decode a JSON column into an array, tolerating null and malformed values.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value The raw column value (JSON string or null).
	 * @return array<int|string, mixed> The decoded array, or empty on failure.
	 */
	private static function decodeJsonArray( $value ): array {
		if ( ! is_string( $value ) || '' === $value ) {
			return array();
		}

		$decoded = json_decode( $value, true );

		return is_array( $decoded ) ? $decoded : array();
	}
}
