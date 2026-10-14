<?php
/**
 * Term domain service: all product-taxonomy business logic and validation.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Products;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Validates product terms and assignments.
 *
 * Writes use {@see TermRepository} and {@see TermRelationshipRepository}. Mutations fire term hooks.
 *
 * @since 1.0.0
 */
class TermService {

	/**
	 * The repository terms are read and written through.
	 *
	 * @var TermRepository
	 */
	private TermRepository $terms;

	/**
	 * The repository product-term assignments are written through.
	 *
	 * @var TermRelationshipRepository
	 */
	private TermRelationshipRepository $relationships;

	/**
	 * Wire the service with its two repositories.
	 *
	 * @since 1.0.0
	 *
	 * @param TermRepository             $terms         Reads and writes terms.
	 * @param TermRelationshipRepository $relationships Reads and writes term assignments.
	 */
	public function __construct( TermRepository $terms, TermRelationshipRepository $relationships ) {
		$this->terms         = $terms;
		$this->relationships = $relationships;
	}

	/**
	 * Create and reload a validated term.
	 *
	 * Tags are flat. Category parents and slugs are validated.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $data The term input.
	 * @return Term The reloaded, persisted term.
	 *
	 * @throws TermValidationException When any field or invariant is invalid.
	 */
	public function create( array $data ): Term {
		$errors = array();

		$taxonomy = $this->resolveTaxonomy( $data['taxonomy'] ?? null, $errors );
		$name     = trim( (string) ( $data['name'] ?? '' ) );

		if ( '' === $name ) {
			$errors[] = __( 'A term name is required.', 'tiermint' );
		}

		// Validate taxonomy and name before resolving a parent.
		if ( array() !== $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Validation messages surfaced as structured API errors, never echoed as HTML.
			throw TermValidationException::withErrors( $errors );
		}

		$parent = $this->resolveParent( $taxonomy, $this->requestedParent( $data ), null, $errors );
		$slug   = $this->uniqueSlug( $this->slugSource( $data, $name ), $taxonomy, null );

		if ( array() !== $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Validation messages surfaced as structured API errors, never echoed as HTML.
			throw TermValidationException::withErrors( $errors );
		}

		$term = new Term(
			null,
			$taxonomy,
			$name,
			$slug,
			$parent,
			$this->resolveDescription( $data, null )
		);

		$created = $this->requireTerm( $this->terms->create( $term ) );

		/**
		 * Fires after a product term (category or tag) is created.
		 *
		 * @since 1.0.0
		 *
		 * @param Term $term The newly created term, reloaded from storage.
		 */
		do_action( 'tiermint_term_created', $created );

		return $created;
	}

	/**
	 * Update supplied fields and return the reloaded term.
	 *
	 * Taxonomy is immutable.
	 *
	 * @since 1.0.0
	 *
	 * @param int                  $id   The term id to update.
	 * @param array<string, mixed> $data The partial term input.
	 * @return Term The reloaded, persisted term.
	 *
	 * @throws TermNotFoundException   When no term has the given id.
	 * @throws TermValidationException When any field or invariant is invalid.
	 */
	public function update( int $id, array $data ): Term {
		$existing = $this->terms->find( $id );

		if ( null === $existing ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured 404, never echoed as HTML.
			throw TermNotFoundException::forId( $id );
		}

		$errors   = array();
		$taxonomy = $existing->taxonomy();

		$name = array_key_exists( 'name', $data ) ? trim( (string) $data['name'] ) : $existing->name();
		if ( '' === $name ) {
			$errors[] = __( 'A term name cannot be empty.', 'tiermint' );
		}

		$slug = ( isset( $data['slug'] ) && '' !== trim( (string) $data['slug'] ) )
			? $this->uniqueSlug( (string) $data['slug'], $taxonomy, $id )
			: $existing->slug();

		// Keep the current parent unless the request changes it.
		$requestedParent = array_key_exists( 'parent', $data ) ? $this->requestedParent( $data ) : $existing->parent();
		$parent          = $this->resolveParent( $taxonomy, $requestedParent, $id, $errors );

		if ( array() !== $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Validation messages surfaced as structured API errors, never echoed as HTML.
			throw TermValidationException::withErrors( $errors );
		}

		$term = new Term(
			$id,
			$taxonomy,
			$name,
			$slug,
			$parent,
			$this->resolveDescription( $data, $existing->description() ),
			$existing->createdAt()
		);

		$this->terms->update( $term );
		$updated = $this->requireTerm( $id );

		/**
		 * Fires after a product term (category or tag) is updated.
		 *
		 * @since 1.0.0
		 *
		 * @param Term $term The updated term, reloaded from storage.
		 */
		do_action( 'tiermint_term_updated', $updated );

		return $updated;
	}

