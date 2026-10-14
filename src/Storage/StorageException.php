<?php
/**
 * Exception thrown when a storage provider fails to read, write, or resolve a file.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Storage;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use RuntimeException;

/**
 * Storage operation failure.
 *
 * @since 1.0.0
 */
final class StorageException extends RuntimeException {

	/**
	 * Build an exception for a file reference whose provider id has no
	 * registered adapter.
	 *
	 * @since 1.0.0
	 *
	 * @param string $providerId The unresolvable provider id.
	 * @return self
	 */
	public static function unknownProvider( string $providerId ): self {
		return new self(
			sprintf( 'TierMint storage provider "%s" is not registered.', $providerId )
		);
	}

	/**
	 * Build an exception for a source file that cannot be read for storing.
	 *
	 * @since 1.0.0
	 *
	 * @param string $sourcePath The unreadable source path.
	 * @return self
	 */
	public static function unreadableSource( string $sourcePath ): self {
		return new self(
			sprintf( 'TierMint storage source file is not readable: %s', $sourcePath )
		);
	}

	/**
	 * Build an exception for a write (copy/move) that failed.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key    The provider-scoped key the write was targeting.
	 * @param string $reason A short human-readable reason, e.g. "copy failed".
	 * @return self
	 */
	public static function writeFailed( string $key, string $reason ): self {
		return new self(
			sprintf( 'TierMint storage write failed for key "%s": %s', $key, $reason )
		);
	}

	/**
	 * Build an exception for a key rejected as unsafe (e.g. path traversal).
	 *
	 * @since 1.0.0
	 *
	 * @param string $key The rejected key.
	 * @return self
	 */
	public static function invalidKey( string $key ): self {
		return new self(
			sprintf( 'TierMint storage key is invalid: %s', $key )
		);
	}
}
