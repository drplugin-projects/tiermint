<?php
/**
 * Product domain service: all product business logic and validation.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Products;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Product CRUD, validation and business rules.
 *
 * It validates category and tag assignment through {@see TermService}.
 *
 * @since 1.0.0
 */
class ProductService {

	/**
	 * The repository this service reads and writes products through.
	 *
	 * @var ProductRepository
	 */
	private ProductRepository $repository;

	/**
	 * Assigns product categories and tags.
	 *
	 * @var TermService
	 */
	private TermService $terms;

	/**
	 * Allowed status transitions, keyed by the current status value.
	 *
	 * Archived products return through draft. Identity transitions are allowed.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const STATUS_TRANSITIONS = array(
		'draft'     => array( 'published', 'archived' ),
		'published' => array( 'archived', 'draft' ),
		'archived'  => array( 'draft' ),
	);

	/**
	 * Wire the service with its repository and the term service.
	 *
	 * @since 1.0.0
	 *
	 * @param ProductRepository $repository Reads and writes products.
	 * @param TermService       $terms      The term service for category/tag assignment.
	 */
	public function __construct( ProductRepository $repository, TermService $terms ) {
		$this->repository = $repository;
		$this->terms      = $terms;
	}

	/**
	 * Validate and create a product.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $data Product input.
	 * @return Product Persisted product.
	 *
	 * @throws ProductValidationException  If input is invalid.
	 * @throws ProductPersistenceException If a write fails.
	 */
	public function create( array $data ): Product {
		$errors = array();

		$name   = trim( (string) ( $data['name'] ?? '' ) );
		$type   = $this->resolveType( $data['type'] ?? null, $errors );
		$status = $this->resolveStatus( $data['status'] ?? null, $errors );

		if ( '' === $name ) {
			$errors[] = __( 'A product name is required.', 'tiermint' );
		}

		// A bad name/type/status blocks everything downstream, so stop here.
		if ( array() !== $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer/validation messages, surfaced as structured API errors, never echoed as HTML.
			throw ProductValidationException::withErrors( $errors );
		}

		$isLicensed  = (bool) ( $data['is_licensed'] ?? true );
		$slug        = $this->uniqueSlug( $this->slugSource( $data, $name ), null );
		$updateSlug  = $this->resolveUpdateSlug( $data, $isLicensed, null, $errors );
		$prices      = $this->buildPrices( $data['prices'] ?? array(), $errors );
		$settings    = $this->resolveSettings( $data['settings'] ?? array(), null, $prices, $errors );
		$bundleItems = ProductType::Bundle === $type
			? $this->buildBundleItems( $data['bundle_items'] ?? array(), null, $errors )
			: array();

		$this->assertPublishable( $status, $isLicensed, $prices, $errors );
		$this->validateTermAssignments( $data, $errors );

		if ( array() !== $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer/validation messages, surfaced as structured API errors, never echoed as HTML.
			throw ProductValidationException::withErrors( $errors );
		}

		$product = Product::fromArray(
			array(
				'name'        => $name,
				'slug'        => $slug,
				'status'      => $status->value,
				'type'        => $type->value,
				'overview'    => $data['overview'] ?? null,
				'description' => $data['description'] ?? null,
				'is_licensed' => $isLicensed,
				'update_slug' => $updateSlug,
				'files'       => $data['files'] ?? array(),
				'settings'    => $settings,
			),
			$prices,
			$bundleItems
		);

		$saved = $this->requireProduct( $this->repository->create( $product ) );

		try {
			$this->assignTerms( $saved, $data );
		} catch ( TermPersistenceException $e ) {
			// The product row already landed, so a failed relationship write would
			// otherwise strand a product with a partial or missing taxonomy. Compensate
			// by cascading the just-created product away, then report a generic product
			// persistence failure. The term exception's internal detail (the relationship
			// table name) is discarded here and never reaches the controller or client.
			$this->repository->delete( (int) $saved->id() );

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Generic developer-facing message with no request data; the controller maps it to a generic 500.
			throw new ProductPersistenceException( 'The product could not be saved with its categories and tags.' );
		}

		return $saved;
	}

