<?php
/**
 * REST controller for the Releases management resource.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\REST;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DateTimeImmutable;
use DrPlugin\WP\TierMint\Products\ProductNotFoundException;
use DrPlugin\WP\TierMint\Products\ProductRepository;
use DrPlugin\WP\TierMint\Storage\StorageException;
use DrPlugin\WP\TierMint\Updates\DuplicateReleaseVersionException;
use DrPlugin\WP\TierMint\Updates\Release;
use DrPlugin\WP\TierMint\Updates\ReleaseException;
use DrPlugin\WP\TierMint\Updates\ReleaseNotFoundException;
use DrPlugin\WP\TierMint\Updates\ReleasePackageName;
use DrPlugin\WP\TierMint\Updates\ReleaseService;
use DrPlugin\WP\TierMint\Updates\ReleaseTransitionException;
use DrPlugin\WP\TierMint\Updates\ReleaseValidationException;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST routes for product release management.
 *
 * The service owns release rules. Package storage details stay private.
 *
 * @since 1.0.0
 */
final class ReleasesController extends AbstractRestController {

	/**
	 * Scalar fields sent to the service. Package files arrive separately.
	 *
	 * @var array<int, string>
	 */
	private const WRITABLE_FIELDS = array(
		'version',
		'changelog',
		'requires_wp',
		'tested_up_to',
		'requires_php',
	);

	/**
	 * The releases domain service this controller delegates to.
	 *
	 * @var ReleaseService
	 */
	private ReleaseService $releases;

	/**
	 * Product reads for package filenames.
	 *
	 * @var ProductRepository
	 */
	private ProductRepository $products;

	/**
	 * Tells whether PHP received a temp file with this request.
	 *
	 * @var callable(string): bool
	 */
	private $isUploadedFile;

	/**
	 * Set release services and product reads.
	 *
	 * @since 1.0.0
	 *
	 * @param ReleaseService    $releases       The releases domain service.
	 * @param ProductRepository $products       Product reads for the synthesized package filename.
	 * @param callable|null     $isUploadedFile Upload check; defaults to is_uploaded_file().
	 */
	public function __construct( ReleaseService $releases, ProductRepository $products, ?callable $isUploadedFile = null ) {
		$this->releases       = $releases;
		$this->products       = $products;
		$this->isUploadedFile = $isUploadedFile ?? static function ( string $path ): bool {
			// The WordPress test suite cannot make a real upload. Core's REST
			// attachments controller skips the check there too.
			if ( defined( 'DIR_TESTDATA' ) && DIR_TESTDATA ) {
				return is_file( $path );
			}

			return is_uploaded_file( $path );
		};
	}

	/**
	 * Register the Releases collection, single-item, publish, and yank routes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			'/products/(?P<id>\d+)/releases',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'managePermission' ),
					'args'                => $this->productIdArgs(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'managePermission' ),
					'args'                => array_merge( $this->productIdArgs(), $this->writeArgs() ),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/products/(?P<id>\d+)/releases/(?P<release_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'show' ),
					'permission_callback' => array( $this, 'managePermission' ),
					'args'                => $this->itemArgs(),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'managePermission' ),
					'args'                => array_merge( $this->itemArgs(), $this->writeArgs() ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => array( $this, 'managePermission' ),
					'args'                => $this->itemArgs(),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/products/(?P<id>\d+)/releases/(?P<release_id>\d+)/publish',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'publish' ),
					'permission_callback' => array( $this, 'managePermission' ),
					'args'                => array_merge( $this->itemArgs(), $this->publishArgs() ),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/products/(?P<id>\d+)/releases/(?P<release_id>\d+)/yank',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'yank' ),
					'permission_callback' => array( $this, 'managePermission' ),
					'args'                => $this->itemArgs(),
				),
			)
		);
	}

	/**
	 * List all product releases.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error The release list, or an error envelope.
	 */
	public function index( WP_REST_Request $request ) {
		try {
			$releases = $this->releases->listForProduct( (int) $request->get_param( 'id' ) );
		} catch ( ProductNotFoundException | ReleaseException | StorageException $e ) {
			return $this->toErrorResponse( $e );
		}

		return new WP_REST_Response( array_map( array( $this, 'releaseToArray' ), $releases ), 200 );
	}

	/**
	 * Create a draft release from a package upload.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error The created release, or an error envelope.
	 */
	public function create( WP_REST_Request $request ) {
		$file = $this->packageFile( $request );

		if ( $file instanceof WP_Error ) {
			return $file;
		}

		if ( array() === $file ) {
			return $this->invalidParams( array( __( 'A package file is required. Upload it as the "package" multipart field.', 'tiermint' ) ) );
		}

		try {
			$release = $this->releases->create( (int) $request->get_param( 'id' ), $this->payload( $request ), $file );
		} catch ( ProductNotFoundException | ReleaseException | StorageException $e ) {
			return $this->toErrorResponse( $e );
		}

		return new WP_REST_Response( $this->releaseToArray( $release ), 201 );
	}

