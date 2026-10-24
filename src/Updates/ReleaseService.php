<?php
/**
 * Release lifecycle service.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Updates;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Products\Product;
use DrPlugin\WP\TierMint\Products\ProductNotFoundException;
use DrPlugin\WP\TierMint\Products\ProductService;
use DrPlugin\WP\TierMint\Storage\StorageException;
use DrPlugin\WP\TierMint\Storage\StorageManager;
use DrPlugin\WP\TierMint\Storage\StoredFile;

/**
 * Release lifecycle service.
 *
 * It validates packages and controls release state and storage.
 *
 * @since 1.0.0
 */
class ReleaseService {

	/**
	 * Package storage provider.
	 *
	 * @var string
	 */
	private const STORAGE_PROVIDER = 'local';

	/**
	 * Bytes read when checking a plugin header.
	 *
	 * @var int
	 */
	private const HEADER_SCAN_BYTES = 8192;

	/**
	 * Accepted release-version pattern.
	 *
	 * @var string
	 */
	private const VERSION_PATTERN = '/^\d+(\.\d+){0,3}(-[0-9A-Za-z][0-9A-Za-z.\-]*)?$/';

	/**
	 * Release fields an edit can change. Only a draft can change its version.
	 *
	 * @var array<int, string>
	 */
	private const EDITABLE_FIELDS = array( 'version', 'changelog', 'requires_wp', 'tested_up_to', 'requires_php' );

	/**
	 * Columns a package replacement writes.
	 *
	 * @var array<int, string>
	 */
	private const PACKAGE_COLUMNS = array( 'storage_provider', 'storage_key', 'filesize', 'checksum_sha256' );

	/**
	 * The releases repository this service reads and writes through.
	 *
	 * @var ReleaseRepository
	 */
	private ReleaseRepository $repository;

	/**
	 * Release transition guard.
	 *
	 * @var ReleaseStateMachine
	 */
	private ReleaseStateMachine $machine;

	/**
	 * The product service, consulted for product existence and the update slug.
	 *
	 * @var ProductService
	 */
	private ProductService $products;

	/**
	 * Package storage manager.
	 *
	 * @var StorageManager
	 */
	private StorageManager $storage;

	/**
	 * Wire the service with its collaborators.
	 *
	 * @since 1.0.0
	 *
	 * @param ReleaseRepository   $repository The releases repository.
	 * @param ReleaseStateMachine $machine    The release status guard.
	 * @param ProductService      $products   The product service.
	 * @param StorageManager      $storage    The storage manager.
	 */
	public function __construct(
		ReleaseRepository $repository,
		ReleaseStateMachine $machine,
		ProductService $products,
		StorageManager $storage
	) {
		$this->repository = $repository;
		$this->machine    = $machine;
		$this->products   = $products;
		$this->storage    = $storage;
	}

	/**
	 * Create a validated draft release and store its package.
	 *
	 * @since 1.0.0
	 *
	 * @param int                  $productId The owning product id.
	 * @param array<string, mixed> $data      Release fields: `version` (required), and the
	 *                                         optional `changelog`, `requires_wp`,
	 *                                         `tested_up_to`, `requires_php` strings.
	 * @param array<string, mixed> $file      The uploaded package, in `$_FILES` shape
	 *                                         (`tmp_name`, `error`, ...).
	 * @return Release The reloaded, persisted draft release.
	 *
	 * @throws ReleaseValidationException When the version is invalid, no package was uploaded,
	 *                                    or the package fails the plugin-header check.
	 */
	public function create( int $productId, array $data, array $file ): Release {
		$product = $this->requireProduct( $productId );

		$errors  = array();
		$version = trim( (string) ( $data['version'] ?? '' ) );

		$this->validateVersion( $version, $errors );

		if ( ! $this->hasPackage( $file ) ) {
			$errors[] = __( 'A release must include a package file.', 'tiermint' );
		}

		if ( array() !== $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw ReleaseValidationException::withErrors( $errors );
		}

		// Validate the temporary upload before storing it.
		$this->assertPackageMatchesSlug( (string) $file['tmp_name'], $product->updateSlug() );

		$stored = $this->storage->for( self::STORAGE_PROVIDER )->store(
			(string) $file['tmp_name'],
			$this->buildStorageKey( $productId )
		);

		$release = new Release(
			null,
			$productId,
			$version,
			$stored->providerId(),
			$stored->key(),
			$stored->filesize(),
			$stored->sha256(),
			$this->optionalString( $data, 'requires_wp' ),
			$this->optionalString( $data, 'tested_up_to' ),
			$this->optionalString( $data, 'requires_php' ),
			$this->optionalString( $data, 'changelog' ),
			ReleaseStatus::Draft,
			null,
			null,
			null
		);

		try {
			return $this->repository->create( $release );
		} catch ( \Throwable $t ) {
			// Remove the newly stored orphan if the database write fails.
			try {
				$this->storage->for( $stored->providerId() )->delete( $stored->key() );
			} catch ( \Throwable $cleanupFailure ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- An orphaned object is preferable to masking the real failure.
			}

			throw $t;
		}
	}

