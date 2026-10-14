<?php
/**
 * Signed download delivery REST endpoints.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\REST;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DateTimeImmutable;
use DateTimeZone;
use DrPlugin\WP\TierMint\Customers\CustomerRepository;
use DrPlugin\WP\TierMint\Database\Logger;
use DrPlugin\WP\TierMint\Licensing\License;
use DrPlugin\WP\TierMint\Licensing\LicensePersistenceException;
use DrPlugin\WP\TierMint\Licensing\LicenseRepository;
use DrPlugin\WP\TierMint\Licensing\LicenseService;
use DrPlugin\WP\TierMint\Licensing\LicenseStatus;
use DrPlugin\WP\TierMint\Orders\Order;
use DrPlugin\WP\TierMint\Orders\OrderRepository;
use DrPlugin\WP\TierMint\Orders\OrderStatus;
use DrPlugin\WP\TierMint\Products\Product;
use DrPlugin\WP\TierMint\Products\ProductRepository;
use DrPlugin\WP\TierMint\Storage\StorageException;
use DrPlugin\WP\TierMint\Storage\StorageManager;
use DrPlugin\WP\TierMint\Updates\CustomerDownloads;
use DrPlugin\WP\TierMint\Updates\PackageSigner;
use DrPlugin\WP\TierMint\Updates\Release;
use DrPlugin\WP\TierMint\Updates\ReleasePackageName;
use DrPlugin\WP\TierMint\Updates\ReleaseRepository;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Protected package and order-file delivery routes.
 *
 * Routes authorize first. The serve filter streams or redirects the stored file.
 *
 * @since 1.0.0
 */
final class DownloadController extends AbstractRestController {

	/**
	 * The URL signer verifying presented grants.
	 *
	 * @var PackageSigner
	 */
	private PackageSigner $signer;

	/**
	 * License reads for the download-time validity re-check.
	 *
	 * @var LicenseRepository
	 */
	private LicenseRepository $licenses;

	/**
	 * The licensing service, for lazy expiry at download time.
	 *
	 * @var LicenseService
	 */
	private LicenseService $licenseSvc;

	/**
	 * Release reads.
	 *
	 * @var ReleaseRepository
	 */
	private ReleaseRepository $releases;

	/**
	 * Product reads (filenames, purchased file lists).
	 *
	 * @var ProductRepository
	 */
	private ProductRepository $products;

	/**
	 * Order reads for the ownership checks.
	 *
	 * @var OrderRepository
	 */
	private OrderRepository $orders;

	/**
	 * Customer reads (WP user to customer resolution).
	 *
	 * @var CustomerRepository
	 */
	private CustomerRepository $customers;

	/**
	 * The shared purchased-file assembler backing `GET /me/downloads`.
	 *
	 * @var CustomerDownloads
	 */
	private CustomerDownloads $downloads;

	/**
	 * Storage provider resolution for delivery.
	 *
	 * @var StorageManager
	 */
	private StorageManager $storage;

	/**
	 * The download audit log writer.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * File staged for the serve filter, or null.
	 *
	 * @var array{provider: string, key: string, filename: string}|null
	 */
	private ?array $pendingStream = null;

	/**
	 * Wire the controller.
	 *
	 * @since 1.0.0
	 *
	 * @param PackageSigner      $signer     Signed URL verification and minting.
	 * @param LicenseRepository  $licenses   License reads.
	 * @param LicenseService     $licenseSvc Lazy expiry at download time.
	 * @param ReleaseRepository  $releases   Release reads.
	 * @param ProductRepository  $products   Product reads.
	 * @param OrderRepository    $orders     Order reads.
	 * @param CustomerRepository $customers  Customer reads.
	 * @param StorageManager     $storage    Storage provider resolution.
	 * @param Logger             $logger     Download audit logging.
	 * @param CustomerDownloads  $downloads  The shared purchased-file assembler.
	 */
	public function __construct(
		PackageSigner $signer,
		LicenseRepository $licenses,
		LicenseService $licenseSvc,
		ReleaseRepository $releases,
		ProductRepository $products,
		OrderRepository $orders,
		CustomerRepository $customers,
		StorageManager $storage,
		Logger $logger,
		CustomerDownloads $downloads
	) {
		$this->signer     = $signer;
		$this->licenses   = $licenses;
		$this->licenseSvc = $licenseSvc;
		$this->releases   = $releases;
		$this->products   = $products;
		$this->orders     = $orders;
		$this->customers  = $customers;
		$this->storage    = $storage;
		$this->logger     = $logger;
		$this->downloads  = $downloads;
	}

