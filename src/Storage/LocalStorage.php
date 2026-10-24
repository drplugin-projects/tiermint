<?php
/**
 * Local filesystem storage provider.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Storage;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Stores protected package files below the uploads directory.
 * Keys cannot resolve outside this root. Local files stream directly.
 *
 * @since 1.0.0
 */
final class LocalStorage implements StorageProvider {

	/**
	 * Apache guard file contents.
	 *
	 * @var string
	 */
	private const HTACCESS_CONTENTS = <<<'HTACCESS'
# Deny direct access to TierMint package files; downloads only ever go
# through the signed /package/download REST endpoint.
<IfModule mod_authz_core.c>
	Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
	Deny from all
</IfModule>
HTACCESS;

	/**
	 * IIS guard file contents.
	 *
	 * @var string
	 */
	private const WEB_CONFIG_CONTENTS = <<<'WEBCONFIG'
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
	<system.webServer>
		<authorization>
			<deny users="*" />
		</authorization>
	</system.webServer>
</configuration>
WEBCONFIG;

	/**
	 * Directory-listing guard file contents.
	 *
	 * @var string
	 */
	private const INDEX_PHP_CONTENTS = "<?php\n// Silence is golden: nothing here is meant to be browsed.\n";

	/**
	 * Guard-file names blocked at the packages root.
	 *
	 * @var array<int, string>
	 */
	private const RESERVED_GUARD_FILES = array( '.htaccess', 'web.config', 'index.php' );

	/**
	 * Cached packages root.
	 *
	 * @var string|null
	 */
	private ?string $root = null;

	/**
	 * Unique provider identifier stored alongside file references.
	 *
	 * @since 1.0.0
	 *
	 * @return string Always 'local'.
	 */
	public function id(): string {
		return 'local';
	}

	/**
	 * Whether a stored object exists for the given key.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Provider-scoped object key.
	 * @return bool True when the key resolves to a readable file under the packages root.
	 */
	public function exists( string $key ): bool {
		$path = $this->root() . '/' . $this->normalizeKey( $key );

		return is_file( $path ) && is_readable( $path );
	}