	/**
	 * Validate and apply changes to a release.
	 *
	 * Keys other than the version, changelog and compatibility fields are ignored.
	 *
	 * @since 1.0.0
	 *
	 * @param int                  $releaseId The release id to update.
	 * @param array<string, mixed> $data      The partial release fields to change.
	 * @param array<string, mixed> $file      An optional replacement package, in `$_FILES` shape.
	 * @return Release The reloaded, persisted release.
	 *
	 * @throws ReleaseNotFoundException         When no release has the given id.
	 * @throws ReleaseValidationException       When a field is invalid or the release is locked.
	 * @throws DuplicateReleaseVersionException When a new version already exists for the product.
	 * @throws ReleasePersistenceException      When the database write fails.
	 * @throws StorageException                 When a replacement package cannot be stored.
	 */
	public function update( int $releaseId, array $data, array $file = array() ): Release {
		$existing = $this->requireRelease( $releaseId );

		if ( ReleaseStatus::Draft !== $existing->status() ) {
			return $this->updateLockedRelease( $existing, $data, $file );
		}

		return $this->updateDraftRelease( $existing, $data, $file );
	}

	/**
	 * Publish a release for update checks.
	 *
	 * @since 1.0.0
	 *
	 * @param int  $releaseId The release id to publish.
	 * @param bool $override  Whether to bypass the version monotonicity check.
	 * @return Release The reloaded, published release.
	 *
	 * @throws ReleaseTransitionException When a concurrent writer won the transition first.
	 */
	public function publish( int $releaseId, bool $override = false ): Release {
		$release = $this->requireRelease( $releaseId );

		if ( ! $override ) {
			$this->assertVersionIsNewer( $release );
		}

		$won = $this->repository->transitionStatus(
			$release,
			$this->machine->sourcesFor( ReleaseStatus::Published ),
			ReleaseStatus::Published
		);

		if ( ! $won ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw ReleaseTransitionException::between( $release->status(), ReleaseStatus::Published );
		}

		$published = $this->requireRelease( $releaseId );

		/**
		 * Fires after a release is published.
		 *
		 * @since 1.0.0
		 *
		 * @param Release $published The freshly published release, reloaded with its stamped `released_at`.
		 */
		do_action( 'tiermint_release_published', $published );

		return $published;
	}

	/**
	 * Yank a published release from update checks.
	 *
	 * @since 1.0.0
	 *
	 * @param int $releaseId The release id to yank.
	 * @return Release The reloaded, yanked release.
	 *
	 * @throws ReleaseTransitionException When the release is not published, or a concurrent
	 *                                    writer won the transition first. A missing release
	 *                                    ({@see ReleaseNotFoundException}) and a database write
	 *                                    fault ({@see ReleasePersistenceException}) also propagate.
	 */
	public function yank( int $releaseId ): Release {
		$release = $this->requireRelease( $releaseId );

		$won = $this->repository->transitionStatus(
			$release,
			$this->machine->sourcesFor( ReleaseStatus::Yanked ),
			ReleaseStatus::Yanked
		);

		if ( ! $won ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw ReleaseTransitionException::between( $release->status(), ReleaseStatus::Yanked );
		}

		return $this->requireRelease( $releaseId );
	}

	/**
	 * Delete a draft or yanked release and its package.
	 *
	 * @since 1.0.0
	 *
	 * @param int $releaseId The release id to delete.
	 * @return void
	 *
	 * @throws ReleaseValidationException When the release is published. A missing release
	 *                                   ({@see ReleaseNotFoundException}), a storage failure
	 *                                   removing the package ({@see StorageException}), and a
	 *                                   row-delete fault ({@see ReleasePersistenceException})
	 *                                   also propagate.
	 */
	public function delete( int $releaseId ): void {
		$release = $this->requireRelease( $releaseId );

		if ( $release->isPublished() ) {
			$messages = array( __( 'A published release cannot be deleted. Yank it first, then delete it.', 'tiermint' ) );

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw ReleaseValidationException::withErrors( $messages );
		}

		// Keep the row when package deletion fails.
		$this->storage->for( $release->storageProvider() )->delete( $release->storageKey() );

		$this->repository->delete( $releaseId );
	}