	/**
	 * Delete a term after moving its children to its parent.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The term id to delete.
	 * @return void
	 *
	 * @throws TermNotFoundException When no term has the given id.
	 */
	public function delete( int $id ): void {
		$existing = $this->terms->find( $id );

		if ( null === $existing ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured 404, never echoed as HTML.
			throw TermNotFoundException::forId( $id );
		}

		// Keep child categories connected to the tree.
		foreach ( $this->terms->childrenOf( $id ) as $child ) {
			$this->terms->update( $this->withParent( $child, $existing->parent() ) );
		}

		$this->terms->delete( $id );

		/**
		 * Fires after a product term (category or tag) is deleted.
		 *
		 * @since 1.0.0
		 *
		 * @param Term $term The term as it was immediately before deletion.
		 */
		do_action( 'tiermint_term_deleted', $existing );
	}

	/**
	 * Load a term by id, or null when it does not exist.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The term id.
	 * @return Term|null The term, or null.
	 */
	public function get( int $id ): ?Term {
		return $this->terms->find( $id );
	}

	/**
	 * List every term of a taxonomy (delegates to the repository).
	 *
	 * @since 1.0.0
	 *
	 * @param ProductTaxonomy $taxonomy The taxonomy to list.
	 * @return array<int, Term> The terms of the taxonomy.
	 */
	public function listByTaxonomy( ProductTaxonomy $taxonomy ): array {
		return $this->terms->listByTaxonomy( $taxonomy );
	}

	/**
	 * Replace a product's terms in one taxonomy.
	 *
	 * Validate every id before writing and announce the stored ids.
	 *
	 * @since 1.0.0
	 *
	 * @param Product           $product  The product to assign terms to.
	 * @param ProductTaxonomy   $taxonomy The taxonomy the assignment covers.
	 * @param array<int, mixed> $termIds The term ids to assign.
	 * @return void
	 *
	 * @throws TermValidationException When any id is not a term of the taxonomy.
	 */
	public function setProductTerms( Product $product, ProductTaxonomy $taxonomy, array $termIds ): void {
		$normalized = array_values( array_unique( array_map( 'intval', $termIds ) ) );
		$errors     = $this->validateAssignable( $normalized, $taxonomy );

		if ( array() !== $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Validation messages surfaced as structured API errors, never echoed as HTML.
			throw TermValidationException::withErrors( $errors );
		}

		$this->relationships->replaceForProductTaxonomy( (int) $product->id(), $taxonomy, $normalized );

		/**
		 * Fires after a product's terms in one taxonomy are (re)assigned.
		 *
		 * @since 1.0.0
		 *
		 * @param Product $product  The product whose terms changed.
		 * @param string  $taxonomy The taxonomy value ('category' or 'tag').
		 * @param int[]   $termIds  The normalized, de-duplicated assigned term ids.
		 */
		do_action( 'tiermint_product_terms_set', $product, $taxonomy->value, $normalized );
	}

	/**
	 * Validate unique ids against a taxonomy.
	 *
	 * Returns one message for each invalid id.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, mixed> $termIds  The term ids to validate.
	 * @param ProductTaxonomy   $taxonomy The taxonomy the ids must belong to.
	 * @return array<int, string> The validation messages (empty when all ids are valid).
	 */
	public function validateAssignable( array $termIds, ProductTaxonomy $taxonomy ): array {
		$errors = array();

		foreach ( array_values( array_unique( array_map( 'intval', $termIds ) ) ) as $termId ) {
			$term = $this->terms->find( $termId );

			if ( null === $term || $term->taxonomy() !== $taxonomy ) {
				/* translators: 1: the term id, 2: the taxonomy value. */
				$errors[] = sprintf( __( 'Term %1$d is not a valid %2$s.', 'tiermint' ), $termId, $taxonomy->value );
			}
		}

		return $errors;
	}