	/**
	 * Validate and update a product.
	 *
	 * @since 1.0.0
	 *
	 * @param int                  $id   Product id.
	 * @param array<string, mixed> $data Partial product input.
	 * @return Product Persisted product.
	 *
	 * @throws ProductNotFoundException    If the product does not exist.
	 * @throws ProductValidationException  If input is invalid.
	 * @throws ProductPersistenceException If a write fails.
	 */
	public function update( int $id, array $data ): Product {
		$existing = $this->repository->find( $id );

		if ( null === $existing ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer/validation message, surfaced as a structured 404, never echoed as HTML.
			throw ProductNotFoundException::forId( $id );
		}

		$errors = array();
		$status = $this->resolveTransition( $existing->status(), $data, $errors );

		// An invalid or forbidden transition is fatal on its own; stop before edits.
		if ( array() !== $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer/validation messages, surfaced as structured API errors, never echoed as HTML.
			throw ProductValidationException::withErrors( $errors );
		}

		$name = array_key_exists( 'name', $data ) ? trim( (string) $data['name'] ) : $existing->name();
		if ( '' === $name ) {
			$errors[] = __( 'A product name cannot be empty.', 'tiermint' );
		}

		$type       = $this->resolveType( $data['type'] ?? $existing->type()->value, $errors );
		$isLicensed = array_key_exists( 'is_licensed', $data ) ? (bool) $data['is_licensed'] : $existing->isLicensed();

		$slug = ( isset( $data['slug'] ) && '' !== trim( (string) $data['slug'] ) )
			? $this->uniqueSlug( (string) $data['slug'], $id )
			: $existing->slug();

		// An unlicensed product carries no update slug, so de-licensing clears any
		// existing one even when the request omits update_slug. A dead slug would
		// otherwise linger as a stale /update-check routing key.
		if ( ! $isLicensed ) {
			$updateSlug = null;
		} elseif ( array_key_exists( 'update_slug', $data ) ) {
			$updateSlug = $this->resolveUpdateSlug( $data, $isLicensed, $id, $errors );
		} else {
			$updateSlug = $existing->updateSlug();
		}

		$prices = array_key_exists( 'prices', $data )
			? $this->buildPrices( $data['prices'], $errors, $this->existingRecurringTierIds( $existing ) )
			: $existing->prices();

		$settings = array_key_exists( 'settings', $data )
			? $this->resolveSettings( $data['settings'], $existing, $prices, $errors )
			: $existing->settings();

		$bundleItems = $this->resolveBundleItems( $type, $data, $existing, $errors );

		$this->assertPublishable( $status, $isLicensed, $prices, $errors );
		$this->validateTermAssignments( $data, $errors );

		if ( array() !== $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer/validation messages, surfaced as structured API errors, never echoed as HTML.
			throw ProductValidationException::withErrors( $errors );
		}

		$product = Product::fromArray(
			array(
				'id'          => $id,
				'name'        => $name,
				'slug'        => $slug,
				'status'      => $status->value,
				'type'        => $type->value,
				'overview'    => array_key_exists( 'overview', $data ) ? $data['overview'] : $existing->overview(),
				'description' => array_key_exists( 'description', $data ) ? $data['description'] : $existing->description(),
				'is_licensed' => $isLicensed,
				'update_slug' => $updateSlug,
				'files'       => array_key_exists( 'files', $data ) ? ( $data['files'] ?? array() ) : $existing->files(),
				'settings'    => $settings,
				'created_at'  => $existing->createdAt(),
			),
			$prices,
			$bundleItems
		);

		$this->repository->update( $product );

		$saved = $this->requireProduct( $id );

		try {
			$this->assignTerms( $saved, $data );
		} catch ( TermPersistenceException $e ) {
			// A relationship write failed after the product columns were updated. Map it
			// to a generic product persistence failure so the controller returns a generic
			// 500 and the term exception's internal table detail never reaches the client.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Generic developer-facing message with no request data; the controller maps it to a generic 500.
			throw new ProductPersistenceException( 'The product could not be saved with its categories and tags.' );
		}

		return $saved;
	}

