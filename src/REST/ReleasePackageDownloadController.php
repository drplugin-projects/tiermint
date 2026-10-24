<?php
/**
 * The store owner's release-package download route.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\REST;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Database\Logger;
use DrPlugin\WP\TierMint\Products\ProductRepository;
use DrPlugin\WP\TierMint\Storage\StorageException;
use DrPlugin\WP\TierMint\Storage\StorageManager;
use DrPlugin\WP\TierMint\Updates\Release;
use DrPlugin\WP\TierMint\Updates\ReleasePackageName;
use DrPlugin\WP\TierMint\Updates\ReleaseRepository;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Lets a store owner download a release package.
 * The route stages delivery so the REST server can remain testable.
 *
 * @since 1.0.0
 */
final class ReleasePackageDownloadController extends AbstractRestController {

	/**
	 * Remote retrieval URL lifetime, in seconds.
	 *
	 * @var int
	 */
	private const REDIRECT_TTL = 60;

	/**
	 * File staged for the serve filter.
	 *
	 * @var array{provider: string, key: string, filename: string}|null
	 */
	private ?array $pendingStream = null;

	/**
	 * Wire the controller.
	 *
	 * @since 1.0.0
	 *
	 * @param ReleaseRepository $releases Release reads.
	 * @param ProductRepository $products Product reads, for the synthesized filename.
	 * @param StorageManager    $storage  Storage provider resolution for delivery.
	 * @param Logger            $logger   The download and error audit writer.
	 */
	public function __construct(
		private readonly ReleaseRepository $releases,
		private readonly ProductRepository $products,
		private readonly StorageManager $storage,
		private readonly Logger $logger
	) {
	}

	/**
	 * Register the route and the streaming serve filter.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function hooks(): void {
		parent::hooks();
		add_filter( 'rest_pre_serve_request', array( $this, 'serve' ), 10, 2 );
	}

	/**
	 * Register the download route.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			'/products/(?P<id>\d+)/releases/(?P<release_id>\d+)/package',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'download' ),
					'permission_callback' => array( $this, 'managePermission' ),
					// Both callbacks keep the route schema explicit.
					'args'                => array(
						'id'         => array(
							'type'              => 'integer',
							'required'          => true,
							'minimum'           => 1,
							'validate_callback' => 'rest_validate_request_arg',
							'sanitize_callback' => 'absint',
						),
						'release_id' => array(
							'type'              => 'integer',
							'required'          => true,
							'minimum'           => 1,
							'validate_callback' => 'rest_validate_request_arg',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * Serve a release package or its HEAD response.
	 * A release must belong to the requested product.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|\WP_Error The staged delivery response, or a refusal envelope.
	 */
	public function download( WP_REST_Request $request ) {
		$productId = (int) $request->get_param( 'id' );
		$releaseId = (int) $request->get_param( 'release_id' );

		try {
			$release = $this->releases->find( $releaseId );

			if ( null === $release || $release->productId() !== $productId ) {
				return $this->notFound();
			}

			$product = $this->products->find( $productId );
		} catch ( Throwable $t ) {
			return $this->serverError();
		}

		$filename = ReleasePackageName::synthesize(
			null !== $product ? $product->updateSlug() : null,
			null !== $product ? $product->slug() : null,
			$release->version()
		);

		// HEAD does not read or stream storage.
		if ( 'HEAD' === $request->get_method() ) {
			return $this->deliveryResponse( $filename, $release->filesize() );
		}

		return $this->stageDownload( $release, $filename );
	}

	/**
	 * Stage a local package or redirect to a remote package.
	 * Storage errors log a redacted key.
	 *
	 * @since 1.0.0
	 *
	 * @param Release $release  The owned release.
	 * @param string  $filename The synthesized download filename.
	 * @return WP_REST_Response|\WP_Error The staged 200, the redirect 302, or a refusal envelope.
	 */
	private function stageDownload( Release $release, string $filename ) {
		try {
			$provider = $this->storage->for( $release->storageProvider() );

			if ( ! $provider->exists( $release->storageKey() ) ) {
				return $this->error(
					'tiermint_package_missing',
					__( 'The package for this release is missing from storage. Re-upload the build to restore it.', 'tiermint' ),
					404
				);
			}

			$redirect = $provider->retrievalUrl( $release->storageKey(), self::REDIRECT_TTL );
		} catch ( StorageException $e ) {
			$this->logger->log(
				'error',
				sprintf( 'Release package download failed for release %d: %s', (int) $release->id(), $this->redactKey( $e->getMessage(), $release->storageKey() ) ),
				array( 'release_id' => (int) $release->id() ),
				'release',
				(int) $release->id()
			);

			return $this->serverError();
		}

		// Audit local streams and remote redirects.
		$this->logger->log(
			'download',
			sprintf( 'Release package %s downloaded by an administrator.', $release->version() ),
			array(
				'release_id' => (int) $release->id(),
				'version'    => $release->version(),
			),
			'release',
			(int) $release->id(),
			$this->clientIp()
		);

		if ( null !== $redirect ) {
			$response = new WP_REST_Response( null, 302 );
			$response->header( 'Location', $redirect );

			return $response;
		}

		$this->pendingStream = array(
			'provider' => $release->storageProvider(),
			'key'      => $release->storageKey(),
			'filename' => $filename,
		);

		// The provider sends GET content headers.
		return $this->deliveryResponse( $filename, null );
	}

	/**
	 * Build the staged response with download headers.
	 * Never expose the storage key as a filename.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $filename      The name the browser should save the download under.
	 * @param int|null $contentLength The byte length to advertise (HEAD, from the recorded row),
	 *                                or null to let the streaming provider send its own on a GET.
	 * @return WP_REST_Response The staged response.
	 */
	private function deliveryResponse( string $filename, ?int $contentLength ): WP_REST_Response {
		$response = new WP_REST_Response( null, 200 );

		$response->header( 'Content-Disposition', 'attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		$response->header( 'X-Robots-Tag', 'noindex, nofollow' );

		if ( null !== $contentLength ) {
			$response->header( 'Content-Length', (string) $contentLength );
		}

		return $response;
	}

	/**
	 * Stream the staged package during REST response delivery.
	 *
	 * @since 1.0.0
	 *
	 * @param bool  $served Whether something already served the response.
	 * @param mixed $result The dispatched result (unused; filter signature).
	 * @return bool True when this filter streamed the file; the incoming value otherwise.
	 */
	public function serve( $served, $result ) {
		if ( $served || null === $this->pendingStream ) {
			return (bool) $served;
		}

		$pending             = $this->pendingStream;
		$this->pendingStream = null;

		try {
			// Remote providers redirect before this filter runs.
			$this->storage->for( $pending['provider'] )->stream( $pending['key'] );
		} catch ( Throwable $t ) {
			// Headers can already be sent.
			$this->logger->log(
				'error',
				'Release package stream failed: ' . $this->redactKey( $t->getMessage(), $pending['key'] ),
				array( 'provider' => $pending['provider'] )
			);
		}

		return true;
	}

	/**
	 * Redact a storage key from a log message.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message The raw message.
	 * @param string $key     The storage key to redact.
	 * @return string The message with the key replaced by a placeholder.
	 */
	private function redactKey( string $message, string $key ): string {
		return '' === $key ? $message : str_replace( $key, '{key}', $message );
	}

	/**
	 * Return the client IP for the audit log.
	 * Proxy headers are not trusted.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null The IP, or null when unavailable.
	 */
	private function clientIp(): ?string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';

		return '' !== $ip ? $ip : null;
	}
}
