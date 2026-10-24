<?php
/**
 * Products repository: the only Products class that touches $wpdb.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Products;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Database\Schema;

/**
 * Stores products, prices and bundle items.
 *
 * Cache entries use the product id and slug. SQL identifiers come from {@see Schema}.
 *
 * @since 1.0.0
 */
class ProductRepository {

	/**
	 * The object-cache group products are cached under.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'tiermint_products';

	/**
	 * The WordPress database abstraction.
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * Wire the repository with its database handle.
	 *
	 * @since 1.0.0
	 *
	 * @param \wpdb $wpdb The WordPress database abstraction.
	 */
	public function __construct( \wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Insert a product with prices and bundle items.
	 *
	 * Use compensation because this can run inside an outer transaction.
	 *
	 * @since 1.0.0
	 *
	 * @param Product $product The product to persist.
	 * @return int The new product id.
	 *
	 * @throws ProductPersistenceException When an insert fails.
	 */
	public function create( Product $product ): int {
		$now = gmdate( 'Y-m-d H:i:s' );
		$row = $product->toRow();

		$id = $this->insertInto(
			$this->productsTable(),
			array(
				'name'        => $row['name'],
				'slug'        => $row['slug'],
				'status'      => $row['status'],
				'type'        => $row['type'],
				'overview'    => $row['overview'],
				'description' => $row['description'],
				'is_licensed' => $row['is_licensed'],
				'update_slug' => $row['update_slug'],
				'files'       => $this->encodeJson( $row['files'] ),
				'settings'    => $this->encodeJson( $row['settings'] ),
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);

		try {
			$this->insertPrices( $id, $product->prices() );

			if ( $product->isBundle() ) {
				$this->insertBundleItems( $id, $product->bundleItems() );
			}
		} catch ( ProductPersistenceException $e ) {
			// Remove the parent after a child insert fails. Keep the original error.
			try {
				$this->delete( $id );
			} catch ( ProductPersistenceException $compensation ) {
				unset( $compensation );
			}

			throw $e;
		}

		$this->forget( $id, (string) $row['slug'] );

		return $id;
	}

	/**
	 * Load a fully-hydrated product by id, reading through the object cache.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The product id.
	 * @return Product|null The product, or null when it does not exist.
	 */
	public function find( int $id ): ?Product {
		$cached = wp_cache_get( $this->idKey( $id ), self::CACHE_GROUP );

		if ( $cached instanceof Product ) {
			return $cached;
		}

		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table; this class decides what to cache.
		$row = $this->fetchRow( fn () => $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->productsTable(), $id ) ) );

		if ( null === $row ) {
			return null;
		}

		$product = $this->hydrate( $row );
		wp_cache_set( $this->idKey( $id ), $product, self::CACHE_GROUP );

		return $product;
	}

	/**
	 * Product names by id for list views.
	 *
	 * Read only id and name. Missing products are not included.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, mixed> $ids The product ids to name.
	 * @return array<int, string> Product id => name.
	 */
	public function namesFor( array $ids ): array {
		$unique = $this->positiveIds( $ids );

		if ( array() === $unique ) {
			return array();
		}

		$wpdb   = $this->wpdb;
		$values = array_merge( array( $this->productsTable() ), $unique );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$rows = $this->fetchRows( fn () => $wpdb->get_results( $wpdb->prepare( 'SELECT id, name FROM %i WHERE id IN (' . implode( ',', array_fill( 0, count( $unique ), '%d' ) ) . ')', $values ) ) );

		$names = array();

		foreach ( $rows as $row ) {
			$names[ (int) $row->id ] = (string) $row->name;
		}

		return $names;
	}

