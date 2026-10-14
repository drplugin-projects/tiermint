<?php
/**
 * Contract for a file storage backend.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Storage;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * A file storage backend. References use a provider ID and key.
 *
 * @since 1.0.0
 */
interface StorageProvider {

	/**
	 * Unique provider identifier stored alongside file references,
	 * e.g. 'local', 's3'.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Whether a stored object exists for the given key.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Provider-scoped object key.
	 * @return bool
	 */
	public function exists( string $key ): bool;

	/**
	 * Persist an uploaded file and return its storage reference.
	 *
	 * @since 1.0.0
	 *
	 * @param string $sourcePath Absolute path of the uploaded temp file.
	 * @param string $key        Desired provider-scoped key (may be namespaced/randomized by the provider).
	 * @return StoredFile Reference DTO: provider id, final key, filesize, sha256.
	 * @throws StorageException On write failure.
	 */
	public function store( string $sourcePath, string $key ): StoredFile;

	/**
	 * Delete a stored object. Deleting a missing key is a no-op.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Provider-scoped object key.
	 * @return void
	 */
	public function delete( string $key ): void;

	/**
	 * Stream the object to the current HTTP response.
	 * Return false when the provider uses retrievalUrl().
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Provider-scoped object key.
	 * @return bool True if the response was written; false if the caller should redirect to retrievalUrl().
	 */
	public function stream( string $key ): bool;

	/**
	 * Get a short-lived retrieval URL when available.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key        Provider-scoped object key.
	 * @param int    $ttlSeconds Requested URL lifetime.
	 * @return string|null Signed URL, or null when the provider streams directly.
	 */
	public function retrievalUrl( string $key, int $ttlSeconds ): ?string;
}
