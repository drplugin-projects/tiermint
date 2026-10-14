<?php
/**
 * Persistence for management API keys.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\REST\Auth;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DateTimeImmutable;
use DateTimeZone;
use DrPlugin\WP\TierMint\Database\Schema;

/**
 * Database gateway for management API keys.
 *
 * Reads stay uncached so a revoked key stops working at once.
 *
 * @since 1.0.0
 */
class ApiKeyRepository {

	/**
	 * The WordPress database gateway.
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * Wire the repository to the database gateway.
	 *
	 * @since 1.0.0
	 *
	 * @param \wpdb $wpdb The WordPress database gateway.
	 */
	public function __construct( \wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Insert a key, stamping created_at in UTC, and return the new id.
	 *
	 * @since 1.0.0
	 *
	 * @param ApiKey $key The key to persist.
	 * @return int The new row id.
	 *
	 * @throws ApiKeyPersistenceException When the insert fails.
	 */
	public function create( ApiKey $key ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Repository-owned custom table write.
		$inserted = $this->wpdb->insert(
			$this->table(),
			array(
				'user_id'      => $key->userId(),
				'label'        => $key->label(),
				'public_key'   => $key->publicKey(),
				'secret_hash'  => $key->secretHash(),
				'permissions'  => $key->permission()->value,
				'last_used_at' => null,
				'status'       => $key->status()->value,
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		if ( false === $inserted ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only literal; controllers map persistence failures to a generic message.
			throw new ApiKeyPersistenceException( 'Failed to insert API key: ' . $this->wpdb->last_error );
		}

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Load a key by id.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The row id.
	 * @return ApiKey|null The key, or null when it does not exist.
	 */
	public function find( int $id ): ?ApiKey {
		$sql = 'SELECT * FROM ' . $this->table() . ' WHERE id = %d';
		$row = $this->getRowOrFail( $this->wpdb->prepare( $sql, $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Values bound via prepare(); the SQL embeds only the Schema-derived table name.

		return null !== $row ? $this->fromRow( $row ) : null;
	}

	/**
	 * Load a key by its unique public key: the authentication lookup.
	 *
	 * @since 1.0.0
	 *
	 * @param string $publicKey The tmk_-prefixed public key as presented.
	 * @return ApiKey|null The key, or null when none carries it.
	 */
	public function findByPublicKey( string $publicKey ): ?ApiKey {
		$sql = 'SELECT * FROM ' . $this->table() . ' WHERE public_key = %s';
		$row = $this->getRowOrFail( $this->wpdb->prepare( $sql, $publicKey ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Values bound via prepare(); the SQL embeds only the Schema-derived table name.

		return null !== $row ? $this->fromRow( $row ) : null;
	}

	/**
	 * All keys, newest first, for the management listing.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, ApiKey> Every key, including revoked audit rows.
	 */
	public function all(): array {
		$rows = $this->getResultsOrFail( 'SELECT * FROM ' . $this->table() . ' ORDER BY id DESC' );

		return array_map( array( $this, 'fromRow' ), $rows );
	}

	/**
	 * Flip a key's status.
	 *
	 * @since 1.0.0
	 *
	 * @param int          $id     The row id.
	 * @param ApiKeyStatus $status The new status.
	 * @return void
	 *
	 * @throws ApiKeyPersistenceException When the update fails.
	 */
	public function updateStatus( int $id, ApiKeyStatus $status ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Repository-owned custom table write.
		$updated = $this->wpdb->update(
			$this->table(),
			array( 'status' => $status->value ),
			array( 'id' => $id )
		);

		if ( false === $updated ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only literal; controllers map persistence failures to a generic message.
			throw new ApiKeyPersistenceException( 'Failed to update API key status: ' . $this->wpdb->last_error );
		}
	}

	/**
	 * Stamp the last authentication time without failing the request.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $id  The row id.
	 * @param string $utc The instant as a UTC MySQL datetime string.
	 * @return void
	 */
	public function stampLastUsed( int $id, string $utc ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Repository-owned custom table write; best-effort telemetry.
		$this->wpdb->update(
			$this->table(),
			array( 'last_used_at' => $utc ),
			array( 'id' => $id )
		);
	}

	/**
	 * The fully prefixed table name.
	 *
	 * @since 1.0.0
	 *
	 * @return string The table name.
	 */
	private function table(): string {
		return Schema::tableName( $this->wpdb, 'api_keys' );
	}

	/**
	 * Run a single-row query and detect database faults.
	 *
	 * @since 1.0.0
	 *
	 * @param string $sql The prepared SQL.
	 * @return object|null The row, or null on a real miss.
	 *
	 * @throws ApiKeyPersistenceException When the read faulted.
	 */
	private function getRowOrFail( string $sql ): ?object {
		$before = $this->wpdb->last_error;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared by the caller; management auth reads are deliberately uncached.
		$row   = $this->wpdb->get_row( $sql );
		$after = $this->wpdb->last_error;

		if ( null === $row && '' !== $after && $after !== $before ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only literal; controllers map persistence failures to a generic message.
			throw new ApiKeyPersistenceException( 'API key read failed: ' . $after );
		}

		return $row;
	}

	/**
	 * Run a multi-row query and detect database faults.
	 *
	 * @since 1.0.0
	 *
	 * @param string $sql The SQL (values bound by the caller where present).
	 * @return array<int, object> The rows.
	 *
	 * @throws ApiKeyPersistenceException When the read faulted.
	 */
	private function getResultsOrFail( string $sql ): array {
		$before = $this->wpdb->last_error;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Static or caller-prepared SQL; management auth reads are deliberately uncached.
		$rows  = $this->wpdb->get_results( $sql );
		$after = $this->wpdb->last_error;

		if ( ( null === $rows || array() === $rows ) && '' !== $after && $after !== $before ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only literal; controllers map persistence failures to a generic message.
			throw new ApiKeyPersistenceException( 'API key read failed: ' . $after );
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Hydrate an entity from a database row.
	 *
	 * @since 1.0.0
	 *
	 * @param object $row The database row.
	 * @return ApiKey The entity.
	 */
	private function fromRow( object $row ): ApiKey {
		return new ApiKey(
			(int) $row->id,
			(int) $row->user_id,
			(string) $row->label,
			(string) $row->public_key,
			(string) $row->secret_hash,
			ApiKeyPermission::from( (string) $row->permissions ),
			ApiKeyStatus::from( (string) $row->status ),
			$this->parseInstant( $row->last_used_at ?? null ),
			$this->parseInstant( $row->created_at ?? null )
		);
	}

	/**
	 * Parse a stored UTC datetime string.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $value The stored value.
	 * @return DateTimeImmutable|null The instant, or null.
	 */
	private function parseInstant( ?string $value ): ?DateTimeImmutable {
		if ( null === $value || '' === $value || '0000-00-00 00:00:00' === $value ) {
			return null;
		}

		return new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) );
	}
}