	/**
	 * Load a fully-hydrated product by slug, reading through the object cache.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug The product slug.
	 * @return Product|null The product, or null when it does not exist.
	 */
	public function findBySlug( string $slug ): ?Product {
		$cached = wp_cache_get( $this->slugKey( $slug ), self::CACHE_GROUP );

		if ( $cached instanceof Product ) {
			return $cached;
		}

		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table; this class decides what to cache.
		$row = $this->fetchRow( fn () => $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE slug = %s', $this->productsTable(), $slug ) ) );

		if ( null === $row ) {
			return null;
		}

		$product = $this->hydrate( $row );
		wp_cache_set( $this->slugKey( $slug ), $product, self::CACHE_GROUP );

		return $product;
	}

	/**
	 * Update a product, its prices and bundle items.
	 *
	 * Bundle items can be replaced because they have no external references.
	 *
	 * @since 1.0.0
	 *
	 * @param Product $product The product to persist (must carry an id).
	 * @return void
	 *
	 * @throws ProductException When the product has no id.
	 */
	public function update( Product $product ): void {
		$id = $product->id();

		if ( null === $id ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new ProductException( __( 'Cannot update a product that has no id.', 'tiermint' ) );
		}

		$row          = $product->toRow();
		$wpdb         = $this->wpdb;
		$previousSlug = (string) $this->fetchVar(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
			fn () => $wpdb->get_var( $wpdb->prepare( 'SELECT slug FROM %i WHERE id = %d', $this->productsTable(), $id ) )
		);

		$this->updateWhere(
			$this->productsTable(),
			array(
				'name'        => $row['name'],
				'slug'        => $row['slug'],
				'status'      => $row['status'],
				'type'        => $row['type'],
				'overview'    => $row['overview'],
				'description' => $row['description'],
				'is_licensed' => $row['is_licensed'],
				'update_slug' => $row['update_slug'],
				'files'       => $this->encodeJson( $row['files'] ),
				'settings'    => $this->encodeJson( $row['settings'] ),
				'updated_at'  => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $id )
		);

		$this->reconcilePrices( $id, $product->prices() );
		$this->reconcileBundleItems( $id, $product );

		$this->forget( $id, (string) $row['slug'] );

		if ( '' !== $previousSlug && $previousSlug !== (string) $row['slug'] ) {
			wp_cache_delete( $this->slugKey( $previousSlug ), self::CACHE_GROUP );
		}
	}

	/**
	 * Delete a product with its prices, bundle links and term links.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The product id to delete.
	 * @return void
	 *
	 * @throws ProductPersistenceException When any of the deletes fails at the database.
	 */
	public function delete( int $id ): void {
		$existing = $this->find( $id );
		$wpdb     = $this->wpdb;

		// find() cached the product. Evict it even when a cascade fails.
		try {
			$this->deleteWhere( $this->productsTable(), array( 'id' => $id ) );
			$this->deleteWhere( $this->pricesTable(), array( 'product_id' => $id ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE bundle_product_id = %d OR child_product_id = %d', $this->bundleTable(), $id, $id ) );
			$this->deleteWhere( $this->relationshipsTable(), array( 'product_id' => $id ) );
		} finally {
			$this->forget( $id, null !== $existing ? $existing->slug() : '' );
		}
	}

	/**
	 * Whether a slug is already taken, optionally excluding one product id.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $slug     The slug to check.
	 * @param int|null $exceptId A product id to exclude (for updates), or null.
	 * @return bool True when the slug is in use.
	 */
	public function slugExists( string $slug, ?int $exceptId = null ): bool {
		return $this->columnValueExists( 'slug', $slug, $exceptId );
	}

	/**
	 * Whether an update slug is already taken, optionally excluding one product id.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $updateSlug The update slug to check.
	 * @param int|null $exceptId   A product id to exclude (for updates), or null.
	 * @return bool True when the update slug is in use.
	 */
	public function updateSlugExists( string $updateSlug, ?int $exceptId = null ): bool {
		return $this->columnValueExists( 'update_slug', $updateSlug, $exceptId );
	}

	/**
	 * Query products for a collection view.
	 *
	 * Supports status, search, category, tag, paging and safe ordering.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $args The query args.
	 * @return array<int, Product> The matching, fully-hydrated products.
	 */
	public function query( array $args ): array {
		$args    = $this->normalizeArgs( $args );
		$filters = $this->filters( $args );

		if ( null === $filters ) {
			return array();
		}

		$termIds = $filters['term_ids'];
		$tags    = $filters['tag_slugs'];
		$perPage = $this->perPage( $args );
		$values  = array_merge(
			$filters['values'],
			array(
				$this->whitelistOrderby( $args['orderby'] ?? 'created_at' ),
				$perPage,
				( max( 1, (int) ( $args['page'] ?? 1 ) ) - 1 ) * $perPage,
			)
		);
		$wpdb    = $this->wpdb;

		// The WHERE is the one in countQuery(); keep the copies in step.
		if ( 'ASC' === $this->whitelistOrder( $args['order'] ?? 'DESC' ) ) {
			$rows = $this->fetchRows(
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
				fn () => $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM %i WHERE ( %s = '' OR status = %s )" .
						' AND ( %d = 0 OR name LIKE %s OR slug LIKE %s OR overview LIKE %s OR description LIKE %s )' .
						' AND ( %d = 0 OR id IN ( SELECT tr.product_id FROM %i tr WHERE tr.term_id IN ( ' .
						implode( ', ', array_fill( 0, count( $termIds ), '%d' ) ) . ' ) ) )' .
						' AND ( %d = 0 OR id IN ( SELECT tr.product_id FROM %i tr INNER JOIN %i t ON t.id = tr.term_id' .
						' WHERE t.taxonomy = %s AND t.slug IN ( ' . implode( ', ', array_fill( 0, count( $tags ), '%s' ) ) . ' ) ) )' .
						' ORDER BY %i ASC, id ASC LIMIT %d OFFSET %d',
						$values
					)
				)
			);
		} else {
			$rows = $this->fetchRows(
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
				fn () => $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM %i WHERE ( %s = '' OR status = %s )" .
						' AND ( %d = 0 OR name LIKE %s OR slug LIKE %s OR overview LIKE %s OR description LIKE %s )' .
						' AND ( %d = 0 OR id IN ( SELECT tr.product_id FROM %i tr WHERE tr.term_id IN ( ' .
						implode( ', ', array_fill( 0, count( $termIds ), '%d' ) ) . ' ) ) )' .
						' AND ( %d = 0 OR id IN ( SELECT tr.product_id FROM %i tr INNER JOIN %i t ON t.id = tr.term_id' .
						' WHERE t.taxonomy = %s AND t.slug IN ( ' . implode( ', ', array_fill( 0, count( $tags ), '%s' ) ) . ' ) ) )' .
						' ORDER BY %i DESC, id DESC LIMIT %d OFFSET %d',
						$values
					)
				)
			);
		}