	/**
	 * Return a product's existing terms in relationship order.
	 *
	 * @since 1.0.0
	 *
	 * @param int             $productId The product id.
	 * @param ProductTaxonomy $taxonomy  The taxonomy to read.
	 * @return array<int, Term> The assigned terms.
	 */
	public function termsForProduct( int $productId, ProductTaxonomy $taxonomy ): array {
		$terms = array();

		foreach ( $this->relationships->termIdsForProduct( $productId, $taxonomy ) as $termId ) {
			$term = $this->terms->find( $termId );

			if ( null !== $term ) {
				$terms[] = $term;
			}
		}

		return $terms;
	}

	/**
	 * Return terms for a product page, keyed by product id.
	 *
	 * Each product has an entry. Batch reads avoid one query per product.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, mixed> $productIds The product ids to read.
	 * @param ProductTaxonomy   $taxonomy   The taxonomy to read.
	 * @return array<int, array<int, Term>> Product id => assigned terms.
	 */
	public function termsForProducts( array $productIds, ProductTaxonomy $taxonomy ): array {
		$ids = array_values( array_unique( array_map( 'intval', $productIds ) ) );

		if ( array() === $ids ) {
			return array();
		}

		$assignments = $this->relationships->termIdsForProducts( $ids, $taxonomy );
		$termIds     = array_merge( array(), ...array_values( $assignments ) );
		$terms       = $this->terms->findMany( array_values( array_unique( $termIds ) ) );

		$map = array();

		foreach ( $ids as $productId ) {
			$map[ $productId ] = array();

			foreach ( $assignments[ $productId ] ?? array() as $termId ) {
				if ( isset( $terms[ $termId ] ) ) {
					$map[ $productId ][] = $terms[ $termId ];
				}
			}
		}

		return $map;
	}

	/**
	 * Resolve the taxonomy value, recording an error for a missing or unknown one.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed              $value  The raw taxonomy value (or null).
	 * @param array<int, string> $errors Error accumulator, appended to by reference.
	 * @return ProductTaxonomy The resolved taxonomy (category as a safe fallback).
	 */
	private function resolveTaxonomy( $value, array &$errors ): ProductTaxonomy {
		if ( null === $value || '' === (string) $value ) {
			$errors[] = __( 'A term taxonomy is required.', 'tiermint' );

			return ProductTaxonomy::Category;
		}

		$taxonomy = ProductTaxonomy::tryFrom( (string) $value );

		if ( null === $taxonomy ) {
			/* translators: %s: the submitted term taxonomy value. */
			$errors[] = sprintf( __( '"%s" is not a valid term taxonomy.', 'tiermint' ), (string) $value );

			return ProductTaxonomy::Category;
		}

		return $taxonomy;
	}

	/**
	 * The parent id requested by the input (0 when absent or non-positive).
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $data The term input.
	 * @return int The requested parent id, clamped to a non-negative value.
	 */
	private function requestedParent( array $data ): int {
		$parent = (int) ( $data['parent'] ?? 0 );

		return $parent > 0 ? $parent : 0;
	}

	/**
	 * Resolve a parent under the taxonomy rules.
	 *
	 * Tags are flat. Category parents cannot form a cycle.
	 *
	 * @since 1.0.0
	 *
	 * @param ProductTaxonomy    $taxonomy  The term's taxonomy.
	 * @param int                $requested The requested parent id (0 for top level).
	 * @param int|null           $selfId    The term's own id on update, or null on create.
	 * @param array<int, string> $errors    Error accumulator, appended to by reference.
	 * @return int The validated parent id (0 when invalid, alongside a recorded error).
	 */
	private function resolveParent( ProductTaxonomy $taxonomy, int $requested, ?int $selfId, array &$errors ): int {
		// Tags never nest; ignore any supplied parent rather than erroring on it.
		if ( ProductTaxonomy::Tag === $taxonomy ) {
			return 0;
		}

		if ( 0 === $requested ) {
			return 0;
		}

		if ( null !== $selfId && $requested === $selfId ) {
			$errors[] = __( 'A category cannot be its own parent.', 'tiermint' );

			return 0;
		}

		$parent = $this->terms->find( $requested );

		if ( null === $parent || ProductTaxonomy::Category !== $parent->taxonomy() ) {
			/* translators: %d: the parent category id. */
			$errors[] = sprintf( __( 'Parent category %d does not exist.', 'tiermint' ), $requested );

			return 0;
		}

		// A descendant cannot become the parent.
		if ( null !== $selfId && $this->isDescendantOf( $requested, $selfId ) ) {
			/* translators: 1: the category id, 2: the descendant category id. */
			$errors[] = sprintf( __( 'Category %1$d cannot be moved under its own descendant %2$d.', 'tiermint' ), $selfId, $requested );

			return 0;
		}

		return $requested;
	}