	/**
	 * Load a release by id, failing loudly if it does not exist.
	 *
	 * @since 1.0.0
	 *
	 * @param int $releaseId The release id.
	 * @return Release The release.
	 *
	 * @throws ReleaseNotFoundException When no release has the given id.
	 */
	public function get( int $releaseId ): Release {
		return $this->requireRelease( $releaseId );
	}

	/**
	 * List product releases, newest version first.
	 *
	 * @since 1.0.0
	 *
	 * @param int $productId The owning product id.
	 * @return array<int, Release> The product's releases, newest version first.
	 *
	 * @throws ProductNotFoundException    When no product has the given id.
	 * @throws ReleasePersistenceException When the read faults.
	 */
	public function listForProduct( int $productId ): array {
		$this->requireProduct( $productId );

		return $this->repository->listForProduct( $productId );
	}

	/**
	 * Update a published or yanked release without changing its version.
	 *
	 * @since 1.0.0
	 *
	 * @param Release              $existing The current locked release.
	 * @param array<string, mixed> $data     The partial release fields to change.
	 * @param array<string, mixed> $file     An optional replacement package, in `$_FILES` shape.
	 * @return Release The reloaded release.
	 *
	 * @throws ReleaseValidationException When the version is changed, or a replacement package
	 *                                   fails the plugin-header check. A database write fault
	 *                                   ({@see ReleasePersistenceException}) and a storage
	 *                                   failure ({@see StorageException}) also propagate.
	 */
	private function updateLockedRelease( Release $existing, array $data, array $file ): Release {
		$fields = $this->editableFields( $data );

		// The editor sends the unchanged version with every save.
		if ( array_key_exists( 'version', $fields ) && trim( (string) $fields['version'] ) === $existing->version() ) {
			unset( $fields['version'] );
		}

		if ( array() === $fields && ! $this->hasPackage( $file ) ) {
			return $existing;
		}

		if ( array_key_exists( 'version', $fields ) ) {
			$messages = array( __( "A published or yanked release's version is locked. To ship a different version, yank this release and publish a new one.", 'tiermint' ) );

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw ReleaseValidationException::withErrors( $messages );
		}

		return $this->saveEdit( $existing, $existing->version(), $fields, $file );
	}

	/**
	 * Update a draft release and optionally replace its package.
	 *
	 * @since 1.0.0
	 *
	 * @param Release              $existing The current draft release.
	 * @param array<string, mixed> $data     The partial release fields to change.
	 * @param array<string, mixed> $file     An optional replacement package, in `$_FILES` shape.
	 * @return Release The reloaded release.
	 *
	 * @throws ReleaseValidationException When a supplied version is malformed. A duplicate
	 *                                   version ({@see DuplicateReleaseVersionException}), a
	 *                                   database write fault ({@see ReleasePersistenceException}),
	 *                                   and a storage failure ({@see StorageException}) also
	 *                                   propagate.
	 */
	private function updateDraftRelease( Release $existing, array $data, array $file ): Release {
		$fields = $this->editableFields( $data );

		if ( array() === $fields && ! $this->hasPackage( $file ) ) {
			return $existing;
		}

		$errors  = array();
		$version = $existing->version();

		if ( array_key_exists( 'version', $fields ) ) {
			$version = trim( (string) $fields['version'] );
			$this->validateVersion( $version, $errors );
		}

		if ( array() !== $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw ReleaseValidationException::withErrors( $errors );
		}

		return $this->saveEdit( $existing, $version, $fields, $file );
	}

	/**
	 * Keep only the release fields an edit can change.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $data The edit input.
	 * @return array<string, mixed> The editable fields that were sent.
	 */
	private function editableFields( array $data ): array {
		return array_intersect_key( $data, array_flip( self::EDITABLE_FIELDS ) );
	}

