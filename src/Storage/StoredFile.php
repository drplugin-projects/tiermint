<?php
/**
 * Immutable reference DTO returned by StorageProvider::store().
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Storage;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use JsonSerializable;

/**
 * Immutable reference to a stored file.
 *
 * @since 1.0.0
 */
final class StoredFile implements JsonSerializable {

	/**
	 * Wire up every field of the reference.
	 *
	 * @since 1.0.0
	 *
	 * @param string $providerId The storage provider id that holds this file, e.g. 'local'.
	 * @param string $key        The final provider-scoped object key.
	 * @param int    $filesize   The stored file's size in bytes.
	 * @param string $sha256     The stored file's SHA-256 checksum, as 64 lowercase hex characters.
	 */
	public function __construct(
		private readonly string $providerId,
		private readonly string $key,
		private readonly int $filesize,
		private readonly string $sha256
	) {
	}

	/**
	 * Rehydrate a StoredFile from a persisted row, the exact inverse of {@see toArray()}.
	 *
	 * @since 1.0.0
	 *
	 * @param array{provider_id: string, key: string, filesize: int, sha256: string} $data Persisted fields.
	 * @return self
	 */
	public static function fromArray( array $data ): self {
		return new self( $data['provider_id'], $data['key'], $data['filesize'], $data['sha256'] );
	}

	/**
	 * The storage provider id that holds this file, e.g. 'local'.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function providerId(): string {
		return $this->providerId;
	}

	/**
	 * The final provider-scoped object key.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function key(): string {
		return $this->key;
	}

	/**
	 * The stored file's size in bytes.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function filesize(): int {
		return $this->filesize;
	}

	/**
	 * The stored file's SHA-256 checksum, as 64 lowercase hex characters.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function sha256(): string {
		return $this->sha256;
	}

	/**
	 * Export the stored-file fields.
	 *
	 * @since 1.0.0
	 *
	 * @return array{provider_id: string, key: string, filesize: int, sha256: string}
	 */
	public function toArray(): array {
		return array(
			'provider_id' => $this->providerId,
			'key'         => $this->key,
			'filesize'    => $this->filesize,
			'sha256'      => $this->sha256,
		);
	}

	/**
	 * Export the stored-file fields for JSON.
	 *
	 * @since 1.0.0
	 *
	 * @return array{provider_id: string, key: string, filesize: int, sha256: string}
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}
}
