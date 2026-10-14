<?php
/**
 * Activations repository: the only Licensing class that touches the activations table.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Licensing;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DateTimeImmutable;
use DateTimeZone;
use DrPlugin\WP\TierMint\Database\Schema;

/**
 * Activation storage with cache-backed identity lookups.
 *
 * @since 1.0.0
 */
class ActivationRepository {

	/**
	 * The object-cache group activations are cached under.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'tiermint_activations';

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
	 * Insert an activation and return its id.
	 *
	 * @since 1.0.0
	 *
	 * @param Activation $activation The activation to persist.
	 * @return int The new activation id.
	 *
	 * @throws DuplicateActivationException When the site is already activated for the
	 *                                      license (the service falls back to reuse).
	 * @throws LicensePersistenceException  When the insert fails for any other reason.
	 */
	public function create( Activation $activation ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Repository-owned custom table write; caching is handled here.
		$inserted = $this->wpdb->insert(
			$this->table(),
			array(
				'license_id'      => $activation->licenseId(),
				'site_url'        => $activation->siteUrl(),
				'site_hash'       => $activation->siteHash(),
				'fingerprint'     => $activation->fingerprint(),
				'status'          => $activation->status()->value,
				'is_local'        => $activation->isLocal() ? 1 : 0,
				'environment'     => null === $activation->environment() ? null : wp_json_encode( $activation->environment() ),
				'activated_at'    => $this->formatInstant( $activation->activatedAt() ) ?? gmdate( 'Y-m-d H:i:s' ),
				'deactivated_at'  => $this->formatInstant( $activation->deactivatedAt() ),
				'last_checked_at' => $this->formatInstant( $activation->lastCheckedAt() ),
			)
		);

		if ( false === $inserted ) {
			// Discriminate the composite-unique clash (a concurrent activation of
			// the same site) from other faults, mirroring the license repository.
			$sql = 'SELECT id FROM ' . $this->table() . ' WHERE license_id = %d AND site_hash = %s';
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Values bound via prepare(); a one-off existence probe on the write failure path.
			$existing = $this->wpdb->get_var( $this->wpdb->prepare( $sql, $activation->licenseId(), $activation->siteHash() ) );

			if ( null !== $existing ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only message built from typed ids; never echoed as HTML.
				throw DuplicateActivationException::forSite( $activation->licenseId(), $activation->siteHash() );
			}

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only literal; controllers map persistence failures to a generic message.
			throw new LicensePersistenceException( 'Failed to insert activation: ' . $this->wpdb->last_error );
		}

		$id = (int) $this->wpdb->insert_id;

		$this->forget( $id, $activation->licenseId(), $activation->siteHash() );

		return $id;
	}