	/**
	 * Store a validated edit, writing only the fields it was sent.
	 *
	 * @since 1.0.0
	 *
	 * @param Release              $existing The release being edited.
	 * @param string               $version  The version to store.
	 * @param array<string, mixed> $fields   The editable fields that were sent.
	 * @param array<string, mixed> $file     An optional replacement package, in `$_FILES` shape.
	 * @return Release The reloaded release.
	 *
	 * @throws ReleaseValidationException       When the replacement fails the plugin-header check.
	 * @throws DuplicateReleaseVersionException When the new version already exists for the product.
	 * @throws ReleasePersistenceException      When the database write fails.
	 * @throws StorageException                 When the replacement cannot be stored.
	 */
	private function saveEdit( Release $existing, string $version, array $fields, array $file ): Release {
		$columns  = array_keys( $fields );
		$provider = $existing->storageProvider();
		$key      = $existing->storageKey();
		$filesize = $existing->filesize();
		$checksum = $existing->checksumSha256();

		// Store a replacement only after every field is valid.
		$stored = $this->storeReplacement( $existing, $file );

		if ( null !== $stored ) {
			$columns  = array_merge( $columns, self::PACKAGE_COLUMNS );
			$provider = $stored->providerId();
			$key      = $stored->key();
			$filesize = $stored->filesize();
			$checksum = $stored->sha256();
		}

		$this->repository->update(
			new Release(
				$existing->id(),
				$existing->productId(),
				$version,
				$provider,
				$key,
				$filesize,
				$checksum,
				array_key_exists( 'requires_wp', $fields ) ? $this->nullableString( $fields['requires_wp'] ) : $existing->requiresWp(),
				array_key_exists( 'tested_up_to', $fields ) ? $this->nullableString( $fields['tested_up_to'] ) : $existing->testedUpTo(),
				array_key_exists( 'requires_php', $fields ) ? $this->nullableString( $fields['requires_php'] ) : $existing->requiresPhp(),
				array_key_exists( 'changelog', $fields ) ? $this->nullableString( $fields['changelog'] ) : $existing->changelog(),
				$existing->status(),
				$existing->releasedAt(),
				$existing->createdAt(),
				$existing->updatedAt()
			),
			$columns
		);

		if ( null !== $stored ) {
			$this->deleteSuperseded( $existing->storageProvider(), $existing->storageKey() );
		}

		return $this->requireRelease( (int) $existing->id() );
	}

	/**
	 * Validate and store a replacement package, if supplied.
	 *
	 * @since 1.0.0
	 *
	 * @param Release              $existing The release whose package is being replaced.
	 * @param array<string, mixed> $file     The uploaded replacement, in `$_FILES` shape.
	 * @return StoredFile|null The stored object, or null when no package was supplied.
	 *
	 * @throws ReleaseValidationException When the replacement fails the plugin-header slug check.
	 * @throws StorageException           When the replacement cannot be stored.
	 */
	private function storeReplacement( Release $existing, array $file ): ?StoredFile {
		if ( ! $this->hasPackage( $file ) ) {
			return null;
		}

		$this->assertPackageMatchesSlug(
			(string) $file['tmp_name'],
			$this->requireProduct( $existing->productId() )->updateSlug()
		);

		return $this->storage->for( self::STORAGE_PROVIDER )->store(
			(string) $file['tmp_name'],
			$this->buildStorageKey( $existing->productId() )
		);
	}

	/**
	 * Delete a superseded package after the row points to its replacement.
	 *
	 * @since 1.0.0
	 *
	 * @param string $provider The provider id the object lives under.
	 * @param string $key      The provider-scoped object key.
	 * @return void
	 */
	private function deleteSuperseded( string $provider, string $key ): void {
		try {
			$this->storage->for( $provider )->delete( $key );
		} catch ( StorageException $e ) {
			// The row update has already succeeded. Keep an orphan on delete failure.
			unset( $e );
		}
	}

