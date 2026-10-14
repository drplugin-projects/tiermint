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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Repository-owned custom table write; $wpdb->insert escapes every value; caching is handled here.
		$inserted = $this->wpdb->insert(
			$this->table(),
			array(
				'product_id'       => $release->productId(),
				'version'          => $release->version(),
				'channel'          => $release->channel(),
				'storage_provider' => $release->storageProvider(),
				'storage_key'      => $release->storageKey(),
				'filesize'         => $release->filesize(),
				'checksum_sha256'  => $release->checksumSha256(),
				'requires_wp'      => $release->requiresWp(),
				'tested_up_to'     => $release->testedUpTo(),
				'requires_php'     => $release->requiresPhp(),
				'changelog'        => $release->changelog(),
				'rollout_percent'  => $release->rolloutPercent(),
				'status'           => $release->status()->value,
				'released_at'      => $this->formatInstant( $release->releasedAt() ),
				'created_at'       => $now,
				'updated_at'       => $now,
			)
		);

		if ( false === $inserted ) {
			if ( $this->versionCollisionExists( $release->productId(), $release->version(), null ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only message; never echoed as HTML.
				throw DuplicateReleaseVersionException::forProductAndVersion( $release->productId(), $release->version() );
			}

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only literal; controllers map persistence failures to a generic message.
			throw new ReleasePersistenceException( 'Failed to insert release: ' . $this->wpdb->last_error );
		}

		$id = (int) $this->wpdb->insert_id;

		$this->forget( $id, $release->productId(), $release->channel() );

		$stored = $this->find( $id );

		if ( null === $stored ) {
			// The row was just written; failing to read it back is a genuine fault, not a miss.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only literal; controllers map persistence failures to a generic message.
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

		$sql = 'SELECT * FROM ' . $this->table() . ' WHERE id = %d';
		$row = $this->getRowOrFail( $this->wpdb->prepare( $sql, $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Values bound via prepare(); the SQL embeds only the Schema-derived table name.

		if ( null === $row ) {
			return null;
		}

		$found = $this->fromRow( $row );
		$this->cache( $found );

		return $found;
	}

	/**
	 * Load a release by its product and version (the `product_version` unique key).
	 *
	 * @since 1.0.0
	 *
	 * @param int    $productId The owning product id.
	 * @param string $version   The version string.
	 * @return Release|null The release, or null when no release carries the pair.
	 *
	 * @throws ReleasePersistenceException When the read faults at the database.
	 */
	public function findByProductAndVersion( int $productId, string $version ): ?Release {
		$sql = 'SELECT * FROM ' . $this->table() . ' WHERE product_id = %d AND version = %s';
		$row = $this->getRowOrFail( $this->wpdb->prepare( $sql, $productId, $version ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Values bound via prepare(); the SQL embeds only the Schema-derived table name.

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
		$sql  = 'SELECT * FROM ' . $this->table() . ' WHERE product_id = %d';
		$rows = $this->getResultsOrFail( $this->wpdb->prepare( $sql, $productId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Values bound via prepare(); the SQL embeds only the Schema-derived table name.

		$releases = array_map( array( $this, 'fromRow' ), $rows );

		usort(
			$releases,
			static fn ( Release $a, Release $b ): int => version_compare( $b->version(), $a->version() )
		);

		return $releases;
	}

	/**
	 * Get the newest published release on a channel.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $productId The owning product id.
	 * @param string $channel   The release channel (core always 'stable').
	 * @return Release|null The latest published release, or null when none exists.
	 *
	 * @throws ReleasePersistenceException When the read faults at the database.
	 */
	public function latestPublished( int $productId, string $channel = 'stable' ): ?Release {
		$cached = wp_cache_get( $this->latestKey( $productId, $channel ), self::CACHE_GROUP );

		if ( $cached instanceof Release ) {
			return $cached;
		}

		$sql = 'SELECT * FROM ' . $this->table() .
			" WHERE product_id = %d AND channel = %s AND status = 'published'" .
			' ORDER BY released_at DESC, id DESC LIMIT 1';

		$row = $this->getRowOrFail( $this->wpdb->prepare( $sql, $productId, $channel ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Values bound via prepare(); the SQL embeds only the Schema-derived table name.

		if ( null === $row ) {
			// No negative caching: the next publish must be visible immediately.
			return null;
		}

		$latest = $this->fromRow( $row );

		wp_cache_set( $this->idKey( (int) $latest->id() ), $latest, self::CACHE_GROUP );
		wp_cache_set( $this->latestKey( $productId, $channel ), $latest, self::CACHE_GROUP );

		return $latest;
	}

	/**
	 * List published releases for selected channels.
	 *
	 * @since 1.0.0
	 *
	 * @param int                $productId The owning product id.
	 * @param array<int, string> $channels  The allowed channels (any order; deduplicated).
	 * @return array<int, Release> The published releases on those channels, newest first.
	 *
	 * @throws ReleasePersistenceException When the read faults at the database.
	 */
	public function publishedForChannels( int $productId, array $channels ): array {
		$allowed = $this->normalizeChannels( $channels );

		if ( array() === $allowed ) {
			// No channels means nothing can be served; never touch the database.
			return array();
		}

		$allowedSet = array_flip( $allowed );

		return array_values(
			array_filter(
				$this->allPublished( $productId ),
				static fn ( Release $release ): bool => isset( $allowedSet[ $release->channel() ] )
			)
		);
	}

	/**
	 * Every published release for a product, newest released_at first (ties by id), cached
	 * under one per-product key. A miss is not cached so a first publish is visible at once.
	 *
	 * @since 1.0.0
	 *
	 * @param int $productId The owning product id.
	 * @return array<int, Release> The product's published releases, newest first.
	 *
	 * @throws ReleasePersistenceException When the read faults at the database.
	 */
	private function allPublished( int $productId ): array {
		$cached = wp_cache_get( $this->publishedKey( $productId ), self::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$sql = 'SELECT * FROM ' . $this->table() .
			" WHERE product_id = %d AND status = 'published'" .
			' ORDER BY released_at DESC, id DESC';

		$rows = $this->getResultsOrFail( $this->wpdb->prepare( $sql, $productId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Value bound via prepare(); the SQL embeds only the Schema-derived table name.

		$releases = array_map( array( $this, 'fromRow' ), $rows );

		if ( array() !== $releases ) {
			// No negative caching: the next publish must be visible immediately.
			wp_cache_set( $this->publishedKey( $productId ), $releases, self::CACHE_GROUP );
		}

		return $releases;
	}

	/**
	 * Normalize a channel set for a lookup: cast to strings, drop blanks, and deduplicate.
	 * Order does not matter (the SQL orders by released_at, the caller filters by membership).
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string> $channels The raw channel list.
	 * @return array<int, string> The cleaned, deduplicated channels.
	 */
	private function normalizeChannels( array $channels ): array {
		$clean = array();

		foreach ( $channels as $channel ) {
			$channel = (string) $channel;

			if ( '' !== $channel ) {
				$clean[ $channel ] = true;
			}
		}

		return array_keys( $clean );
	}

	/**
	 * Update mutable release fields and clear affected caches.
	 *
	 * @since 1.0.0
	 *
	 * @param Release $release The release to persist (must carry an id).
	 * @return void
	 *
	 * @throws ReleaseException                 When the release has no id to update.
	 * @throws DuplicateReleaseVersionException When the new version already exists for the product.
	 * @throws ReleasePersistenceException      When the write faults for any other reason.
	 */
	public function update( Release $release ): void {
		$id = $release->id();

		if ( null === $id ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer message; never echoed as HTML.
			throw new ReleaseException( 'Cannot update a release that has no id.' );
		}

		// The previous channel may differ from the new one; capture it so its latest-lookup
		// entry is invalidated too (core stays on 'stable', but a re-channel must not strand a
		// stale cache).
		$previousChannel = (string) $this->fetchScalar(
			'SELECT channel FROM ' . $this->table() . ' WHERE id = %d',
			array( $id )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Repository-owned custom table write; $wpdb->update escapes values and conditions; caching is handled here.
		$updated = $this->wpdb->update(
			$this->table(),
			array(
				'product_id'       => $release->productId(),
				'version'          => $release->version(),
				'channel'          => $release->channel(),
				'storage_provider' => $release->storageProvider(),
				'storage_key'      => $release->storageKey(),
				'filesize'         => $release->filesize(),
				'checksum_sha256'  => $release->checksumSha256(),
				'requires_wp'      => $release->requiresWp(),
				'tested_up_to'     => $release->testedUpTo(),
				'requires_php'     => $release->requiresPhp(),
				'changelog'        => $release->changelog(),
				'rollout_percent'  => $release->rolloutPercent(),
				'status'           => $release->status()->value,
				'released_at'      => $this->formatInstant( $release->releasedAt() ),
				'updated_at'       => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $id )
		);

		if ( false === $updated ) {
			if ( $this->versionCollisionExists( $release->productId(), $release->version(), $id ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only message; never echoed as HTML.
				throw DuplicateReleaseVersionException::forProductAndVersion( $release->productId(), $release->version() );
			}

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only literal; controllers map persistence failures to a generic message.
			throw new ReleasePersistenceException( 'Failed to update release: ' . $this->wpdb->last_error );
		}

		$this->forget( $id, $release->productId(), $release->channel() );

		if ( '' !== $previousChannel && $previousChannel !== $release->channel() ) {
			wp_cache_delete( $this->latestKey( $release->productId(), $previousChannel ), self::CACHE_GROUP );
		}
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
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer message; never echoed as HTML.
			throw new ReleaseException( 'Cannot transition a release that has no id.' );
		}

		if ( array() === $fromStatuses ) {
			// No legal source means the move can never apply; nothing to write.
			return false;
		}

		$now = gmdate( 'Y-m-d H:i:s' );

		$set    = 'status = %s, updated_at = %s';
		$params = array( $to->value, $now );

		if ( ReleaseStatus::Published === $to ) {
			// Publishing is the one move that stamps the release date the update server orders on.
			$set     .= ', released_at = %s';
			$params[] = $now;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $fromStatuses ), '%s' ) );

		$params[] = $id;
		foreach ( $fromStatuses as $status ) {
			$params[] = $status->value;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The SQL embeds only the Schema-derived table name and %s placeholders; every runtime value is bound via prepare() below.
		$sql = 'UPDATE ' . $this->table() . ' SET ' . $set . ' WHERE id = %d AND status IN (' . $placeholders . ')';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Values bound via prepare(); cache invalidated below.
		$affected = $this->wpdb->query( $this->wpdb->prepare( $sql, $params ) );

		// Invalidate whether or not this call won: a racing writer may have changed the row.
		$this->forget( $id, $release->productId(), $release->channel() );

		if ( false === $affected ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only literal; controllers map persistence failures to a generic message.
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
		// Load first so the product and channel are known for latest-lookup invalidation.
		$existing = $this->find( $id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Repository-owned custom table write; $wpdb->delete escapes the conditions; caching is handled here.
		$deleted = $this->wpdb->delete( $this->table(), array( 'id' => $id ) );

		if ( false === $deleted ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only literal; controllers map persistence failures to a generic message.
			throw new ReleasePersistenceException( 'Failed to delete release: ' . $this->wpdb->last_error );
		}

		if ( null !== $existing ) {
			$this->forget( $id, $existing->productId(), $existing->channel() );
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
		$sql    = 'SELECT id FROM ' . $this->table() . ' WHERE product_id = %d AND version = %s';
		$params = array( $productId, $version );

		if ( null !== $exceptId ) {
			$sql     .= ' AND id != %d';
			$params[] = $exceptId;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Values bound via prepare(); a one-off existence probe on the write failure path.
		$existing = $this->wpdb->get_var( $this->wpdb->prepare( $sql, $params ) );

		return null !== $existing;
	}

	/**
	 * Run one prepared read and distinguish a fault from a miss.
	 *
	 * @since 1.0.0
	 *
	 * @param string $preparedSql The already-prepared SQL.
	 * @return object|null The row, or null when it genuinely does not exist.
	 *
	 * @throws ReleasePersistenceException When the read faulted at the database.
	 */
	private function getRowOrFail( string $preparedSql ): ?object {
		$this->wpdb->last_error = '';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Callers prepare the SQL; the repository fronts reads with wp_cache where the hot path needs it.
		$row = $this->wpdb->get_row( $preparedSql );

		if ( '' !== $this->wpdb->last_error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only literal; controllers map persistence failures to a generic message.
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
	 * @param string $preparedSql The already-prepared SQL.
	 * @return array<int, object> The rows (possibly empty).
	 *
	 * @throws ReleasePersistenceException When the read faulted at the database.
	 */
	private function getResultsOrFail( string $preparedSql ): array {
		$this->wpdb->last_error = '';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Callers prepare the SQL; list reads are deliberately uncached.
		$rows = $this->wpdb->get_results( $preparedSql );

		if ( '' !== $this->wpdb->last_error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only literal; controllers map persistence failures to a generic message.
			throw new ReleasePersistenceException( 'Release list read failed: ' . $this->wpdb->last_error );
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Fetch one scalar with read-fault detection.
	 *
	 * @since 1.0.0
	 *
	 * @param string            $sql  SQL with the Schema-derived table name and %d/%s placeholders.
	 * @param array<int, mixed> $args Values bound into the placeholders.
	 * @return string|null The scalar value, or null.
	 *
	 * @throws ReleasePersistenceException When the read faulted at the database.
	 */
	private function fetchScalar( string $sql, array $args ): ?string {
		$this->wpdb->last_error = '';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Values bound via prepare(); a small identity probe with nothing to cache.
		$value = $this->wpdb->get_var( $this->wpdb->prepare( $sql, $args ) );

		if ( '' !== $this->wpdb->last_error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only literal; controllers map persistence failures to a generic message.
			throw new ReleasePersistenceException( 'Release scalar read failed: ' . $this->wpdb->last_error );
		}

		return null === $value ? null : (string) $value;
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
			(string) $row->channel,
			(string) $row->storage_provider,
			(string) $row->storage_key,
			null === $row->filesize ? null : (int) $row->filesize,
			null === $row->checksum_sha256 ? null : (string) $row->checksum_sha256,
			null === $row->requires_wp ? null : (string) $row->requires_wp,
			null === $row->tested_up_to ? null : (string) $row->tested_up_to,
			null === $row->requires_php ? null : (string) $row->requires_php,
			null === $row->changelog ? null : (string) $row->changelog,
			(int) $row->rollout_percent,
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
	 * @param int    $id        The release id.
	 * @param int    $productId The owning product id.
	 * @param string $channel   The release channel.
	 * @return void
	 */
	private function forget( int $id, int $productId, string $channel ): void {
		wp_cache_delete( $this->idKey( $id ), self::CACHE_GROUP );
		wp_cache_delete( $this->latestKey( $productId, $channel ), self::CACHE_GROUP );
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
	 * The cache key for a product/channel latest-published lookup.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $productId The owning product id.
	 * @param string $channel   The release channel.
	 * @return string The cache key.
	 */
	private function latestKey( int $productId, string $channel ): string {
		return 'release_latest_' . $productId . '_' . $channel;
	}

	/**
	 * The cache key for a product's full published-release list ({@see allPublished()}).
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