	/**
	 * Register routes and the streaming serve filter.
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
	 * Register the two signed delivery routes and the customer list route.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			'/package/download',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'downloadPackage' ),
					// The signature in the URL is the credential.
					'permission_callback' => '__return_true',
					'args'                => $this->grantArgs( array( 'product', 'release', 'license_key_id' ), array( 'site_hash' ) ),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/order/download',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'downloadOrderFile' ),
					'permission_callback' => '__return_true',
					'args'                => $this->grantArgs( array( 'order', 'item', 'customer' ), array( 'token' ) ),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/me/downloads',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'myDownloads' ),
					'permission_callback' => 'is_user_logged_in',
					'args'                => array(
						'page' => array(
							'type'              => 'integer',
							'required'          => false,
							'default'           => 1,
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * The shared args schema of a signed grant: the named integer fields,
	 * the named string fields, plus expires and signature.
	 *
	 * @param array<int, string> $intFields    Required integer grant fields.
	 * @param array<int, string> $stringFields Required string grant fields.
	 * @return array<string, array<string, mixed>> The args schema.
	 */
	private function grantArgs( array $intFields, array $stringFields = array() ): array {
		$args = array();

		foreach ( $intFields as $field ) {
			$args[ $field ] = array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			);
		}

		foreach ( $stringFields as $field ) {
			$args[ $field ] = array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			);
		}

		$args['expires']   = array(
			'type'              => 'integer',
			'required'          => true,
			'sanitize_callback' => 'absint',
		);
		$args['signature'] = array(
			'type'              => 'string',
			'required'          => true,
			'sanitize_callback' => 'sanitize_text_field',
		);

		return $args;
	}

	/**
	 * Verify a package grant and stage delivery.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error The staged delivery response, or an error envelope.
	 */
	public function downloadPackage( WP_REST_Request $request ) {
		$productId    = (int) $request->get_param( 'product' );
		$releaseId    = (int) $request->get_param( 'release' );
		$licenseKeyId = (int) $request->get_param( 'license_key_id' );
		$siteHash     = trim( (string) $request->get_param( 'site_hash' ) );
		$expires      = (int) $request->get_param( 'expires' );
		$signature    = trim( (string) $request->get_param( 'signature' ) );

		$verdictError = $this->verdictError(
			$this->signer->verifyPackage( $productId, $releaseId, $licenseKeyId, $siteHash, $expires, $signature )
		);

		if ( null !== $verdictError ) {
			return $verdictError;
		}

		try {
			$license = $this->currentlyValidLicense( $licenseKeyId, $productId );

			if ( $license instanceof WP_Error ) {
				return $license;
			}

			$release = $this->releases->find( $releaseId );

			if ( null === $release || $release->productId() !== $productId ) {
				return $this->notFound();
			}

			if ( ! $release->isPublished() ) {
				// A yanked (or somehow unpublished) release stops serving
				// instantly, outstanding signed URLs included.
				return $this->forbidden();
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

		$staged = $this->stageDelivery( $release->storageProvider(), $release->storageKey(), $filename );

		if ( $staged instanceof WP_Error ) {
			return $staged;
		}

		$this->logger->log(
			'download',
			'Package downloaded.',
			array(
				'product_id' => $productId,
				'release_id' => $releaseId,
				'version'    => $release->version(),
				'license_id' => $licenseKeyId,
			),
			'release',
			$releaseId,
			$this->clientIp()
		);

		/**
		 * Fires before authorized package delivery.
		 *
		 * @since 1.0.0
		 *
		 * @param Release $release The release being delivered.
		 * @param License $license The license the grant was verified against.
		 */
		do_action( 'tiermint_package_downloaded', $release, $license );

		return $staged;
	}

	/**
	 * Verify an order-file grant and stage delivery.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error The staged delivery response, or an error envelope.
	 */
	public function downloadOrderFile( WP_REST_Request $request ) {
		$orderId    = (int) $request->get_param( 'order' );
		$itemId     = (int) $request->get_param( 'item' );
		$customerId = (int) $request->get_param( 'customer' );
		$token      = trim( (string) $request->get_param( 'token' ) );
		$expires    = (int) $request->get_param( 'expires' );
		$signature  = trim( (string) $request->get_param( 'signature' ) );

		$verdictError = $this->verdictError(
			$this->signer->verifyOrderFile( $orderId, $itemId, $customerId, $token, $expires, $signature )
		);

		if ( null !== $verdictError ) {
			return $verdictError;
		}

		try {
			$order = $this->orders->find( $orderId );

			if ( null === $order ) {
				return $this->notFound();
			}

			if ( (int) $order->customerId() !== $customerId || ! $this->orderDelivers( $order ) ) {
				// Wrong owner, refunded, pending, failed: the grant no longer
				// (or never did) deliver. One generic refusal for all of them.
				return $this->forbidden();
			}

			$file = $this->resolveOrderFile( $order, $itemId, $token );
		} catch ( Throwable $t ) {
			return $this->serverError();
		}

		if ( null === $file ) {
			// The item is not on this order or the product's files changed
			// since the URL was minted; a fresh /me/downloads list recovers.
			return $this->notFound();
		}

		$staged = $this->stageDelivery(
			$file['provider'],
			$file['key'],
			'' !== $file['label'] ? $file['label'] : basename( $file['key'] )
		);

		if ( $staged instanceof WP_Error ) {
			return $staged;
		}

		$this->logger->log(
			'download',
			'Purchased file downloaded.',
			array(
				'order_id'      => $orderId,
				'order_item_id' => $itemId,
				'customer_id'   => $customerId,
			),
			'order',
			$orderId,
			$this->clientIp()
		);

		return $staged;
	}

	/**
	 * List downloadable files for the logged-in customer.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request (carries the optional page).
	 * @return WP_REST_Response|WP_Error The downloads list.
	 */
	public function myDownloads( WP_REST_Request $request ) {
		try {
			$customer = $this->customers->findByUserId( get_current_user_id() );

			if ( null === $customer ) {
				return new WP_REST_Response( array(), 200 );
			}

			$page    = max( 1, (int) $request->get_param( 'page' ) );
			$entries = $this->downloads->purchasedFileEntries( $customer, $page );
		} catch ( Throwable $t ) {
			return $this->serverError();
		}

		return new WP_REST_Response( $entries, 200 );
	}

	/**
	 * Serve a staged file after REST dispatch.
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

		if ( ! headers_sent() ) {
			header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $pending['filename'] ) . '"' );
			header( 'X-Robots-Tag: noindex, nofollow' );
		}

		try {
			// LocalStorage sends Content-Type and Content-Length itself and
			// falls back to readfile; remote providers were already
			// 302-redirected in stageDelivery and never reach here.
			$this->storage->for( $pending['provider'] )->stream( $pending['key'] );
		} catch ( Throwable $t ) {
			// Headers may already be out; there is nothing safe to add to the
			// body. The failed delivery surfaces in the site's PHP error log.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- last-resort visibility for a failed stream after headers.
			error_log( 'TierMint: package stream failed: ' . $t->getMessage() );
		}

		return true;
	}

	/**
	 * Deliver a stored object through streaming or a provider redirect.
	 *
	 * @param string $providerId The storage provider id.
	 * @param string $key        The provider-scoped object key.
	 * @param string $filename   The client-facing download filename.
	 * @return WP_REST_Response|WP_Error The staged 200 or redirect 302, or a generic 500.
	 */
	private function stageDelivery( string $providerId, string $key, string $filename ) {
		try {
			$provider = $this->storage->for( $providerId );

			if ( ! $provider->exists( $key ) ) {
				// The row points at a missing object: a server-side fault,
				// never the caller's. Generic 500; details go to the log.
				$this->logger->log(
					'error',
					'Download failed: stored object missing.',
					array(
						'provider' => $providerId,
						'key'      => $key,
					)
				);

				return $this->serverError();
			}

			$redirect = $provider->retrievalUrl( $key, 60 );
		} catch ( StorageException $e ) {
			return $this->serverError();
		}

		if ( null !== $redirect ) {
			$response = new WP_REST_Response( null, 302 );
			$response->header( 'Location', $redirect );

			return $response;
		}

		$this->pendingStream = array(
			'provider' => $providerId,
			'key'      => $key,
			'filename' => $filename,
		);

		return new WP_REST_Response( null, 200 );
	}

	/**
	 * Load a currently valid license for the granted product.
	 *
	 * @param int $licenseKeyId The license row id from the grant.
	 * @param int $productId    The product id from the grant.
	 * @return License|WP_Error The valid license, or the refusal envelope.
	 * @throws LicensePersistenceException When the license read faults (caller maps to 500).
	 */
	private function currentlyValidLicense( int $licenseKeyId, int $productId ) {
		$license = $this->licenses->find( $licenseKeyId );

		if ( null === $license || $license->productId() !== $productId ) {
			return $this->forbidden();
		}

		$license = $this->licenseSvc->expireLazily(
			$license,
			new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) )
		);

		if ( LicenseStatus::Active !== $license->status() ) {
			return $this->forbidden();
		}

		return $license;
	}

	/**
	 * Check if an order still delivers files.
	 *
	 * @param Order $order The order.
	 * @return bool True when files may be served.
	 */
	private function orderDelivers( Order $order ): bool {
		return in_array( $order->status(), array( OrderStatus::Completed, OrderStatus::PartiallyRefunded ), true );
	}

	/**
	 * Resolve an order-file token back to a current product file of the
	 * given order item.
	 *
	 * @param Order  $order  The delivering order.
	 * @param int    $itemId The order item id from the grant.
	 * @param string $token  The file token from the grant.
	 * @return array{provider: string, key: string, label: string}|null The file, or null.
	 */
	private function resolveOrderFile( Order $order, int $itemId, string $token ): ?array {
		foreach ( $order->items() as $item ) {
			if ( (int) $item->id() !== $itemId ) {
				continue;
			}

			foreach ( $this->deliverableProducts( $item->productId() ) as $product ) {
				foreach ( $product->files() as $file ) {
					if ( hash_equals( $this->fileToken( $file ), $token ) ) {
						return array(
							'provider' => (string) $file['provider'],
							'key'      => (string) $file['key'],
							'label'    => (string) ( $file['label'] ?? '' ),
						);
					}
				}
			}

			return null;
		}

		return null;
	}

	/**
	 * Get file-carrying products for an order item.
	 *
	 * @param int $productId The order item's product id.
	 * @return array<int, Product> The file-carrying candidate products.
	 */
	private function deliverableProducts( int $productId ): array {
		$product = $this->products->find( $productId );

		if ( null === $product ) {
			return array();
		}

		if ( ! $product->isBundle() ) {
			return array( $product );
		}

		$children = array();

		foreach ( $product->bundleItems() as $bundleItem ) {
			$child = $this->products->find( $bundleItem->childProductId() );

			if ( null !== $child ) {
				$children[] = $child;
			}
		}

		return $children;
	}

	/**
	 * Build a file token pinned to its provider and key.
	 *
	 * @param array<string, mixed> $file The product file reference.
	 * @return string The 64-hex token.
	 */
	private function fileToken( array $file ): string {
		return hash( 'sha256', (string) ( $file['provider'] ?? '' ) . '|' . (string) ( $file['key'] ?? '' ) );
	}

	/**
	 * Map a signer verdict to its refusal envelope, or null when valid.
	 *
	 * @param string $verdict A PackageSigner VERDICT_* value.
	 * @return WP_Error|null The 403 envelope, or null to proceed.
	 */
	private function verdictError( string $verdict ): ?WP_Error {
		if ( PackageSigner::VERDICT_INVALID_SIGNATURE === $verdict ) {
			return $this->error(
				'tiermint_invalid_signature',
				__( 'This download link is not valid.', 'tiermint' ),
				403
			);
		}

		if ( PackageSigner::VERDICT_EXPIRED === $verdict ) {
			return $this->error(
				'tiermint_expired_url',
				__( 'This download link has expired. Please retry the download to receive a fresh link.', 'tiermint' ),
				403
			);
		}

		return null;
	}

	/**
	 * The generic 403 for grants that verified but no longer deliver.
	 *
	 * @return WP_Error The envelope.
	 */
	private function forbidden(): WP_Error {
		return $this->error(
			'tiermint_forbidden',
			__( 'This download is not available for this license or order.', 'tiermint' ),
			403
		);
	}

	/**
	 * Get the direct client IP for download auditing.
	 *
	 * @return string|null The IP, or null when unavailable.
	 */
	private function clientIp(): ?string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';

		return '' !== $ip ? $ip : null;
	}
}