	/**
	 * Check that a release is newer than the latest published release.
	 *
	 * @since 1.0.0
	 *
	 * @param Release $release The release about to be published.
	 * @return void
	 *
	 * @throws ReleaseValidationException When the version is not greater than the latest.
	 */
	private function assertVersionIsNewer( Release $release ): void {
		$latest = $this->repository->latestPublished( $release->productId() );

		if ( null === $latest ) {
			return;
		}

		if ( version_compare( $release->version(), $latest->version(), '>' ) ) {
			return;
		}

		$message = sprintf(
			/* translators: 1: the version being published, 2: the current latest published version. */
			__( 'Version %1$s is not newer than the current latest published version %2$s. Publish a higher version, or override to re-publish deliberately.', 'tiermint' ),
			$release->version(),
			$latest->version()
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
		throw ReleaseValidationException::withErrors( array( $message ) );
	}

	/**
	 * Check a version against the accepted shape, appending a message on failure.
	 *
	 * @since 1.0.0
	 *
	 * @param string             $version The version to check.
	 * @param array<int, string> $errors  Error accumulator, appended by reference.
	 * @return void
	 */
	private function validateVersion( string $version, array &$errors ): void {
		if ( '' === $version ) {
			$errors[] = __( 'A release version is required.', 'tiermint' );

			return;
		}

		if ( 1 !== preg_match( self::VERSION_PATTERN, $version ) ) {
			/* translators: %s: the submitted version string. */
			$errors[] = sprintf( __( '"%s" is not a valid version. Use one to four dot-separated numbers, with an optional pre-release suffix like "-beta".', 'tiermint' ), $version );
		}
	}

	/**
	 * Check a plugin package header against the product update slug.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $tmpPath    Absolute path of the uploaded temp zip.
	 * @param string|null $updateSlug The product's update slug, or null for a non-plugin product.
	 * @return void
	 *
	 * @throws ReleaseValidationException When the zip cannot be read, or contains no
	 *                                    `{update_slug}/*.php` file with a `Plugin Name:` header.
	 */
	private function assertPackageMatchesSlug( string $tmpPath, ?string $updateSlug ): void {
		if ( null === $updateSlug || '' === $updateSlug || ! class_exists( '\ZipArchive' ) ) {
			return;
		}

		$zip = new \ZipArchive();

		if ( true !== $zip->open( $tmpPath ) ) {
			$messages = array( __( 'The uploaded package could not be read as a zip archive.', 'tiermint' ) );

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw ReleaseValidationException::withErrors( $messages );
		}

		$pattern = '#^' . preg_quote( $updateSlug, '#' ) . '/[^/]+\.php$#';
		$matched = false;

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );

			if ( 1 !== preg_match( $pattern, $name ) ) {
				continue;
			}

			$contents = $zip->getFromIndex( $i, self::HEADER_SCAN_BYTES );

			if ( is_string( $contents ) && str_contains( $contents, 'Plugin Name:' ) ) {
				$matched = true;
				break;
			}
		}

		$zip->close();

		if ( ! $matched ) {
			$message = sprintf(
				/* translators: %1$s: the product's expected update slug. */
				__( 'The package does not contain a plugin header for the expected update slug "%1$s". Make sure the zip has a "%1$s/%1$s.php" (or similar) file with a "Plugin Name:" header.', 'tiermint' ),
				$updateSlug
			);

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw ReleaseValidationException::withErrors( array( $message ) );
		}
	}

	/**
	 * Check if upload parameters contain a completed package upload.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $file The file params, in `$_FILES` shape.
	 * @return bool True when a package temp file is present and its upload completed cleanly.
	 */
	private function hasPackage( array $file ): bool {
		if ( ! isset( $file['tmp_name'] ) || ! is_string( $file['tmp_name'] ) || '' === $file['tmp_name'] ) {
			return false;
		}

		return UPLOAD_ERR_OK === (int) ( $file['error'] ?? UPLOAD_ERR_OK );
	}

	/**
	 * Build a random storage key for a product package.
	 *
	 * @since 1.0.0
	 *
	 * @param int $productId The owning product id.
	 * @return string The provider-scoped storage key.
	 */
	private function buildStorageKey( int $productId ): string {
		return $productId . '/' . bin2hex( random_bytes( 16 ) ) . '/package.zip';
	}

	/**
	 * Read an optional input string.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $data The input array.
	 * @param string               $key  The field name.
	 * @return string|null The trimmed value, or null.
	 */
	private function optionalString( array $data, string $key ): ?string {
		return array_key_exists( $key, $data ) ? $this->nullableString( $data[ $key ] ) : null;
	}

	/**
	 * Convert a value to a trimmed string or null.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value The raw value.
	 * @return string|null The trimmed string, or null.
	 */
	private function nullableString( $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		$string = trim( (string) $value );

		return '' === $string ? null : $string;
	}

	/**
	 * Load a required product.
	 *
	 * @since 1.0.0
	 *
	 * @param int $productId The product id.
	 * @return Product The product.
	 *
	 * @throws ProductNotFoundException When no product has the given id.
	 */
	private function requireProduct( int $productId ): Product {
		$product = $this->products->get( $productId );

		if ( null === $product ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw ProductNotFoundException::forId( $productId );
		}

		return $product;
	}

	/**
	 * Load a required release.
	 *
	 * @since 1.0.0
	 *
	 * @param int $releaseId The release id.
	 * @return Release The release.
	 *
	 * @throws ReleaseNotFoundException When no release has the given id.
	 */
	private function requireRelease( int $releaseId ): Release {
		$release = $this->repository->find( $releaseId );

		if ( null === $release ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw ReleaseNotFoundException::forId( $releaseId );
		}

		return $release;
	}
}