	/**
	 * Copy an uploaded file into the packages root and return its storage reference.
	 *
	 * @since 1.0.0
	 *
	 * @param string $sourcePath Absolute path of the uploaded temp file.
	 * @param string $key        Desired provider-scoped key.
	 * @return StoredFile Reference DTO: provider id, final key, filesize, sha256.
	 *
	 * @throws StorageException When the source is unreadable, the key is invalid, or the write fails.
	 */
	public function store( string $sourcePath, string $key ): StoredFile {
		if ( ! is_file( $sourcePath ) || ! is_readable( $sourcePath ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw StorageException::unreadableSource( $sourcePath );
		}

		$normalizedKey = $this->normalizeKey( $key );
		$this->ensureRoot();

		$targetPath = $this->root() . '/' . $normalizedKey;

		if ( ! wp_mkdir_p( dirname( $targetPath ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw StorageException::writeFailed( $normalizedKey, 'could not create the destination directory' );
		}

		$filesystem = $this->directFilesystem( $this->root() );

		if ( null !== $filesystem ) {
			$copied = $filesystem->copy( $sourcePath, $targetPath, true );
		} else {
			// Another filesystem method needs credentials, and a REST upload cannot ask for them.
			$copied = copy( $sourcePath, $targetPath );
		}

		if ( ! $copied ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw StorageException::writeFailed( $normalizedKey, 'copy to the packages directory failed' );
		}

		$this->guardFolders( dirname( $normalizedKey ) );

		$filesize = filesize( $targetPath );
		$sha256   = hash_file( 'sha256', $targetPath );

		if ( false === $filesize || false === $sha256 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw StorageException::writeFailed( $normalizedKey, 'could not read back the stored file to checksum it' );
		}

		return new StoredFile( $this->id(), $normalizedKey, $filesize, $sha256 );
	}

	/**
	 * Delete a stored object. Deleting a missing key is a no-op.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Provider-scoped object key.
	 * @return void
	 */
	public function delete( string $key ): void {
		$path = $this->root() . '/' . $this->normalizeKey( $key );

		if ( is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Stream the object to the current HTTP response.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Provider-scoped object key.
	 * @return bool True once the file has been streamed; false when the key does not exist.
	 */
	public function stream( string $key ): bool {
		$path = $this->root() . '/' . $this->normalizeKey( $key );

		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return false;
		}

		if ( ! headers_sent() ) {
			header( 'Content-Type: application/octet-stream' );
			header( 'Content-Length: ' . (string) filesize( $path ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streams the package to the response; WP_Filesystem has no streaming read.
		readfile( $path );

		return true;
	}

	/**
	 * A short-lived URL the client can be redirected to.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key        Provider-scoped object key.
	 * @param int    $ttlSeconds Requested URL lifetime.
	 * @return string|null Always null: local storage always streams, it never issues a retrieval URL.
	 */
	public function retrievalUrl( string $key, int $ttlSeconds ): ?string {
		return null;
	}

	/**
	 * Resolve and cache the packages root.
	 *
	 * @since 1.0.0
	 *
	 * @return string Absolute path of the packages root, no trailing slash.
	 */
	private function root(): string {
		if ( null === $this->root ) {
			$uploadDir  = wp_upload_dir();
			$this->root = rtrim( (string) $uploadDir['basedir'], '/\\' ) . '/tiermint/packages';
		}

		return $this->root;
	}

	/**
	 * Create the packages root and its guard files.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function ensureRoot(): void {
		$root = $this->root();

		wp_mkdir_p( $root );

		$this->writeGuardFileIfMissing( $root . '/.htaccess', self::HTACCESS_CONTENTS );
		$this->writeGuardFileIfMissing( $root . '/web.config', self::WEB_CONFIG_CONTENTS );
		$this->writeGuardFileIfMissing( $root . '/index.php', self::INDEX_PHP_CONTENTS );
	}

	/**
	 * Put an index.php in each folder of a key's path below the root.
	 *
	 * The root's .htaccess and web.config cover these folders on Apache and IIS.
	 * The index.php stops a folder listing on servers that ignore both.
	 *
	 * @since 1.0.0
	 *
	 * @param string $folder Normalized folder part of the key, or "." for none.
	 * @return void
	 */
	private function guardFolders( string $folder ): void {
		while ( '.' !== $folder && '' !== $folder ) {
			$this->writeGuardFileIfMissing( $this->root() . '/' . $folder . '/index.php', self::INDEX_PHP_CONTENTS );
			$folder = dirname( $folder );
		}
	}

	/**
	 * Write a protected-directory guard file, but only if it is not already there.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path     Absolute path of the guard file.
	 * @param string $contents Contents to write when the file is missing.
	 * @return void
	 */
	private function writeGuardFileIfMissing( string $path, string $contents ): void {
		if ( file_exists( $path ) ) {
			return;
		}

		$filesystem = $this->directFilesystem( dirname( $path ) );

		if ( null !== $filesystem ) {
			$filesystem->put_contents( $path, $contents );

			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Another filesystem method needs credentials, and a REST upload cannot ask for them.
		file_put_contents( $path, $contents );
	}

	/**
	 * Get the direct WP_Filesystem, or null when WordPress would use another method.
	 *
	 * An instance WordPress already set up is reused only when it is direct, so an
	 * FTP instance in use elsewhere in the request is never replaced.
	 *
	 * @since 1.0.0
	 *
	 * @param string $context The folder the write goes to.
	 * @return \WP_Filesystem_Base|null
	 */
	private function directFilesystem( string $context ): ?\WP_Filesystem_Base {
		global $wp_filesystem;

		if ( $wp_filesystem instanceof \WP_Filesystem_Base ) {
			return 'direct' === $wp_filesystem->method ? $wp_filesystem : null;
		}

		if ( ! function_exists( 'get_filesystem_method' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// PHP writes the uploads folder itself, so file ownership is not checked.
		if ( 'direct' !== get_filesystem_method( array(), $context, true ) || ! WP_Filesystem( false, $context, true ) ) {
			return null;
		}

		return $wp_filesystem instanceof \WP_Filesystem_Base ? $wp_filesystem : null;
	}

	/**
	 * Normalize a key and keep it below the packages root.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Provider-scoped object key as supplied by the caller.
	 * @return string The normalized key, safe to append to the packages root.
	 *
	 * @throws StorageException When the key is empty, absolute, contains a null
	 *                           byte, attempts to traverse above the root, or targets
	 *                           one of the packages root's guard files.
	 */
	private function normalizeKey( string $key ): string {
		$normalized = ltrim( str_replace( '\\', '/', $key ), '/' );

		if (
			'' === $normalized
			|| str_contains( $normalized, "\0" )
			|| str_contains( $normalized, '..' )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw StorageException::invalidKey( $key );
		}

		// A root-level key matching a guard file would overwrite the deny-all
		// protection when stored. Guard basenames deeper in the tree are harmless, so
		// only reject them when they resolve directly to the packages root.
		if ( '.' === dirname( $normalized ) && in_array( basename( $normalized ), self::RESERVED_GUARD_FILES, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw StorageException::invalidKey( $key );
		}

		return $normalized;
	}
}