	/**
	 * Get one release belonging to the product.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error The release, or an error envelope.
	 */
	public function show( WP_REST_Request $request ) {
		try {
			$release = $this->ownedRelease( $request );
		} catch ( ProductNotFoundException | ReleaseException | StorageException $e ) {
			return $this->toErrorResponse( $e );
		}

		if ( $release instanceof WP_Error ) {
			return $release;
		}

		return new WP_REST_Response( $this->releaseToArray( $release ), 200 );
	}

	/**
	 * Update one release.
	 *
	 * Published and yanked releases keep their version but accept package replacements.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error The updated release, or an error envelope.
	 */
	public function update( WP_REST_Request $request ) {
		try {
			$owned = $this->ownedRelease( $request );

			if ( $owned instanceof WP_Error ) {
				return $owned;
			}

			$file = $this->packageFile( $request );

			if ( $file instanceof WP_Error ) {
				return $file;
			}

			$release = $this->releases->update(
				(int) $request->get_param( 'release_id' ),
				$this->payload( $request ),
				$file
			);
		} catch ( ProductNotFoundException | ReleaseException | StorageException $e ) {
			return $this->toErrorResponse( $e );
		}

		return new WP_REST_Response( $this->releaseToArray( $release ), 200 );
	}

	/**
	 * Delete a draft or yanked release.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error The deletion result, or an error envelope.
	 */
	public function delete( WP_REST_Request $request ) {
		try {
			$owned = $this->ownedRelease( $request );

			if ( $owned instanceof WP_Error ) {
				return $owned;
			}

			$previous = $this->releaseToArray( $owned );

			$this->releases->delete( (int) $request->get_param( 'release_id' ) );
		} catch ( ProductNotFoundException | ReleaseException | StorageException $e ) {
			return $this->toErrorResponse( $e );
		}

		return new WP_REST_Response(
			array(
				'deleted'  => true,
				'previous' => $previous,
			),
			200
		);
	}

	/**
	 * Publish a draft or yanked release.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error The published release, or an error envelope.
	 */
	public function publish( WP_REST_Request $request ) {
		try {
			$owned = $this->ownedRelease( $request );

			if ( $owned instanceof WP_Error ) {
				return $owned;
			}

			$release = $this->releases->publish(
				(int) $request->get_param( 'release_id' ),
				(bool) $request->get_param( 'override' )
			);
		} catch ( ProductNotFoundException | ReleaseException | StorageException $e ) {
			return $this->toErrorResponse( $e );
		}

		return new WP_REST_Response( $this->releaseToArray( $release ), 200 );
	}

	/**
	 * Yank a published release without deleting it.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error The yanked release, or an error envelope.
	 */
	public function yank( WP_REST_Request $request ) {
		try {
			$owned = $this->ownedRelease( $request );

			if ( $owned instanceof WP_Error ) {
				return $owned;
			}

			$release = $this->releases->yank( (int) $request->get_param( 'release_id' ) );
		} catch ( ProductNotFoundException | ReleaseException | StorageException $e ) {
			return $this->toErrorResponse( $e );
		}

		return new WP_REST_Response( $this->releaseToArray( $release ), 200 );
	}

	/**
	 * Load the release and confirm its product ownership.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return Release|WP_Error The owned release, or the not-found envelope on an ownership mismatch.
	 *
	 * @throws ReleaseNotFoundException When the release id does not exist at all.
	 */
	private function ownedRelease( WP_REST_Request $request ) {
		$productId = (int) $request->get_param( 'id' );
		$release   = $this->releases->get( (int) $request->get_param( 'release_id' ) );

		if ( $release->productId() !== $productId ) {
			return $this->notFound();
		}

		return $release;
	}

	/**
	 * Map a domain exception to an error envelope.
	 *
	 * Check duplicate versions before their persistence parent.
	 *
	 * @since 1.0.0
	 *
	 * @param Throwable $e The caught exception.
	 * @return WP_Error The matching error envelope.
	 */
	private function toErrorResponse( Throwable $e ): WP_Error {
		if ( $e instanceof ReleaseNotFoundException || $e instanceof ProductNotFoundException ) {
			return $this->notFound();
		}

		if ( $e instanceof DuplicateReleaseVersionException ) {
			return $this->error( 'tiermint_duplicate_version', __( 'That version already exists for this product.', 'tiermint' ), 400 );
		}

		if ( $e instanceof ReleaseValidationException ) {
			$errors = $e->getErrors();

			return $this->invalidParams( array() === $errors ? array( $e->getMessage() ) : $errors );
		}

		if ( $e instanceof ReleaseTransitionException ) {
			return $this->error( 'tiermint_conflict', __( 'The release could not be changed because its status changed. Reload and try again.', 'tiermint' ), 409 );
		}

		// Do not expose persistence or storage details.
		return $this->serverError();
	}

