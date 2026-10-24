<?php
/**
 * Releases repository: the only Updates class that touches the releases table.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Updates;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DateTimeImmutable;
use DateTimeZone;
use DrPlugin\WP\TierMint\Database\Schema;

/**
 * Repository for release rows and release caches.
 *
 * It distinguishes database faults from missing rows and guards status changes.
 *
 * @since 1.0.0
 */
class ReleaseRepository {

	/**
	 * The object-cache group releases are cached under.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'tiermint';

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
	 * Insert a release and return its persisted record.
	 *
	 * @since 1.0.0
	 *
	 * @param Release $release The release to persist (must be unsaved).
	 * @return Release The hydrated release, carrying its new id and timestamps.
	 *
	 * @throws DuplicateReleaseVersionException When the version already exists for the product.
	 * @throws ReleasePersistenceException      When the insert fails for any other reason, or the
	 *                                          just-written row cannot be read back.
	 */
	public function create( Release $release ): Release {
		$now = gmdate( 'Y-m-d H:i:s' );

		$inserted = $this->wpdb->insert(
			$this->table(),
			array(
				'product_id'       => $release->productId(),
				'version'          => $release->version(),
				'storage_provider' => $release->storageProvider(),
				'storage_key'      => $release->storageKey(),
				'filesize'         => $release->filesize(),
				'checksum_sha256'  => $release->checksumSha256(),
				'requires_wp'      => $release->requiresWp(),
				'tested_up_to'     => $release->testedUpTo(),
				'requires_php'     => $release->requiresPhp(),
				'changelog'        => $release->changelog(),
				'status'           => $release->status()->value,
				'released_at'      => $this->formatInstant( $release->releasedAt() ),
				'created_at'       => $now,
				'updated_at'       => $now,
			)
		);

		if ( false === $inserted ) {
			if ( $this->versionCollisionExists( $release->productId(), $release->version(), null ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
				throw DuplicateReleaseVersionException::forProductAndVersion( $release->productId(), $release->version() );
			}

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new ReleasePersistenceException( 'Failed to insert release: ' . $this->wpdb->last_error );
		}

		$id = (int) $this->wpdb->insert_id;

		$this->forget( $id, $release->productId() );

		$stored = $this->find( $id );

		if ( null === $stored ) {
			// The row was just written; failing to read it back is a genuine fault, not a miss.
			throw new ReleasePersistenceException( 'Failed to read back a just-created release.' );
		}

		return $stored;
	}

	/**
	 * Load a release by id, reading through the object cache.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The release id.
	 * @return Release|null The release, or null when it does not exist.
	 *
	 * @throws ReleasePersistenceException When the read faults at the database.
	 */
	public function find( int $id ): ?Release {
		$cached = wp_cache_get( $this->idKey( $id ), self::CACHE_GROUP );

		if ( $cached instanceof Release ) {
			return $cached;
		}

		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$row = $this->getRowOrFail( fn () => $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table(), $id ) ) );

		if ( null === $row ) {
			return null;
		}

		$found = $this->fromRow( $row );
		$this->cache( $found );