		return $this->hydratePage( $rows );
	}

	/**
	 * Hydrate a page with batched price and bundle reads.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, object> $rows The `tiermint_products` rows on this page.
	 * @return array<int, Product> The hydrated products, in the order given.
	 */
	private function hydratePage( array $rows ): array {
		$prices = $this->loadPricesFor( array_map( static fn ( object $row ): int => (int) $row->id, $rows ) );

		$bundleIds = array();
		foreach ( $rows as $row ) {
			if ( ProductType::Bundle->value === (string) $row->type ) {
				$bundleIds[] = (int) $row->id;
			}
		}

		$bundles = $this->loadBundleItemsFor( $bundleIds );

		return array_map(
			static fn ( object $row ): Product => Product::fromRow(
				$row,
				$prices[ (int) $row->id ] ?? array(),
				$bundles[ (int) $row->id ] ?? array()
			),
			$rows
		);
	}

	/**
	 * Count products matching a collection query (for pagination).
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $args The query args (status, search).
	 * @return int The total matching product count.
	 */
	public function countQuery( array $args ): int {
		$filters = $this->filters( $this->normalizeArgs( $args ) );

		if ( null === $filters ) {
			return 0;
		}

		$termIds = $filters['term_ids'];
		$tags    = $filters['tag_slugs'];
		$values  = $filters['values'];
		$wpdb    = $this->wpdb;

		return (int) $this->fetchVar(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
			fn () => $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i WHERE ( %s = '' OR status = %s )" .
					' AND ( %d = 0 OR name LIKE %s OR slug LIKE %s OR overview LIKE %s OR description LIKE %s )' .
					' AND ( %d = 0 OR id IN ( SELECT tr.product_id FROM %i tr WHERE tr.term_id IN ( ' .
					implode( ', ', array_fill( 0, count( $termIds ), '%d' ) ) . ' ) ) )' .
					' AND ( %d = 0 OR id IN ( SELECT tr.product_id FROM %i tr INNER JOIN %i t ON t.id = tr.term_id' .
					' WHERE t.taxonomy = %s AND t.slug IN ( ' . implode( ', ', array_fill( 0, count( $tags ), '%s' ) ) . ' ) ) )',
					$values
				)
			)
		);
	}

	/**
	 * Let extensions normalize product query arguments.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $args The raw query args.
	 * @return array<string, mixed> The normalized query args.
	 */
	private function normalizeArgs( array $args ): array {
		/**
		 * Filters product query arguments before SQL is built.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $args The product-query args about to build SQL.
		 */
		return (array) apply_filters( 'tiermint_product_query_args', $args );
	}

	/**
	 * Hydrate a product row into a full aggregate, loading its prices and (for
	 * bundles) its composition.
	 *
	 * @since 1.0.0
	 *
	 * @param object $row A `tiermint_products` row.
	 * @return Product The hydrated product.
	 */
	private function hydrate( object $row ): Product {
		$id          = (int) $row->id;
		$prices      = $this->loadPrices( $id );
		$bundleItems = ProductType::Bundle->value === (string) $row->type
			? $this->loadBundleItems( $id )
			: array();

		return Product::fromRow( $row, $prices, $bundleItems );
	}

	/**
	 * Load a product's price tiers, ordered for deterministic default selection.
	 *
	 * @since 1.0.0
	 *
	 * @param int $productId The owning product id.
	 * @return array<int, ProductPrice> The ordered price tiers.
	 */
	private function loadPrices( int $productId ): array {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$rows = $this->fetchRows( fn () => $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE product_id = %d ORDER BY sort_order ASC, id ASC', $this->pricesTable(), $productId ) ) );

		return array_map( static fn ( object $r ): ProductPrice => ProductPrice::fromRow( $r ), $rows );
	}

	/**
	 * Load price tiers for products in one query.
	 *
	 * Products without tiers are not included.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, int> $productIds The owning product ids.
	 * @return array<int, array<int, ProductPrice>> Product id => its ordered tiers.
	 */
	private function loadPricesFor( array $productIds ): array {
		$unique = $this->positiveIds( $productIds );

		if ( array() === $unique ) {
			return array();
		}

		$wpdb   = $this->wpdb;
		$values = array_merge( array( $this->pricesTable() ), $unique );
		$rows   = $this->fetchRows(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
			fn () => $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE product_id IN (' . implode( ',', array_fill( 0, count( $unique ), '%d' ) ) . ')' .
					' ORDER BY product_id ASC, sort_order ASC, id ASC',
					$values
				)
			)
		);

		$grouped = array();

		foreach ( $rows as $row ) {
			$grouped[ (int) $row->product_id ][] = ProductPrice::fromRow( $row );
		}

		return $grouped;
	}

	/**
	 * Load bundle items in one query, grouped by bundle id.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, int> $bundleIds The owning bundle product ids.
	 * @return array<int, array<int, BundleItem>> Bundle id => its ordered items.
	 */
	private function loadBundleItemsFor( array $bundleIds ): array {
		$unique = $this->positiveIds( $bundleIds );

		if ( array() === $unique ) {
			return array();
		}

		$wpdb   = $this->wpdb;
		$values = array_merge( array( $this->bundleTable() ), $unique );
		$rows   = $this->fetchRows(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
			fn () => $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE bundle_product_id IN (' . implode( ',', array_fill( 0, count( $unique ), '%d' ) ) . ')' .
					' ORDER BY bundle_product_id ASC, sort_order ASC, child_product_id ASC',
					$values
				)
			)
		);

		$grouped = array();

		foreach ( $rows as $row ) {
			$grouped[ (int) $row->bundle_product_id ][] = BundleItem::fromRow( $row );
		}

		return $grouped;
	}

	/**
	 * The distinct positive ids in a list, for an `IN (...)` lookup.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, mixed> $ids The raw ids.
	 * @return array<int, int> The unique positive ids.
	 */
	private function positiveIds( array $ids ): array {
		return array_values(
			array_unique( array_filter( array_map( 'intval', $ids ), static fn ( int $id ): bool => $id > 0 ) )
		);
	}

	/**
	 * Load a bundle's composition, ordered for display.
	 *
	 * @since 1.0.0
	 *
	 * @param int $bundleId The owning bundle product id.
	 * @return array<int, BundleItem> The ordered bundle items.
	 */
	private function loadBundleItems( int $bundleId ): array {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$rows = $this->fetchRows( fn () => $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE bundle_product_id = %d ORDER BY sort_order ASC, child_product_id ASC', $this->bundleTable(), $bundleId ) ) );

		return array_map( static fn ( object $r ): BundleItem => BundleItem::fromRow( $r ), $rows );
	}

	/**
	 * Insert a product's price tiers, stamping product id and timestamps.
	 *
	 * @since 1.0.0
	 *
	 * @param int                      $productId The owning product id.
	 * @param array<int, ProductPrice> $prices    The tiers to insert.
	 * @return void
	 */
	private function insertPrices( int $productId, array $prices ): void {
		$now = gmdate( 'Y-m-d H:i:s' );

		foreach ( $prices as $price ) {
			$data               = $price->toRow();
			$data['product_id'] = $productId;
			$data['created_at'] = $now;
			$data['updated_at'] = $now;

			$this->insertInto( $this->pricesTable(), $data );
		}
	}

	/**
	 * Insert a bundle's composition, stamping the owning bundle id.
	 *
	 * @since 1.0.0
	 *
	 * @param int                    $bundleId The owning bundle product id.
	 * @param array<int, BundleItem> $items    The items to insert.
	 * @return void
	 */
	private function insertBundleItems( int $bundleId, array $items ): void {
		foreach ( $items as $item ) {
			$data                      = $item->toRow();
			$data['bundle_product_id'] = $bundleId;

			$this->insertInto( $this->bundleTable(), $data );
		}
	}

	/**
	 * Reconcile a product's price rows to match the entity (upsert present, delete
	 * absent). See {@see update()} for the chosen semantics.
	 *
	 * @since 1.0.0
	 *
	 * @param int                      $productId The owning product id.
	 * @param array<int, ProductPrice> $prices    The desired tiers.
	 * @return void
	 */
	private function reconcilePrices( int $productId, array $prices ): void {
		$existingIds = $this->existingPriceIds( $productId );
		$keptIds     = array();
		$now         = gmdate( 'Y-m-d H:i:s' );

		foreach ( $prices as $price ) {
			$data    = $price->toRow();
			$priceId = $price->id();

			if ( null !== $priceId && in_array( $priceId, $existingIds, true ) ) {
				$data['updated_at'] = $now;
				$this->updateWhere( $this->pricesTable(), $data, array( 'id' => $priceId ) );
				$keptIds[] = $priceId;
				continue;
			}

			$data['product_id'] = $productId;
			$data['created_at'] = $now;
			$data['updated_at'] = $now;
			$keptIds[]          = $this->insertInto( $this->pricesTable(), $data );
		}

		foreach ( $existingIds as $existingId ) {
			if ( ! in_array( $existingId, $keptIds, true ) ) {
				$this->deleteWhere( $this->pricesTable(), array( 'id' => $existingId ) );
			}
		}
	}

	/**
	 * Replace a bundle's composition wholesale (delete then reinsert). A product no
	 * longer of bundle type ends up with no composition.
	 *
	 * @since 1.0.0
	 *
	 * @param int     $bundleId The owning bundle product id.
	 * @param Product $product  The product carrying the desired composition.
	 * @return void
	 */
	private function reconcileBundleItems( int $bundleId, Product $product ): void {
		$this->deleteWhere( $this->bundleTable(), array( 'bundle_product_id' => $bundleId ) );

		if ( $product->isBundle() ) {
			$this->insertBundleItems( $bundleId, $product->bundleItems() );
		}
	}

	/**
	 * The existing price-row ids for a product.
	 *
	 * @since 1.0.0
	 *
	 * @param int $productId The owning product id.
	 * @return array<int, int> The price ids.
	 */
	private function existingPriceIds( int $productId ): array {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$ids = $this->fetchCol( fn () => $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE product_id = %d', $this->pricesTable(), $productId ) ) );

		return array_map( 'intval', $ids );
	}

	/**
	 * Check a product column, excluding one product when needed.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $column   The fixed internal column name.
	 * @param string   $value    The value to look for.
	 * @param int|null $exceptId A product id to exclude, or null.
	 * @return bool True when a matching row exists.
	 */
	private function columnValueExists( string $column, string $value, ?int $exceptId ): bool {
		$wpdb = $this->wpdb;

		if ( null === $exceptId ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
			return null !== $this->fetchVar( fn () => $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE %i = %s LIMIT 1', $this->productsTable(), $column, $value ) ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		return null !== $this->fetchVar( fn () => $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE %i = %s AND id != %d LIMIT 1', $this->productsTable(), $column, $value, $exceptId ) ) );
	}

	/**
	 * Read the collection filters for the fixed WHERE in query() and countQuery().
	 *
	 * An empty status, an empty category or tag list or an empty search turns that filter
	 * off. MySQL drops a clause that is off before it plans the query. The search goes in
	 * as a 0/1 flag because MySQL collations compare a zero-width space equal to ''. An
	 * IN list keeps one placeholder value even when its filter is off, since SQL has no
	 * empty IN list.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $args The query args.
	 * @return array{values: array<int, int|string>, term_ids: array<int, int>, tag_slugs: array<int, string>}|null
	 *         The WHERE values in placeholder order (tables included) and the two IN lists,
	 *         or null when the category filter names no known category, so nothing matches.
	 */
	private function filters( array $args ): ?array {
		$status     = isset( $args['status'] ) ? (string) $args['status'] : '';
		$status     = null !== ProductStatus::tryFrom( $status ) ? $status : '';
		$search     = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
		$like       = '%' . $this->wpdb->esc_like( $search ) . '%';
		$categories = $this->parseSlugList( $args['category'] ?? null );
		$tags       = $this->parseSlugList( $args['tag'] ?? null );
		$termIds    = array();

		if ( array() !== $categories ) {
			// The resolved ids are categories, so no taxonomy check is needed on them.
			$termIds = $this->resolveCategoryTermIds( $categories, $this->includeChildren( $args ) );

			if ( array() === $termIds ) {
				return null;
			}
		}

		$termList = array() === $termIds ? array( 0 ) : $termIds;
		$tagList  = array() === $tags ? array( '' ) : $tags;

		return array(
			'term_ids'  => $termList,
			'tag_slugs' => $tagList,
			'values'    => array_merge(
				array(
					$this->productsTable(),
					$status,
					$status,
					'' === $search ? 0 : 1,
					$like,
					$like,
					$like,
					$like,
					array() === $termIds ? 0 : 1,
					$this->relationshipsTable(),
				),
				$termList,
				array(
					array() === $tags ? 0 : 1,
					$this->relationshipsTable(),
					$this->termsTable(),
					ProductTaxonomy::Tag->value,
				),
				$tagList
			),
		);
	}

	/**
	 * Return unique slugs from an array or comma-separated value.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value The raw filter value (array, comma string, or null).
	 * @return array<int, string> The parsed slugs.
	 */
	private function parseSlugList( $value ): array {
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$slugs = array();

		foreach ( $value as $slug ) {
			$slug = trim( (string) $slug );

			if ( '' !== $slug ) {
				$slugs[] = $slug;
			}
		}

		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Check whether a category filter includes descendants. Default to true.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $args The query args.
	 * @return bool True when descendants should be included.
	 */
	private function includeChildren( array $args ): bool {
		if ( ! array_key_exists( 'include_children', $args ) ) {
			return true;
		}

		$value = $args['include_children'];

		if ( is_bool( $value ) ) {
			return $value;
		}

		return ! in_array( $value, array( 'false', '0', 0, 'no', 'off', '' ), true );
	}

	/**
	 * Resolve category slugs and, when requested, their descendant ids.
	 * A visited set stops corrupt parent cycles.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string> $slugs           The selected category slugs.
	 * @param bool               $includeChildren Whether to expand to descendants.
	 * @return array<int, int> The resolved (and optionally expanded) term ids.
	 */
	private function resolveCategoryTermIds( array $slugs, bool $includeChildren ): array {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$rows = $this->fetchRows( fn () => $wpdb->get_results( $wpdb->prepare( 'SELECT id, slug, parent FROM %i WHERE taxonomy = %s', $this->termsTable(), ProductTaxonomy::Category->value ) ) );

		$idBySlug   = array();
		$childrenOf = array();

		foreach ( $rows as $row ) {
			$id                              = (int) $row->id;
			$idBySlug[ (string) $row->slug ] = $id;
			// Group each term id under its parent so the walk can descend the tree.
			$childrenOf[ (int) $row->parent ][] = $id;
		}

		$selected = array();

		foreach ( $slugs as $slug ) {
			if ( isset( $idBySlug[ $slug ] ) ) {
				$selected[] = $idBySlug[ $slug ];
			}
		}

		if ( ! $includeChildren || array() === $selected ) {
			return array_values( array_unique( $selected ) );
		}

		// A visited set stops corrupt parent cycles.
		$resolved = array();
		$queue    = $selected;

		while ( array() !== $queue ) {
			$current = (int) array_shift( $queue );

			if ( isset( $resolved[ $current ] ) ) {
				continue;
			}

			$resolved[ $current ] = true;

			foreach ( $childrenOf[ $current ] ?? array() as $childId ) {
				if ( ! isset( $resolved[ $childId ] ) ) {
					$queue[] = $childId;
				}
			}
		}

		return array_map( 'intval', array_keys( $resolved ) );
	}

	/**
	 * Whitelist the orderby column, defaulting to created_at.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $orderby The requested orderby.
	 * @return string A safe column name.
	 */
	private function whitelistOrderby( $orderby ): string {
		$allowed = array( 'id', 'name', 'created_at' );

		return in_array( (string) $orderby, $allowed, true ) ? (string) $orderby : 'created_at';
	}

	/**
	 * Whitelist the sort direction, defaulting to DESC.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $order The requested direction.
	 * @return string 'ASC' or 'DESC'.
	 */
	private function whitelistOrder( $order ): string {
		return 'ASC' === strtoupper( (string) $order ) ? 'ASC' : 'DESC';
	}

	/**
	 * The per-page size, defaulting to 10 (matching the REST collection schema) and
	 * capped at 100.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $args The query args.
	 * @return int The clamped per-page size.
	 */
	private function perPage( array $args ): int {
		$perPage = (int) ( $args['per_page'] ?? 10 );

		if ( $perPage < 1 ) {
			$perPage = 10;
		}

		return min( $perPage, 100 );
	}

	/**
	 * JSON-encode an array column, storing null for an empty array.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int|string, mixed> $value The value to encode.
	 * @return string|null The JSON string, or null when empty/unencodable.
	 */
	private function encodeJson( array $value ): ?string {
		if ( array() === $value ) {
			return null;
		}

		$json = wp_json_encode( $value );

		return false === $json ? null : $json;
	}

	/**
	 * The cache key for a product id.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The product id.
	 * @return string The cache key.
	 */
	private function idKey( int $id ): string {
		return 'product_' . $id;
	}

	/**
	 * The cache key for a product slug.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug The product slug.
	 * @return string The cache key.
	 */
	private function slugKey( string $slug ): string {
		return 'slug_' . $slug;
	}

	/**
	 * Invalidate the cached id and slug entries for a product.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $id   The product id.
	 * @param string $slug The product slug (empty to skip the slug key).
	 * @return void
	 */
	private function forget( int $id, string $slug ): void {
		wp_cache_delete( $this->idKey( $id ), self::CACHE_GROUP );

		if ( '' !== $slug ) {
			wp_cache_delete( $this->slugKey( $slug ), self::CACHE_GROUP );
		}
	}

	/**
	 * The fully-qualified `tiermint_products` table name.
	 *
	 * @since 1.0.0
	 *
	 * @return string The table name.
	 */
	private function productsTable(): string {
		return Schema::tableName( $this->wpdb, 'products' );
	}

	/**
	 * The fully-qualified `tiermint_product_prices` table name.
	 *
	 * @since 1.0.0
	 *
	 * @return string The table name.
	 */
	private function pricesTable(): string {
		return Schema::tableName( $this->wpdb, 'product_prices' );
	}

	/**
	 * The fully-qualified `tiermint_bundle_items` table name.
	 *
	 * @since 1.0.0
	 *
	 * @return string The table name.
	 */
	private function bundleTable(): string {
		return Schema::tableName( $this->wpdb, 'bundle_items' );
	}

	/**
	 * The fully-qualified `tiermint_term_relationships` table name.
	 *
	 * @since 1.0.0
	 *
	 * @return string The table name.
	 */
	private function relationshipsTable(): string {
		return Schema::tableName( $this->wpdb, 'term_relationships' );
	}

	/**
	 * The fully-qualified `tiermint_terms` table name.
	 *
	 * @since 1.0.0
	 *
	 * @return string The table name.
	 */
	private function termsTable(): string {
		return Schema::tableName( $this->wpdb, 'terms' );
	}

	/**
	 * Insert a row into a Schema-defined table and return the new auto-increment id.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $table The fully-qualified table name.
	 * @param array<string, mixed> $data  Column => value pairs.
	 * @return int The inserted row id.
	 *
	 * @throws ProductPersistenceException When the insert fails.
	 */
	private function insertInto( string $table, array $data ): int {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table; this class decides what to cache.
		$inserted = $wpdb->insert( $table, $data );

		if ( false === $inserted ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new ProductPersistenceException( 'Failed to insert a row into ' . $table . '.' );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update rows in a Schema-defined table.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $table The fully-qualified table name.
	 * @param array<string, mixed> $data  Column => value pairs to set.
	 * @param array<string, mixed> $where Column => value equality conditions.
	 * @return void
	 *
	 * @throws ProductPersistenceException When the update fails at the database.
	 */
	private function updateWhere( string $table, array $data, array $where ): void {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$updated = $wpdb->update( $table, $data, $where );

		if ( false === $updated ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new ProductPersistenceException( 'Failed to update a row in ' . $table . '.' );
		}
	}

	/**
	 * Delete rows from a Schema-defined table.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $table The fully-qualified table name.
	 * @param array<string, mixed> $where Column => value equality conditions.
	 * @return void
	 *
	 * @throws ProductPersistenceException When the delete fails at the database.
	 */
	private function deleteWhere( string $table, array $where ): void {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$deleted = $wpdb->delete( $table, $where );

		if ( false === $deleted ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new ProductPersistenceException( 'Failed to delete a row from ' . $table . '.' );
		}
	}

	/**
	 * Fetch a single row.
	 *
	 * @since 1.0.0
	 *
	 * @param callable(): mixed $query Runs the prepared query.
	 * @return object|null The row object, or null.
	 */
	private function fetchRow( callable $query ): ?object {
		return $query();
	}

	/**
	 * Fetch a result set.
	 *
	 * @since 1.0.0
	 *
	 * @param callable(): mixed $query Runs the prepared query.
	 * @return array<int, object> The rows (empty on no match).
	 */
	private function fetchRows( callable $query ): array {
		$rows = $query();

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Fetch a single column list.
	 *
	 * @since 1.0.0
	 *
	 * @param callable(): mixed $query Runs the prepared query.
	 * @return array<int, mixed> The column values.
	 */
	private function fetchCol( callable $query ): array {
		$col = $query();

		return is_array( $col ) ? $col : array();
	}

	/**
	 * Fetch a single scalar.
	 *
	 * @since 1.0.0
	 *
	 * @param callable(): mixed $query Runs the prepared query.
	 * @return string|null The scalar value, or null.
	 */
	private function fetchVar( callable $query ): ?string {
		$value = $query();

		return null === $value ? null : (string) $value;
	}
}