	/**
	 * Load an activation by id, reading through the object cache.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The activation id.
	 * @return Activation|null The activation, or null when it does not exist.
	 */
	public function find( int $id ): ?Activation {
		$cached = wp_cache_get( $this->idKey( $id ), self::CACHE_GROUP );

		if ( $cached instanceof Activation ) {
			return $cached;
		}

		$sql = 'SELECT * FROM ' . $this->table() . ' WHERE id = %d';
		$row = $this->getRowOrFail( $this->wpdb->prepare( $sql, $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Values bound via prepare(); the SQL embeds only the Schema-derived table name.

		if ( null === $row ) {
			return null;
		}

		$activation = $this->fromRow( $row );
		$this->cache( $activation );

		return $activation;
	}

	/**
	 * Load an activation by license and site hash.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $licenseId The license id.
	 * @param string $siteHash  The site hash presented by the client.
	 * @return Activation|null The activation, or null when the site was never activated.
	 */
	public function findByLicenseAndHash( int $licenseId, string $siteHash ): ?Activation {
		$id = wp_cache_get( $this->hashKey( $licenseId, $siteHash ), self::CACHE_GROUP );

		if ( is_int( $id ) ) {
			$activation = $this->find( $id );

			if ( null !== $activation && $activation->licenseId() === $licenseId && hash_equals( $activation->siteHash(), $siteHash ) ) {
				return $activation;
			}
		}

		$sql = 'SELECT * FROM ' . $this->table() . ' WHERE license_id = %d AND site_hash = %s';
		$row = $this->getRowOrFail( $this->wpdb->prepare( $sql, $licenseId, $siteHash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Values bound via prepare(); the SQL embeds only the Schema-derived table name.

		if ( null === $row ) {
			return null;
		}

		$activation = $this->fromRow( $row );
		$this->cache( $activation );

		return $activation;
	}

	/**
	 * Load a license's activations, newest first.
	 *
	 * @since 1.0.0
	 *
	 * @param int                   $licenseId The license id.
	 * @param ActivationStatus|null $status    Only activations in this status, or null for all.
	 * @return array<int, Activation> The activations.
	 */
	public function listForLicense( int $licenseId, ?ActivationStatus $status = null ): array {
		if ( null === $status ) {
			$sql    = 'SELECT * FROM ' . $this->table() . ' WHERE license_id = %d ORDER BY id DESC';
			$values = array( $licenseId );
		} else {
			$sql    = 'SELECT * FROM ' . $this->table() . ' WHERE license_id = %d AND status = %s ORDER BY id DESC';
			$values = array( $licenseId, $status->value );
		}

		$rows = $this->getResultsOrFail( $this->wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Values bound via prepare(); the SQL embeds only the Schema-derived table name.

		return array_map( array( $this, 'fromRow' ), $rows );
	}

	/**
	 * Count active activations, optionally excluding local sites.
	 *
	 * @since 1.0.0
	 *
	 * @param int  $licenseId    The license id.
	 * @param bool $excludeLocal Whether to skip local/staging activations.
	 * @return int The number of active activations.
	 */
	public function countActiveForLicense( int $licenseId, bool $excludeLocal = false ): int {
		$sql = 'SELECT COUNT(*) FROM ' . $this->table() . " WHERE license_id = %d AND status = 'active'";

		if ( $excludeLocal ) {
			$sql .= ' AND is_local = 0';
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Values bound via prepare(); a consistency-check count, deliberately uncached.
		return (int) $this->wpdb->get_var( $this->wpdb->prepare( $sql, $licenseId ) );
	}

	/**
	 * Save mutable activation fields and clear its cache.
	 *
	 * A moved site is a new activation.
	 *
	 * @since 1.0.0
	 *
	 * @param Activation $activation The activation to persist (must carry an id).
	 * @return void
	 *
	 * @throws LicenseException          When the activation has no id.
	 * @throws LicensePersistenceException When the write faults at the database.
	 */
	public function update( Activation $activation ): void {
		$id = $activation->id();

		if ( null === $id ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer message; never echoed as HTML.
			throw new LicenseException( 'Cannot update an activation that has not been persisted.' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Repository-owned custom table write; caching is handled here.
		$updated = $this->wpdb->update(
			$this->table(),
			array(
				'fingerprint'    => $activation->fingerprint(),
				'status'         => $activation->status()->value,
				'is_local'       => $activation->isLocal() ? 1 : 0,
				'environment'    => null === $activation->environment() ? null : wp_json_encode( $activation->environment() ),
				'activated_at'   => $this->formatInstant( $activation->activatedAt() ) ?? gmdate( 'Y-m-d H:i:s' ),
				'deactivated_at' => $this->formatInstant( $activation->deactivatedAt() ),
			),
			array( 'id' => $id )
		);

		if ( false === $updated ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only literal; controllers map persistence failures to a generic message.
			throw new LicensePersistenceException( 'Failed to update activation: ' . $this->wpdb->last_error );
		}

		$this->forget( $id, $activation->licenseId(), $activation->siteHash() );
	}

	/**
	 * Remove personal site data for an erasure request.
	 *
	 * Replace the URL-derived hash so it cannot identify the site.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The activation id to anonymize.
	 * @return void
	 *
	 * @throws LicensePersistenceException When the update fails.
	 */
	public function anonymize( int $id ): void {
		$existing = $this->find( $id );

		// A per-row random hash keeps the unique index intact without preserving
		// anything derived from the erased site URL.
		$anonHash = hash( 'sha256', 'tiermint-anon-' . $id . '-' . wp_generate_uuid4() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Repository-owned custom table write; caching is handled here.
		$updated = $this->wpdb->update(
			$this->table(),
			array(
				'site_url'    => '',
				'site_hash'   => $anonHash,
				'fingerprint' => null,
				'environment' => null,
			),
			array( 'id' => $id )
		);

		if ( false === $updated ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only literal; controllers map persistence failures to a generic message.
			throw new LicensePersistenceException( 'Failed to anonymize activation: ' . $this->wpdb->last_error );
		}

		if ( null !== $existing ) {
			$this->forget( $id, $existing->licenseId(), $existing->siteHash() );
		}

		wp_cache_delete( $this->idKey( $id ), self::CACHE_GROUP );
	}

	/**
	 * Deactivate an active activation with a guarded update.
	 *
	 * Only one competing request releases the slot and fires the hook.
	 *
	 * @since 1.0.0
	 *
	 * @param Activation $activation    The activation to deactivate (drives cache invalidation).
	 * @param string     $deactivatedAt The deactivation instant as a UTC MySQL DATETIME.
	 * @return bool True when this call made the flip; false when a concurrent
	 *              writer got there first (the row is no longer active). An
	 *              unsaved entity raises LicenseException from the id guard.
	 *
	 * @throws LicensePersistenceException When the write faults at the database.
	 */
	public function markDeactivated( Activation $activation, string $deactivatedAt ): bool {
		$id = $this->requireId( $activation );

		$sql = 'UPDATE ' . $this->table() .
			" SET status = 'deactivated', deactivated_at = %s WHERE id = %d AND status = 'active'";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Values bound via prepare(); cache invalidated below.
		$affected = $this->wpdb->query( $this->wpdb->prepare( $sql, $deactivatedAt, $id ) );

		if ( false === $affected ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only literal; controllers map persistence failures to a generic message.
			throw new LicensePersistenceException( 'Failed to deactivate activation: ' . $this->wpdb->last_error );
		}

		$this->forget( $id, $activation->licenseId(), $activation->siteHash() );

		return 1 === $affected;
	}

	/**
	 * Reactivate a stored activation with a guarded update.
	 *
	 * Only one competing request claims the slot.
	 *
	 * @since 1.0.0
	 *
	 * @param Activation                $activation  The deactivated row to reuse.
	 * @param string                    $activatedAt The reactivation instant as a UTC MySQL DATETIME.
	 * @param array<string, mixed>|null $environment The fresh environment report, or null to keep the stored one.
	 * @return bool True when this call made the flip; false when a concurrent
	 *              writer got there first (the row is no longer deactivated). An
	 *              unsaved entity raises LicenseException from the id guard.
	 *
	 * @throws LicensePersistenceException When the write faults at the database.
	 */
	public function markReactivated( Activation $activation, string $activatedAt, ?array $environment ): bool {
		$id = $this->requireId( $activation );

		if ( null === $environment ) {
			$sql    = 'UPDATE ' . $this->table() .
				" SET status = 'active', deactivated_at = NULL, activated_at = %s" .
				" WHERE id = %d AND status = 'deactivated'";
			$values = array( $activatedAt, $id );
		} else {
			$sql    = 'UPDATE ' . $this->table() .
				" SET status = 'active', deactivated_at = NULL, activated_at = %s, environment = %s" .
				" WHERE id = %d AND status = 'deactivated'";
			$values = array( $activatedAt, wp_json_encode( $environment ), $id );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Values bound via prepare(); cache invalidated below.
		$affected = $this->wpdb->query( $this->wpdb->prepare( $sql, $values ) );

		if ( false === $affected ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only literal; controllers map persistence failures to a generic message.
			throw new LicensePersistenceException( 'Failed to reactivate activation: ' . $this->wpdb->last_error );
		}

		$this->forget( $id, $activation->licenseId(), $activation->siteHash() );

		return 1 === $affected;
	}

	/**
	 * The id a write requires, or a domain error when the entity is unsaved.
	 *
	 * @since 1.0.0
	 *
	 * @param Activation $activation The activation.
	 * @return int The activation id.
	 *
	 * @throws LicenseException When the activation was never persisted.
	 */
	private function requireId( Activation $activation ): int {
		$id = $activation->id();

		if ( null === $id ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer message; never echoed as HTML.
			throw new LicenseException( 'Cannot write an activation that has not been persisted.' );
		}

		return $id;
	}

	/**
	 * Throttled update of an activation's check time.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $id            The activation id.
	 * @param string $now           The current instant as a UTC MySQL DATETIME.
	 * @param int    $throttleHours The minimum hours between stamps.
	 * @return bool True when the stamp was written; false when throttled away.
	 *
	 * @throws LicensePersistenceException When the write faults at the database.
	 */
	public function touchLastChecked( int $id, string $now, int $throttleHours ): bool {
		$cutoff = gmdate( 'Y-m-d H:i:s', (int) strtotime( $now . ' UTC' ) - ( $throttleHours * HOUR_IN_SECONDS ) );

		$sql = 'UPDATE ' . $this->table() .
			' SET last_checked_at = %s WHERE id = %d AND (last_checked_at IS NULL OR last_checked_at < %s)';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Values bound via prepare(); cache invalidated below.
		$affected = $this->wpdb->query( $this->wpdb->prepare( $sql, $now, $id, $cutoff ) );

		if ( false === $affected ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only literal; controllers map persistence failures to a generic message.
			throw new LicensePersistenceException( 'Failed to stamp activation last_checked_at: ' . $this->wpdb->last_error );
		}

		if ( 1 === $affected ) {
			// Only the id entry needs eviction: the hash entry stores just the id.
			wp_cache_delete( $this->idKey( $id ), self::CACHE_GROUP );

			return true;
		}

		return false;
	}

	/**
	 * Run a prepared single-row SELECT.
	 *
	 * Distinguish a database error from a missing activation.
	 *
	 * @since 1.0.0
	 *
	 * @param string $preparedSql The already-prepared SQL.
	 * @return object|null The row, or null when it genuinely does not exist.
	 *
	 * @throws LicensePersistenceException When the read faulted at the database.
	 */
	private function getRowOrFail( string $preparedSql ): ?object {
		$this->wpdb->last_error = '';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Callers prepare the SQL; the repository fronts reads with wp_cache where the hot path needs it.
		$row = $this->wpdb->get_row( $preparedSql );

		if ( '' !== $this->wpdb->last_error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only literal; controllers map persistence failures to a generic message.
			throw new LicensePersistenceException( 'Activation read failed: ' . $this->wpdb->last_error );
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
	 * @throws LicensePersistenceException When the read faulted at the database.
	 */
	private function getResultsOrFail( string $preparedSql ): array {
		$this->wpdb->last_error = '';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Callers prepare the SQL; list reads are deliberately uncached.
		$rows = $this->wpdb->get_results( $preparedSql );

		if ( '' !== $this->wpdb->last_error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only literal; controllers map persistence failures to a generic message.
			throw new LicensePersistenceException( 'Activation read failed: ' . $this->wpdb->last_error );
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Hydrate an activation from UTC database values.
	 *
	 * @since 1.0.0
	 *
	 * @param object $row The raw database row.
	 * @return Activation The hydrated activation.
	 */
	private function fromRow( object $row ): Activation {
		$environment = null;

		if ( null !== $row->environment && '' !== $row->environment ) {
			$decoded     = json_decode( (string) $row->environment, true );
			$environment = is_array( $decoded ) ? $decoded : null;
		}

		return new Activation(
			(int) $row->id,
			(int) $row->license_id,
			(string) $row->site_url,
			(string) $row->site_hash,
			null === $row->fingerprint ? null : (string) $row->fingerprint,
			ActivationStatus::from( (string) $row->status ),
			(bool) (int) $row->is_local,
			$environment,
			$this->parseInstant( $row->activated_at ),
			$this->parseInstant( $row->deactivated_at ),
			$this->parseInstant( $row->last_checked_at )
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
	 * Cache an activation under its id and register the hash-to-id lookup entry.
	 *
	 * @since 1.0.0
	 *
	 * @param Activation $activation The activation to cache.
	 * @return void
	 */
	private function cache( Activation $activation ): void {
		wp_cache_set( $this->idKey( (int) $activation->id() ), $activation, self::CACHE_GROUP );
		wp_cache_set( $this->hashKey( $activation->licenseId(), $activation->siteHash() ), (int) $activation->id(), self::CACHE_GROUP );
	}

	/**
	 * Invalidate the cache entries for an activation identity.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $id        The activation id.
	 * @param int    $licenseId The license id.
	 * @param string $siteHash  The site hash.
	 * @return void
	 */
	private function forget( int $id, int $licenseId, string $siteHash ): void {
		wp_cache_delete( $this->idKey( $id ), self::CACHE_GROUP );
		wp_cache_delete( $this->hashKey( $licenseId, $siteHash ), self::CACHE_GROUP );
	}

	/**
	 * The cache key for an activation id.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The activation id.
	 * @return string The cache key.
	 */
	private function idKey( int $id ): string {
		return 'id_' . $id;
	}

	/**
	 * The cache key for a `(license_id, site_hash)` lookup entry.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $licenseId The license id.
	 * @param string $siteHash  The site hash.
	 * @return string The cache key.
	 */
	private function hashKey( int $licenseId, string $siteHash ): string {
		return 'site_' . $licenseId . '_' . $siteHash;
	}

	/**
	 * The fully-qualified activations table name.
	 *
	 * @since 1.0.0
	 *
	 * @return string The prefixed activations table name.
	 */
	private function table(): string {
		return Schema::tableName( $this->wpdb, 'activations' );
	}
}
