<?php
/**
 * Resolves storage providers by id.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Storage;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Resolves providers from `tiermint_storage_providers`.
 * The provider map is cached for the request.
 *
 * @since 1.0.0
 */
final class StorageManager {

	/**
	 * Providers keyed by ID.
	 *
	 * @var array<string, StorageProvider>
	 */
	private array $providers;

	/**
	 * Build the manager with local storage as the default provider.
	 *
	 * @since 1.0.0
	 *
	 * @param LocalStorage $localStorage The default local filesystem provider.
	 */
	public function __construct( LocalStorage $localStorage ) {
		/**
		 * Filters registered storage providers.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, StorageProvider> $providers Provider map, 'local' => LocalStorage by default.
		 */
		$this->providers = apply_filters( 'tiermint_storage_providers', array( 'local' => $localStorage ) );
	}

	/**
	 * Resolve the storage provider registered under the given id.
	 *
	 * @since 1.0.0
	 *
	 * @param string $providerId The provider id to resolve, e.g. 'local'.
	 * @return StorageProvider
	 *
	 * @throws StorageException When no provider is registered under $providerId
	 *                           (an adapter deactivated after files were stored
	 *                           must fail loud, never a silent 404).
	 */
	public function for( string $providerId ): StorageProvider {
		if ( ! isset( $this->providers[ $providerId ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer exception; $providerId is a trusted internal service id, never user input or HTML output.
			throw StorageException::unknownProvider( $providerId );
		}

		return $this->providers[ $providerId ];
	}
}