	/**
	 * Return the uploaded package, an empty array, or a refusal for a non-ZIP file.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return array<string, mixed>|WP_Error The `$_FILES`-shaped package entry, an empty array, or the refusal.
	 */
	private function packageFile( WP_REST_Request $request ) {
		$files = $request->get_file_params();

		if ( ! isset( $files['package'] ) || ! is_array( $files['package'] ) ) {
			return array();
		}

		$package = $files['package'];

		if ( ! isset( $package['tmp_name'] ) || '' === (string) $package['tmp_name'] ) {
			return array();
		}

		if ( UPLOAD_ERR_OK !== (int) ( $package['error'] ?? UPLOAD_ERR_OK ) ) {
			return array();
		}

		$path = (string) $package['tmp_name'];

		if ( ! ( $this->isUploadedFile )( $path ) ) {
			return array();
		}

		// Judge the content only. Package storage does not use the media file types.
		if ( ! function_exists( 'wp_zip_file_is_valid' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// An empty archive is 22 bytes. ZipArchive opens a 0-byte file as a new
		// archive and deletes it on close, so refuse short files first.
		if ( (int) filesize( $path ) < 22 || ! wp_zip_file_is_valid( $path ) ) {
			return $this->invalidParams( array( __( 'The package must be a ZIP file.', 'tiermint' ) ) );
		}

		return $package;
	}

	/**
	 * Collect scalar fields sent by the client.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return array<string, mixed> The service input array.
	 */
	private function payload( WP_REST_Request $request ): array {
		$data = array();

		foreach ( self::WRITABLE_FIELDS as $field ) {
			if ( $request->has_param( $field ) ) {
				$data[ $field ] = $request->get_param( $field );
			}
		}

		return $data;
	}

	/**
	 * Shape a release for JSON.
	 *
	 * Keep storage details private and return changelog source for the editor.
	 *
	 * @since 1.0.0
	 *
	 * @param Release $release The release to present.
	 * @return array<string, mixed> The response representation.
	 */
	private function releaseToArray( Release $release ): array {
		$data = array(
			'id'               => (int) $release->id(),
			'product_id'       => $release->productId(),
			'version'          => $release->version(),
			'status'           => $release->status()->value,
			'has_package'      => true,
			'package_filename' => $this->packageFilename( $release ),
			'filesize'         => $release->filesize(),
			'checksum_sha256'  => $release->checksumSha256(),
			'changelog'        => $release->changelog(),
			'requires_wp'      => $release->requiresWp(),
			'tested_up_to'     => $release->testedUpTo(),
			'requires_php'     => $release->requiresPhp(),
			'released_at'      => $this->formatInstant( $release->releasedAt() ),
			'created_at'       => $this->formatInstant( $release->createdAt() ),
			'updated_at'       => $this->formatInstant( $release->updatedAt() ),
		);

		/**
		 * Filters a release in the releases REST responses.
		 *
		 * An extension that stores its own data per release adds it here. A return
		 * that is not an array keeps core's data.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $data    The release fields.
		 * @param Release              $release The release.
		 */
		$filtered = apply_filters( 'tiermint_rest_release', $data, $release );

		return is_array( $filtered ) ? $filtered : $data;
	}

	/**
	 * Return the synthesized release package filename.
	 *
	 * @since 1.0.0
	 *
	 * @param Release $release The release to name.
	 * @return string The synthesized package filename.
	 */
	private function packageFilename( Release $release ): string {
		$product = $this->products->find( $release->productId() );

		return ReleasePackageName::synthesize(
			null !== $product ? $product->updateSlug() : null,
			null !== $product ? $product->slug() : null,
			$release->version()
		);
	}

	/**
	 * Format a UTC instant for JSON.
	 *
	 * @since 1.0.0
	 *
	 * @param DateTimeImmutable|null $instant The instant, or null.
	 * @return string|null The ISO 8601 UTC string, or null.
	 */
	private function formatInstant( ?DateTimeImmutable $instant ): ?string {
		return null === $instant ? null : $instant->format( 'Y-m-d\TH:i:s\Z' );
	}

	/**
	 * The `args` schema for the `{id}` product path parameter.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>> The parameter schema.
	 */
	private function productIdArgs(): array {
		return array(
			'id' => array(
				'description'       => __( 'Unique product identifier.', 'tiermint' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * The `args` schema for the `{id}` and `{release_id}` path parameters.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>> The parameter schema.
	 */
	private function itemArgs(): array {
		return $this->productIdArgs() + array(
			'release_id' => array(
				'description'       => __( 'Unique release identifier.', 'tiermint' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * Return scalar create and update fields.
	 *
	 * The service reports missing business fields. Package files use multipart data.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>> The parameter schema.
	 */
	private function writeArgs(): array {
		return array(
			'version'      => array(
				'description'       => __( 'Release version (one to four dot-separated numbers, optional pre-release suffix).', 'tiermint' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'changelog'    => array(
				'description'       => __( 'Release changelog as Markdown source.', 'tiermint' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'requires_wp'  => array(
				'description'       => __( 'Minimum required WordPress version.', 'tiermint' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'tested_up_to' => array(
				'description'       => __( 'Highest WordPress version tested against.', 'tiermint' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'requires_php' => array(
				'description'       => __( 'Minimum required PHP version.', 'tiermint' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * The `args` schema for the publish route's optional override flag.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>> The parameter schema.
	 */
	private function publishArgs(): array {
		return array(
			'override' => array(
				'description'       => __( 'Bypass the version monotonicity check for a deliberate re-publish.', 'tiermint' ),
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}
}
