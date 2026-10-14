<?php
/**
 * Product term value object.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Products;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * A product category or tag value.
 *
 * Categories can nest. Rules that need lookups stay in {@see TermService}.
 *
 * @since 1.0.0
 */
final class Term {

	/**
	 * Primary key, or null for a term that has not been persisted yet.
	 *
	 * @var int|null
	 */
	private ?int $id;

	/**
	 * Which taxonomy this term belongs to (category or tag).
	 *
	 * @var ProductTaxonomy
	 */
	private ProductTaxonomy $taxonomy;

	/**
	 * Display name.
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * URL slug, unique within the term's taxonomy.
	 *
	 * @var string
	 */
	private string $slug;

	/**
	 * Parent term id, or 0 for a top-level term. Always 0 for tags.
	 *
	 * @var int
	 */
	private int $parent;

	/**
	 * Optional long description, or null.
	 *
	 * @var string|null
	 */
	private ?string $description;

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
	 * Construct a term from typed fields.
	 *
	 * @since 1.0.0
	 *
	 * @param int|null        $id          Primary key, or null when unsaved.
	 * @param ProductTaxonomy $taxonomy    The owning taxonomy.
	 * @param string          $name        Display name.
	 * @param string          $slug        URL slug (unique within the taxonomy).
	 * @param int             $parentId    Parent term id, or 0 for a top-level term.
	 * @param string|null     $description Long description, or null.
	 * @param string|null     $createdAt   UTC created timestamp, or null.
	 * @param string|null     $updatedAt   UTC updated timestamp, or null.
	 */
	public function __construct(
		?int $id,
		ProductTaxonomy $taxonomy,
		string $name,
		string $slug,
		int $parentId = 0,
		?string $description = null,
		?string $createdAt = null,
		?string $updatedAt = null
	) {
		$this->id          = $id;
		$this->taxonomy    = $taxonomy;
		$this->name        = $name;
		$this->slug        = $slug;
		$this->parent      = $parentId;
		$this->description = $description;
		$this->createdAt   = $createdAt;
		$this->updatedAt   = $updatedAt;
	}

	/**
	 * Build a term from input data.
	 *
	 * Unknown taxonomies default to category. The service validates input.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $data Scalar fields keyed by column name.
	 * @return self
	 */
	public static function fromArray( array $data ): self {
		$taxonomy = ProductTaxonomy::tryFrom( (string) ( $data['taxonomy'] ?? ProductTaxonomy::Category->value ) )
			?? ProductTaxonomy::Category;

		return new self(
			isset( $data['id'] ) ? (int) $data['id'] : null,
			$taxonomy,
			(string) ( $data['name'] ?? '' ),
			(string) ( $data['slug'] ?? '' ),
			(int) ( $data['parent'] ?? 0 ),
			isset( $data['description'] ) && null !== $data['description'] && '' !== $data['description']
				? (string) $data['description']
				: null,
			isset( $data['created_at'] ) && null !== $data['created_at'] ? (string) $data['created_at'] : null,
			isset( $data['updated_at'] ) && null !== $data['updated_at'] ? (string) $data['updated_at'] : null
		);
	}

	/**
	 * Build a term from a database row.
	 *
	 * @since 1.0.0
	 *
	 * @param object $row A `tiermint_terms` row.
	 * @return self
	 */
	public static function fromRow( object $row ): self {
		return self::fromArray( (array) $row );
	}

	/**
	 * Return stored columns without the id or timestamps.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed> Column name => value.
	 */
	public function toRow(): array {
		return array(
			'taxonomy'    => $this->taxonomy->value,
			'name'        => $this->name,
			'slug'        => $this->slug,
			'parent'      => $this->parent,
			'description' => $this->description,
		);
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
	 * The owning taxonomy (category or tag).
	 *
	 * @since 1.0.0
	 *
	 * @return ProductTaxonomy
	 */
	public function taxonomy(): ProductTaxonomy {
		return $this->taxonomy;
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
	 * The URL slug, unique within the term's taxonomy.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function slug(): string {
		return $this->slug;
	}

	/**
	 * The parent term id, or 0 for a top-level term.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function parent(): int {
		return $this->parent;
	}

	/**
	 * The long description, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public function description(): ?string {
		return $this->description;
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
}