	/**
	 * Delete a product, failing loudly if it does not exist.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The product id to delete.
	 * @return void
	 *
	 * @throws ProductNotFoundException When no product has the given id.
	 */
	public function delete( int $id ): void {
		if ( null === $this->repository->find( $id ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer/validation message, surfaced as a structured 404, never echoed as HTML.
			throw ProductNotFoundException::forId( $id );
		}

		$this->repository->delete( $id );
	}

	/**
	 * Load a product by id, or null when it does not exist.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The product id.
	 * @return Product|null The product, or null.
	 */
	public function get( int $id ): ?Product {
		return $this->repository->find( $id );
	}

	/**
	 * Load a product by slug, or null when none carries it.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug The product slug.
	 * @return Product|null The product, or null.
	 */
	public function getBySlug( string $slug ): ?Product {
		return $this->repository->findBySlug( $slug );
	}

	/**
	 * List products for a collection view (delegates to the repository query).
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $args Query args (status, search, paging, ordering).
	 * @return array<int, Product> The matching products.
	 */
	public function list( array $args ): array {
		return $this->repository->query( $args );
	}

	/**
	 * Count products matching a collection query (for pagination).
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $args Query args (status, search).
	 * @return int The total matching product count.
	 */
	public function count( array $args ): int {
		return $this->repository->countQuery( $args );
	}

	/**
	 * Resolve a product type value, recording an error for an unknown type.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed              $value  The raw type value (or null for the default).
	 * @param array<int, string> $errors Error accumulator, appended to by reference.
	 * @return ProductType The resolved type (defaults to digital when omitted).
	 */
	private function resolveType( $value, array &$errors ): ProductType {
		if ( null === $value ) {
			return ProductType::Digital;
		}

		$type = ProductType::tryFrom( (string) $value );

		if ( null === $type ) {
			/* translators: %s: the submitted product type value. */
			$errors[] = sprintf( __( '"%s" is not a valid product type.', 'tiermint' ), (string) $value );

			return ProductType::Digital;
		}

		return $type;
	}

	/**
	 * Resolve a product status value, recording an error for an unknown status.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed              $value  The raw status value (or null for the default).
	 * @param array<int, string> $errors Error accumulator, appended to by reference.
	 * @return ProductStatus The resolved status (defaults to draft when omitted).
	 */
	private function resolveStatus( $value, array &$errors ): ProductStatus {
		if ( null === $value ) {
			return ProductStatus::Draft;
		}

		$status = ProductStatus::tryFrom( (string) $value );

		if ( null === $status ) {
			/* translators: %s: the submitted product status value. */
			$errors[] = sprintf( __( '"%s" is not a valid product status.', 'tiermint' ), (string) $value );

			return ProductStatus::Draft;
		}

		return $status;
	}

	/**
	 * Resolve the target status for an update, enforcing the transition rules.
	 *
	 * @since 1.0.0
	 *
	 * @param ProductStatus        $current The product's current status.
	 * @param array<string, mixed> $data  The update input.
	 * @param array<int, string>   $errors  Error accumulator, appended to by reference.
	 * @return ProductStatus The target status (current when unchanged or invalid).
	 */
	private function resolveTransition( ProductStatus $current, array $data, array &$errors ): ProductStatus {
		if ( ! array_key_exists( 'status', $data ) ) {
			return $current;
		}

		$target = ProductStatus::tryFrom( (string) $data['status'] );

		if ( null === $target ) {
			/* translators: %s: the submitted product status value. */
			$errors[] = sprintf( __( '"%s" is not a valid product status.', 'tiermint' ), (string) $data['status'] );

			return $current;
		}

		if ( ! $this->canTransition( $current, $target ) ) {
			/* translators: 1: the current product status, 2: the requested product status. */
			$errors[] = sprintf( __( 'Cannot change product status from "%1$s" to "%2$s".', 'tiermint' ), $current->value, $target->value );

			return $current;
		}

		return $target;
	}

	/**
	 * Whether a status transition is permitted (identity transitions always are).
	 *
	 * @since 1.0.0
	 *
	 * @param ProductStatus $from The current status.
	 * @param ProductStatus $to   The requested status.
	 * @return bool True when the transition is allowed.
	 */
	private function canTransition( ProductStatus $from, ProductStatus $to ): bool {
		if ( $from === $to ) {
			return true;
		}

		return in_array( $to->value, self::STATUS_TRANSITIONS[ $from->value ] ?? array(), true );
	}

	/**
	 * The source string a slug is generated from: an explicit slug when supplied,
	 * otherwise the product name.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $data The product input.
	 * @param string               $name The resolved product name.
	 * @return string The slug source.
	 */
	private function slugSource( array $data, string $name ): string {
		return ( isset( $data['slug'] ) && '' !== trim( (string) $data['slug'] ) )
			? (string) $data['slug']
			: $name;
	}

	/**
	 * Generate a slug unique across products, suffixing WP-style on collision
	 * (`base`, `base-2`, `base-3`, …).
	 *
	 * @since 1.0.0
	 *
	 * @param string   $source   The string to slugify.
	 * @param int|null $exceptId A product id to exclude from the collision check.
	 * @return string The unique slug.
	 */
	private function uniqueSlug( string $source, ?int $exceptId ): string {
		$base = sanitize_title( $source );

		if ( '' === $base ) {
			$base = 'product';
		}

		$candidate = $base;
		$suffix    = 1;

		while ( $this->repository->slugExists( $candidate, $exceptId ) ) {
			++$suffix;
			$candidate = $base . '-' . $suffix;
		}

		return $candidate;
	}

	/**
	 * Resolve a unique update slug for a licensed product.
	 *
	 * A collision is an error because this is an exact update route key.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $data       The product input.
	 * @param bool                 $isLicensed Whether the product is licensed.
	 * @param int|null             $exceptId   A product id to exclude from the check.
	 * @param array<int, string>   $errors     Error accumulator, appended by reference.
	 * @return string|null The resolved update slug, or null.
	 */
	private function resolveUpdateSlug( array $data, bool $isLicensed, ?int $exceptId, array &$errors ): ?string {
		if ( ! $isLicensed || ! isset( $data['update_slug'] ) || '' === trim( (string) $data['update_slug'] ) ) {
			return null;
		}

		$candidate = sanitize_title( (string) $data['update_slug'] );

		if ( '' !== $candidate && $this->repository->updateSlugExists( $candidate, $exceptId ) ) {
			/* translators: %s: the submitted update slug value. */
			$errors[] = sprintf( __( 'The update slug "%s" is already in use by another product.', 'tiermint' ), $candidate );

			return null;
		}

		return '' === $candidate ? null : $candidate;
	}

	/**
	 * Whether recurring price tiers may be saved.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when an extension enables recurring tiers.
	 */
	public static function recurringTiersSupported(): bool {
		/**
 * Filters recurring price tier support.
 *
 * The free core must reject tiers it cannot renew.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $supported Whether recurring tiers may be saved and rendered.
		 */
		return (bool) apply_filters( 'tiermint_recurring_tiers_supported', false );
	}

	/**
	 * Build price objects and collect validation errors.
	 *
	 * Existing recurring tiers stay valid when recurring billing is unavailable.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed              $pricesData               The raw prices input (expected: a list of arrays).
	 * @param array<int, string> $errors                   Error accumulator, appended by reference.
	 * @param array<int, int>    $grandfatheredRecurringIds Ids of the product's already-persisted
	 *                                                     recurring tiers, exempt from the gate.
	 * @return array<int, ProductPrice> The built price tiers.
	 */
	private function buildPrices( $pricesData, array &$errors, array $grandfatheredRecurringIds = array() ): array {
		if ( ! is_array( $pricesData ) ) {
			return array();
		}

		$prices = array();

		foreach ( $pricesData as $priceData ) {
			if ( ! is_array( $priceData ) ) {
				$errors[] = __( 'Each price must be provided as an object.', 'tiermint' );
				continue;
			}

			try {
				$price = ProductPrice::fromArray( $priceData );
			} catch ( ProductException $e ) {
				$errors[] = $e->getMessage();
				continue;
			}

			// Gate the license length here, on the write path only, so a day count
			// can never go back into the strtotime-string column (the corruption an
			// older build caused) while legacy freeform rows stay saveable. Hydration does
			// not run this, so an existing row still loads.
			$lengthError = ProductPrice::licenseLengthError( $price->licenseLength(), $price->isSubscription() );

			if ( null !== $lengthError ) {
				$errors[] = $lengthError;
				continue;
			}

			if ( $price->isSubscription() && ! self::recurringTiersSupported() ) {
				// Grandfather an existing recurring tier of this product so an admin
				// who removed Pro is not locked out of editing the product at all;
				// anything new (no id, or a one-time tier newly flipped to recurring)
				// is still refused because the core cannot bill it.
				$priceId = $price->id();

				if ( null === $priceId || ! in_array( $priceId, $grandfatheredRecurringIds, true ) ) {
					$errors[] = __( 'Recurring price tiers require TierMint Pro.', 'tiermint' );
					continue;
				}
			}

			$prices[] = $price;
		}

		return $prices;
	}

	/**
	 * Get persisted recurring-tier IDs that remain valid on update.
	 *
	 * @since 1.0.0
	 *
	 * @param Product $product The product being updated.
	 * @return array<int, int> The persisted recurring tier ids.
	 */
	private function existingRecurringTierIds( Product $product ): array {
		$ids = array();

		foreach ( $product->prices() as $price ) {
			$id = $price->id();

			if ( $price->isSubscription() && null !== $id ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Resolve settings input.
	 *
	 * Validate upgrade paths and retain unknown settings.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed                    $settingsData The raw settings input.
	 * @param Product|null             $existing     The product being updated, or null on create.
	 * @param array<int, ProductPrice> $prices       The resolved price tiers of this save.
	 * @param array<int, string>       $errors       Error accumulator, appended by reference.
	 * @return array<string, mixed> The resolved settings blob.
	 */
	private function resolveSettings( $settingsData, ?Product $existing, array $prices, array &$errors ): array {
		$settings = is_array( $settingsData ) ? $settingsData : array();

		if ( ! array_key_exists( 'upgrade_paths', $settings ) ) {
			return $settings;
		}

		$resolved = $this->resolveUpgradePaths( $settings['upgrade_paths'], $existing, $prices, $errors );

		// An empty map means "no switches allowed", which is also the meaning of
		// an absent key, so the blob stays clean rather than storing empty husks.
		if ( array() === $resolved ) {
			unset( $settings['upgrade_paths'] );
		} else {
			$settings['upgrade_paths'] = $resolved;
		}

		return $settings;
	}

	/**
	 * Validate upgrade paths between recurring prices of this product.
	 *
	 * Existing paths remain valid when recurring billing is unavailable.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed                    $raw      The raw upgrade_paths input.
	 * @param Product|null             $existing The product being updated, or null on create.
	 * @param array<int, ProductPrice> $prices   The resolved price tiers of this save.
	 * @param array<int, string>       $errors   Error accumulator, appended by reference.
	 * @return array<int, array<int, int>> The validated, normalized map (possibly empty).
	 */
	private function resolveUpgradePaths( $raw, ?Product $existing, array $prices, array &$errors ): array {
		$stored = array();

		if ( null !== $existing ) {
			$existingSettings = $existing->settings();
			$stored           = $this->normalizeUpgradePaths( $existingSettings['upgrade_paths'] ?? array() ) ?? array();
		}

		$submitted = $this->normalizeUpgradePaths( $raw );

		if ( null === $submitted ) {
			$errors[] = __( 'Plan switch targets must be a map of price tier ids to lists of price tier ids.', 'tiermint' );

			return $stored;
		}

		if ( ! self::recurringTiersSupported() ) {
			// Grandfather the persisted config so an admin who removed Pro can
			// keep saving the product (the SPA resubmits stored settings
			// verbatim); anything new or changed is refused because nothing in
			// the free core can execute a plan switch.
			if ( $submitted === $stored ) {
				return $stored;
			}

			$errors[] = __( 'Configuring plan switch targets requires TierMint Pro.', 'tiermint' );

			return $stored;
		}

		$recurringIds = array();

		foreach ( $prices as $price ) {
			$id = $price->id();

			if ( $price->isSubscription() && null !== $id ) {
				$recurringIds[] = $id;
			}
		}

		$validated = array();

		foreach ( $submitted as $source => $targets ) {
			if ( ! in_array( $source, $recurringIds, true ) ) {
				/* translators: %d: the submitted price tier id. */
				$errors[] = sprintf( __( 'Plan switch targets reference price tier #%d, which is not one of this product\'s recurring tiers.', 'tiermint' ), $source );
				continue;
			}

			$list = array();

			foreach ( $targets as $target ) {
				// A tier switching to itself is meaningless; drop it quietly
				// rather than failing the whole save over a no-op.
				if ( $target === $source ) {
					continue;
				}

				if ( ! in_array( $target, $recurringIds, true ) ) {
					/* translators: %d: the submitted price tier id. */
					$errors[] = sprintf( __( 'Plan switch targets reference price tier #%d, which is not one of this product\'s recurring tiers.', 'tiermint' ), $target );
					continue;
				}

				$list[] = $target;
			}

			if ( array() !== $list ) {
				$validated[ $source ] = $list;
			}
		}

		return $validated;
	}

	/**
	 * Normalize upgrade paths for stable comparison and storage.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $raw The raw upgrade-paths value.
	 * @return array<int, array<int, int>>|null The canonical map, or null when the
	 *                                          shape is not a map of id lists.
	 */
	private function normalizeUpgradePaths( $raw ): ?array {
		if ( null === $raw || array() === $raw ) {
			return array();
		}

		if ( ! is_array( $raw ) ) {
			return null;
		}

		$map = array();

		foreach ( $raw as $source => $targets ) {
			if ( ! is_numeric( $source ) || (int) $source <= 0 || ! is_array( $targets ) ) {
				return null;
			}

			$list = array();

			foreach ( $targets as $target ) {
				if ( ! is_numeric( $target ) || (int) $target <= 0 ) {
					return null;
				}

				$list[] = (int) $target;
			}

			$list = array_values( array_unique( $list ) );
			sort( $list );

			if ( array() !== $list ) {
				$map[ (int) $source ] = $list;
			}
		}

		ksort( $map );

		return $map;
	}

	/**
	 * Build bundle items and collect validation errors.
	 *
	 * Children must be published, non-bundle products and unique.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed              $itemsData The raw bundle-items input (list of arrays).
	 * @param int|null           $ownId     The bundle's own id (for self-reference guarding), or null on create.
	 * @param array<int, string> $errors    Error accumulator, appended by reference.
	 * @return array<int, BundleItem> The validated bundle items.
	 */
	private function buildBundleItems( $itemsData, ?int $ownId, array &$errors ): array {
		if ( ! is_array( $itemsData ) ) {
			return array();
		}

		$items   = array();
		$seenIds = array();

		foreach ( $itemsData as $itemData ) {
			if ( ! is_array( $itemData ) ) {
				$errors[] = __( 'Each bundle item must be provided as an object.', 'tiermint' );
				continue;
			}

			try {
				$item = BundleItem::fromArray( $itemData );
			} catch ( ProductException $e ) {
				$errors[] = $e->getMessage();
				continue;
			}

			$childId = $item->childProductId();

			// A repeated child would violate the bundle_items composite key on insert,
			// so catch it in validation before any row is written.
			if ( in_array( $childId, $seenIds, true ) ) {
				/* translators: %d: the product id. */
				$errors[] = sprintf( __( 'Bundle contains product #%d more than once.', 'tiermint' ), $childId );
				continue;
			}

			$seenIds[] = $childId;

			$this->validateBundleChild( $item, $ownId, $errors );
			$items[] = $item;
		}

		return $items;
	}

	/**
	 * Validate a single bundle child against the composition rules.
	 *
	 * @since 1.0.0
	 *
	 * @param BundleItem         $item   The bundle item to validate.
	 * @param int|null           $ownId  The bundle's own id, or null on create.
	 * @param array<int, string> $errors Error accumulator, appended by reference.
	 * @return void
	 */
	private function validateBundleChild( BundleItem $item, ?int $ownId, array &$errors ): void {
		// A bundle can never contain itself, including the request that turns a
		// product into a bundle (where the child would still read as digital).
		if ( null !== $ownId && $item->childProductId() === $ownId ) {
			$errors[] = __( 'A bundle cannot contain itself.', 'tiermint' );

			return;
		}

		$child = $this->repository->find( $item->childProductId() );

		if ( null === $child ) {
			/* translators: %d: the child product id. */
			$errors[] = sprintf( __( 'Bundle child product %d does not exist.', 'tiermint' ), $item->childProductId() );

			return;
		}

		if ( $child->isBundle() ) {
			/* translators: %d: the child product id. */
			$errors[] = sprintf( __( 'Bundle child product %d is itself a bundle; bundles cannot be nested.', 'tiermint' ), $item->childProductId() );
		}

		if ( ProductStatus::Published !== $child->status() ) {
			/* translators: %d: the child product id. */
			$errors[] = sprintf( __( 'Bundle child product %d must be published to be included in a bundle.', 'tiermint' ), $item->childProductId() );
		}
	}

	/**
	 * Resolve the bundle composition for an update: bundle types keep or replace
	 * their items; non-bundle types always have an empty composition.
	 *
	 * @since 1.0.0
	 *
	 * @param ProductType          $type     The resolved product type.
	 * @param array<string, mixed> $data   The update input.
	 * @param Product              $existing The current product.
	 * @param array<int, string>   $errors   Error accumulator, appended by reference.
	 * @return array<int, BundleItem> The resolved bundle items.
	 */
	private function resolveBundleItems( ProductType $type, array $data, Product $existing, array &$errors ): array {
		if ( ProductType::Bundle !== $type ) {
			return array();
		}

		if ( array_key_exists( 'bundle_items', $data ) ) {
			return $this->buildBundleItems( $data['bundle_items'], $existing->id(), $errors );
		}

		return $existing->bundleItems();
	}

	/**
	 * Enforce the publish invariant: a licensed product cannot be published without
	 * at least one active price tier.
	 *
	 * @since 1.0.0
	 *
	 * @param ProductStatus            $status     The target status.
	 * @param bool                     $isLicensed Whether the product is licensed.
	 * @param array<int, ProductPrice> $prices     The product's price tiers.
	 * @param array<int, string>       $errors     Error accumulator, appended by reference.
	 * @return void
	 */
	private function assertPublishable( ProductStatus $status, bool $isLicensed, array $prices, array &$errors ): void {
		if ( ProductStatus::Published !== $status || ! $isLicensed ) {
			return;
		}

		foreach ( $prices as $price ) {
			if ( $price->isActive() ) {
				return;
			}
		}

		$errors[] = __( 'A licensed product must have at least one active price before it can be published.', 'tiermint' );
	}

	/**
	 * Validate named category and tag IDs before a write.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $data   The product input.
	 * @param array<int, string>   $errors Error accumulator, appended by reference.
	 * @return void
	 */
	private function validateTermAssignments( array $data, array &$errors ): void {
		if ( array_key_exists( 'categories', $data ) ) {
			foreach ( $this->terms->validateAssignable( $this->termIdList( $data['categories'] ), ProductTaxonomy::Category ) as $error ) {
				$errors[] = $error;
			}
		}

		if ( array_key_exists( 'tags', $data ) ) {
			foreach ( $this->terms->validateAssignable( $this->termIdList( $data['tags'] ), ProductTaxonomy::Tag ) as $error ) {
				$errors[] = $error;
			}
		}
	}

	/**
	 * Apply named category and tag assignments after saving.
	 *
	 * An empty list clears its taxonomy.
	 *
	 * @since 1.0.0
	 *
	 * @param Product              $product The persisted, reloaded product.
	 * @param array<string, mixed> $data    The product input.
	 * @return void
	 */
	private function assignTerms( Product $product, array $data ): void {
		if ( array_key_exists( 'categories', $data ) ) {
			$this->terms->setProductTerms( $product, ProductTaxonomy::Category, $this->termIdList( $data['categories'] ) );
		}

		if ( array_key_exists( 'tags', $data ) ) {
			$this->terms->setProductTerms( $product, ProductTaxonomy::Tag, $this->termIdList( $data['tags'] ) );
		}
	}

	/**
	 * Normalize a raw taxonomy input value into a de-duplicated list of positive-ish
	 * integer term ids. A non-array value (or absent input) yields an empty list.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value The raw term-id input (expected: a list of ids).
	 * @return array<int, int> The normalized term ids.
	 */
	private function termIdList( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_values( array_unique( array_map( 'intval', $value ) ) );
	}

	/**
	 * Reload a product by id after a write, failing loudly if it vanished.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The product id.
	 * @return Product The reloaded product.
	 *
	 * @throws ProductNotFoundException When the product cannot be reloaded.
	 */
	private function requireProduct( int $id ): Product {
		$product = $this->repository->find( $id );

		if ( null === $product ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer/validation message, surfaced as a structured error, never echoed as HTML.
			throw ProductNotFoundException::forId( $id );
		}

		return $product;
	}
}