	/**
	 * Check whether a category is below another category.
	 *
	 * A visited set stops corrupt parent cycles.
	 *
	 * @since 1.0.0
	 *
	 * @param int $candidateId The term whose ancestry is walked.
	 * @param int $ancestorId  The term we are testing for as an ancestor.
	 * @return bool True when $ancestorId is an ancestor of $candidateId.
	 */
	private function isDescendantOf( int $candidateId, int $ancestorId ): bool {
		$visited = array();
		$current = $this->terms->find( $candidateId );

		while ( null !== $current && $current->parent() > 0 ) {
			$parentId = $current->parent();

			if ( $parentId === $ancestorId ) {
				return true;
			}

			if ( isset( $visited[ $parentId ] ) ) {
				break;
			}
			$visited[ $parentId ] = true;

			$current = $this->terms->find( $parentId );
		}

		return false;
	}

	/**
	 * The source string a slug is generated from: an explicit slug when supplied,
	 * otherwise the term name.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $data The term input.
	 * @param string               $name The resolved term name.
	 * @return string The slug source.
	 */
	private function slugSource( array $data, string $name ): string {
		return ( isset( $data['slug'] ) && '' !== trim( (string) $data['slug'] ) )
			? (string) $data['slug']
			: $name;
	}

	/**
	 * Generate a unique taxonomy slug with a numeric suffix.
	 *
	 * @since 1.0.0
	 *
	 * @param string          $source   The string to slugify.
	 * @param ProductTaxonomy $taxonomy The taxonomy the slug must be unique within.
	 * @param int|null        $exceptId A term id to exclude from the collision check.
	 * @return string The unique slug.
	 */
	private function uniqueSlug( string $source, ProductTaxonomy $taxonomy, ?int $exceptId ): string {
		$base = sanitize_title( $source );

		if ( '' === $base ) {
			$base = 'term';
		}

		$candidate = $base;
		$suffix    = 1;

		while ( $this->terms->slugExists( $candidate, $taxonomy, $exceptId ) ) {
			++$suffix;
			$candidate = $base . '-' . $suffix;
		}

		return $candidate;
	}

	/**
	 * Use the supplied description, or keep the fallback.
	 *
	 * Empty descriptions become null.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $data     The term input.
	 * @param string|null          $fallback The value to keep when no key is present.
	 * @return string|null The resolved description.
	 */
	private function resolveDescription( array $data, ?string $fallback ): ?string {
		if ( ! array_key_exists( 'description', $data ) ) {
			return $fallback;
		}

		$description = (string) ( $data['description'] ?? '' );

		return '' === trim( $description ) ? null : $description;
	}

	/**
	 * Build a copy of a term with a different parent, preserving every other field.
	 *
	 * @since 1.0.0
	 *
	 * @param Term $term     The term to copy.
	 * @param int  $parentId The new parent id.
	 * @return Term The re-parented term.
	 */
	private function withParent( Term $term, int $parentId ): Term {
		return new Term(
			$term->id(),
			$term->taxonomy(),
			$term->name(),
			$term->slug(),
			$parentId,
			$term->description(),
			$term->createdAt(),
			$term->updatedAt()
		);
	}

	/**
	 * Reload a term by id after a write, failing loudly if it vanished.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The term id.
	 * @return Term The reloaded term.
	 *
	 * @throws TermNotFoundException When the term cannot be reloaded.
	 */
	private function requireTerm( int $id ): Term {
		$term = $this->terms->find( $id );

		if ( null === $term ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured error, never echoed as HTML.
			throw TermNotFoundException::forId( $id );
		}

		return $term;
	}
}