		return $found;
	}

	/**
	 * List product releases in version order.
	 *
	 * @since 1.0.0
	 *
	 * @param int $productId The owning product id.
	 * @return array<int, Release> The product's releases, newest version first.
	 *
	 * @throws ReleasePersistenceException When the read faults at the database.
	 */
	public function listForProduct( int $productId ): array {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
		$rows = $this->getResultsOrFail( fn () => $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE product_id = %d', $this->table(), $productId ) ) );

		$releases = array_map( array( $this, 'fromRow' ), $rows );

		usort(
			$releases,
			static fn ( Release $a, Release $b ): int => version_compare( $b->version(), $a->version() )
		);

		return $releases;
	}

	/**
	 * Get the newest published release of a product.
	 *
	 * @since 1.0.0
	 *
	 * @param int $productId The owning product id.
	 * @return Release|null The latest published release, or null when none exists.
	 *
	 * @throws ReleasePersistenceException When the read faults at the database.
	 */
	public function latestPublished( int $productId ): ?Release {
		$cached = wp_cache_get( $this->latestKey( $productId ), self::CACHE_GROUP );

		if ( $cached instanceof Release ) {
			return $cached;
		}

		$wpdb = $this->wpdb;

		$row = $this->getRowOrFail(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table; this class decides what to cache.
			fn () => $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM %i' .
					" WHERE product_id = %d AND status = 'published'" .
					' ORDER BY released_at DESC, id DESC LIMIT 1',
					$this->table(),
					$productId
				)
			)
		);

		if ( null === $row ) {
			// No negative caching: the next publish must be visible immediately.
			return null;
		}

		$latest = $this->fromRow( $row );

		wp_cache_set( $this->idKey( (int) $latest->id() ), $latest, self::CACHE_GROUP );
		wp_cache_set( $this->latestKey( $productId ), $latest, self::CACHE_GROUP );

		return $latest;
	}

	/**
	 * List a product's published releases, newest first.
	 *
	 * Newest means the latest release date, then the highest id. The list is cached per
	 * product; an empty list is not, so a first publish is visible at once.
	 *
	 * @since 1.0.0
	 *
	 * @param int $productId The owning product id.
	 * @return array<int, Release> The product's published releases, newest first.
	 *
	 * @throws ReleasePersistenceException When the read faults at the database.
	 */
	public function published( int $productId ): array {
		$cached = wp_cache_get( $this->publishedKey( $productId ), self::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$wpdb = $this->wpdb;

		$rows = $this->getResultsOrFail(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table; this class decides what to cache.
			fn () => $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i' .
					" WHERE product_id = %d AND status = 'published'" .
					' ORDER BY released_at DESC, id DESC',
					$this->table(),
					$productId
				)
			)
		);

		$releases = array_map( array( $this, 'fromRow' ), $rows );

		if ( array() !== $releases ) {
			// No negative caching: the next publish must be visible immediately.
			wp_cache_set( $this->publishedKey( $productId ), $releases, self::CACHE_GROUP );
		}

		return $releases;
	}

	/**
	 * Update release fields and clear affected caches.
	 *
	 * With a column list, only those columns are written, so a stale copy cannot undo a
	 * concurrent edit of another column. Status and the release date never take part in a
	 * scoped write: status moves go through {@see transitionStatus()}.
	 *
	 * @since 1.0.0
	 *
	 * @param Release                 $release The release to persist (must carry an id).
	 * @param array<int, string>|null $columns The columns to write, or null for the whole row.
	 * @return void
	 *
	 * @throws ReleaseException                 When the release has no id, or the column list
	 *                                          is empty or names a column a scoped write may not set.
	 * @throws DuplicateReleaseVersionException When the new version already exists for the product.
	 * @throws ReleasePersistenceException      When the write faults for any other reason.
	 */
	public function update( Release $release, ?array $columns = null ): void {
		$id = $release->id();

		if ( null === $id ) {
			throw new ReleaseException( 'Cannot update a release that has no id.' );
		}

		$values = $this->updatableValues( $release );

		if ( null === $columns ) {
			$values['product_id']  = $release->productId();
			$values['status']      = $release->status()->value;
			$values['released_at'] = $this->formatInstant( $release->releasedAt() );
		} else {
			$unknown = array_diff( $columns, array_keys( $values ) );

			if ( array() === $columns || array() !== $unknown ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
				throw new ReleaseException( 'A scoped release update needs at least one column, and only these: ' . implode( ', ', array_keys( $values ) ) . '.' );
			}

			$values = array_intersect_key( $values, array_flip( $columns ) );
		}

		$values['updated_at'] = gmdate( 'Y-m-d H:i:s' );

		$updated = $this->wpdb->update( $this->table(), $values, array( 'id' => $id ) );

		if ( false === $updated ) {
			if ( array_key_exists( 'version', $values ) && $this->versionCollisionExists( $release->productId(), $release->version(), $id ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
				throw DuplicateReleaseVersionException::forProductAndVersion( $release->productId(), $release->version() );
			}

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new ReleasePersistenceException( 'Failed to update release: ' . $this->wpdb->last_error );
		}

		$this->forget( $id, $release->productId() );
	}

	/**
	 * The columns an update may write, keyed by column name.
	 *
	 * @since 1.0.0
	 *
	 * @param Release $release The release to read the values from.
	 * @return array<string, mixed> The column values.
	 */
	private function updatableValues( Release $release ): array {
		return array(
			'version'          => $release->version(),
			'storage_provider' => $release->storageProvider(),
			'storage_key'      => $release->storageKey(),
			'filesize'         => $release->filesize(),
			'checksum_sha256'  => $release->checksumSha256(),
			'requires_wp'      => $release->requiresWp(),
			'tested_up_to'     => $release->testedUpTo(),
			'requires_php'     => $release->requiresPhp(),
			'changelog'        => $release->changelog(),
		);
	}

	/**
	 * Move a release status with a guarded compare-and-swap.
	 *
	 * @since 1.0.0
	 *
	 * @param Release                   $release      The release to transition (its stored status is not trusted).
	 * @param array<int, ReleaseStatus> $fromStatuses The statuses the move is legal from
	 *                                                (typically {@see ReleaseStateMachine::sourcesFor()}).
	 * @param ReleaseStatus             $to           The status to move to.
	 * @return bool True when this call made the transition; false when a concurrent writer got
	 *              there first (or no legal source was given).
	 *
	 * @throws ReleaseException            When the release has no id.
	 * @throws ReleasePersistenceException When the write faults at the database.
	 */
	public function transitionStatus( Release $release, array $fromStatuses, ReleaseStatus $to ): bool {
		$id = $release->id();

		if ( null === $id ) {
			throw new ReleaseException( 'Cannot transition a release that has no id.' );
		}

		if ( array() === $fromStatuses ) {
			// No legal source means the move can never apply; nothing to write.
			return false;
		}

		$now     = gmdate( 'Y-m-d H:i:s' );
		$wpdb    = $this->wpdb;
		$sources = array_map( static fn ( ReleaseStatus $status ): string => $status->value, $fromStatuses );

		if ( ReleaseStatus::Published === $to ) {
			// Publishing is the one move that stamps the release date the update server orders on.
			$values = array_merge( array( $this->table(), $to->value, $now, $now, $id ), $sources );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
			$affected = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET status = %s, updated_at = %s, released_at = %s WHERE id = %d AND status IN (' .
					implode( ', ', array_fill( 0, count( $fromStatuses ), '%s' ) ) . ')',
					$values
				)
			);
		} else {
			$values = array_merge( array( $this->table(), $to->value, $now, $id ), $sources );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
			$affected = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET status = %s, updated_at = %s WHERE id = %d AND status IN (' .
					implode( ', ', array_fill( 0, count( $fromStatuses ), '%s' ) ) . ')',
					$values
				)
			);
		}

		// Invalidate whether or not this call won: a racing writer may have changed the row.
		$this->forget( $id, $release->productId() );

		if ( false === $affected ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new ReleasePersistenceException( 'Failed to transition release status: ' . $this->wpdb->last_error );
		}

		return 1 === $affected;
	}

	/**
	 * Delete a release row and clear its caches.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The release id to delete.
	 * @return void
	 *
	 * @throws ReleasePersistenceException When the delete faults at the database.
	 */
	public function delete( int $id ): void {
		// Load first so the product is known for latest-lookup invalidation.
		$existing = $this->find( $id );

		$deleted = $this->wpdb->delete( $this->table(), array( 'id' => $id ) );

		if ( false === $deleted ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new ReleasePersistenceException( 'Failed to delete release: ' . $this->wpdb->last_error );
		}

		if ( null !== $existing ) {
			$this->forget( $id, $existing->productId() );
		} else {
			wp_cache_delete( $this->idKey( $id ), self::CACHE_GROUP );
		}
	}

	/**
	 * Check if another release owns a product-version pair.
	 *
	 * @since 1.0.0
	 *
	 * @param int      $productId The owning product id.
	 * @param string   $version   The version being written.
	 * @param int|null $exceptId  A row id to exclude (the row being updated), or null on insert.
	 * @return bool True when another row already carries the pair.
	 */
	private function versionCollisionExists( int $productId, string $version, ?int $exceptId ): bool {
		$wpdb = $this->wpdb;

		if ( null !== $exceptId ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
			$existing = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE product_id = %d AND version = %s AND id != %d', $this->table(), $productId, $version, $exceptId ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; this class decides what to cache.
			$existing = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE product_id = %d AND version = %s', $this->table(), $productId, $version ) );
		}

		return null !== $existing;
	}

	/**
	 * Run one prepared read and distinguish a fault from a miss.
	 *
	 * @since 1.0.0
	 *
	 * @param callable(): mixed $query Runs the prepared query.
	 * @return object|null The row, or null when it genuinely does not exist.
	 *
	 * @throws ReleasePersistenceException When the read faulted at the database.
	 */
	private function getRowOrFail( callable $query ): ?object {
		$this->wpdb->last_error = '';

		$row = $query();

		if ( '' !== $this->wpdb->last_error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new ReleasePersistenceException( 'Release read failed: ' . $this->wpdb->last_error );
		}

		return $row;
	}

	/**
	 * Run a prepared multi-row SELECT with the same fault discrimination as
	 * {@see getRowOrFail()}.
	 *
	 * @since 1.0.0
	 *
	 * @param callable(): mixed $query Runs the prepared query.
	 * @return array<int, object> The rows (possibly empty).
	 *
	 * @throws ReleasePersistenceException When the read faulted at the database.
	 */
	private function getResultsOrFail( callable $query ): array {
		$this->wpdb->last_error = '';

		$rows = $query();

		if ( '' !== $this->wpdb->last_error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw new ReleasePersistenceException( 'Release list read failed: ' . $this->wpdb->last_error );
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Hydrate a release row with UTC timestamps.
	 *
	 * @since 1.0.0
	 *
	 * @param object $row The raw database row.
	 * @return Release The hydrated release.
	 */
	private function fromRow( object $row ): Release {
		return new Release(
			(int) $row->id,
			(int) $row->product_id,
			(string) $row->version,
			(string) $row->storage_provider,
			(string) $row->storage_key,
			null === $row->filesize ? null : (int) $row->filesize,
			null === $row->checksum_sha256 ? null : (string) $row->checksum_sha256,
			null === $row->requires_wp ? null : (string) $row->requires_wp,
			null === $row->tested_up_to ? null : (string) $row->tested_up_to,
			null === $row->requires_php ? null : (string) $row->requires_php,
			null === $row->changelog ? null : (string) $row->changelog,
			ReleaseStatus::from( (string) $row->status ),
			$this->parseInstant( $row->released_at ),
			$this->parseInstant( $row->created_at ),
			$this->parseInstant( $row->updated_at )
		);
	}

	/**
	 * Parse a stored UTC DATETIME string into an instant.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $value The stored value.
	 * @return DateTimeImmutable|null The instant, or null.
	 */
	private function parseInstant( ?string $value ): ?DateTimeImmutable {
		if ( null === $value || '' === $value ) {
			return null;
		}

		return new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Format an instant as a UTC MySQL DATETIME string for storage.
	 *
	 * @since 1.0.0
	 *
	 * @param DateTimeImmutable|null $instant The instant, or null.
	 * @return string|null The storable string, or null.
	 */
	private function formatInstant( ?DateTimeImmutable $instant ): ?string {
		if ( null === $instant ) {
			return null;
		}

		return $instant->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Cache a release under its id key.
	 *
	 * @since 1.0.0
	 *
	 * @param Release $release The release to cache.
	 * @return void
	 */
	private function cache( Release $release ): void {
		$id = $release->id();

		if ( null !== $id ) {
			wp_cache_set( $this->idKey( $id ), $release, self::CACHE_GROUP );
		}
	}

	/**
	 * Clear caches touched by a release write.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id        The release id.
	 * @param int $productId The owning product id.
	 * @return void
	 */
	private function forget( int $id, int $productId ): void {
		wp_cache_delete( $this->idKey( $id ), self::CACHE_GROUP );
		wp_cache_delete( $this->latestKey( $productId ), self::CACHE_GROUP );
		wp_cache_delete( $this->publishedKey( $productId ), self::CACHE_GROUP );
	}

	/**
	 * The cache key for a release id.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The release id.
	 * @return string The cache key.
	 */
	private function idKey( int $id ): string {
		return 'release_' . $id;
	}

	/**
	 * The cache key for a product's latest-published lookup.
	 *
	 * @since 1.0.0
	 *
	 * @param int $productId The owning product id.
	 * @return string The cache key.
	 */
	private function latestKey( int $productId ): string {
		return 'release_latest_' . $productId;
	}

	/**
	 * The cache key for a product's published-release list ({@see published()}).
	 *
	 * @since 1.0.0
	 *
	 * @param int $productId The owning product id.
	 * @return string The cache key.
	 */
	private function publishedKey( int $productId ): string {
		return 'release_published_' . $productId;
	}

	/**
	 * The fully-qualified releases table name.
	 *
	 * @since 1.0.0
	 *
	 * @return string The prefixed releases table name.
	 */
	private function table(): string {
		return Schema::tableName( $this->wpdb, 'releases' );
	}
}
