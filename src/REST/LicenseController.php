<?php
/**
 * Public REST controller for the licensing surface: activate, deactivate, validate.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\REST;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DateTimeImmutable;
use DateTimeZone;
use DrPlugin\WP\TierMint\Customers\CustomerPersistenceException;
use DrPlugin\WP\TierMint\Customers\CustomerService;
use DrPlugin\WP\TierMint\Licensing\Activation;
use DrPlugin\WP\TierMint\Licensing\ActivationLimitException;
use DrPlugin\WP\TierMint\Licensing\ActivationNotFoundException;
use DrPlugin\WP\TierMint\Licensing\ActivationService;
use DrPlugin\WP\TierMint\Licensing\ActivationStatus;
use DrPlugin\WP\TierMint\Licensing\License;
use DrPlugin\WP\TierMint\Licensing\LicensePersistenceException;
use DrPlugin\WP\TierMint\Licensing\LicenseService;
use DrPlugin\WP\TierMint\Licensing\LicenseStatus;
use DrPlugin\WP\TierMint\Licensing\LicenseValidationException;
use DrPlugin\WP\TierMint\Licensing\LicenseValidationService;
use DrPlugin\WP\TierMint\Products\ProductPersistenceException;
use DrPlugin\WP\TierMint\Products\ProductService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Public endpoints for license validation and activation changes.
 *
 * The key is the credential. The route guard limits requests before dispatch.
 *
 * @since 1.0.0
 */
final class LicenseController extends AbstractRestController {

	/**
	 * The license read/lifecycle service (key lookup, lazy expiry).
	 *
	 * @var LicenseService
	 */
	private LicenseService $licenses;

	/**
	 * The activation service enforcing slots, transfer, and local bypass.
	 *
	 * @var ActivationService
	 */
	private ActivationService $activations;

	/**
	 * The validation service producing the canonical validation payload.
	 *
	 * @var LicenseValidationService
	 */
	private LicenseValidationService $validator;

	/**
	 * The product catalog, for the activate-time product match.
	 *
	 * @var ProductService
	 */
	private ProductService $products;

	/**
	 * The customer directory, for the masked owner email in activate responses.
	 *
	 * @var CustomerService
	 */
	private CustomerService $customers;

	/**
	 * Wire the controller with the licensing domain services.
	 *
	 * @since 1.0.0
	 *
	 * @param LicenseService           $licenses    The license read/lifecycle service.
	 * @param ActivationService        $activations The activation service.
	 * @param LicenseValidationService $validator   The validation service.
	 * @param ProductService           $products    The product catalog service.
	 * @param CustomerService          $customers   The customer directory service.
	 */
	public function __construct(
		LicenseService $licenses,
		ActivationService $activations,
		LicenseValidationService $validator,
		ProductService $products,
		CustomerService $customers
	) {
		$this->licenses    = $licenses;
		$this->activations = $activations;
		$this->validator   = $validator;
		$this->products    = $products;
		$this->customers   = $customers;
	}

	/**
	 * Register public licensing routes.
	 *
	 * The license key is the credential. The route guard runs before dispatch.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			'/license/validate',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'validate' ),
					'permission_callback' => '__return_true',
					'args'                => $this->commonArgs(),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/license/activate',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'activate' ),
					'permission_callback' => '__return_true',
					'args'                => array_merge( $this->commonArgs(), $this->environmentArg() ),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/license/deactivate',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'deactivate' ),
					'permission_callback' => '__return_true',
					'args'                => $this->commonArgs(),
				),
			)
		);
	}

	/**
	 * Return the license verdict in a successful request payload.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error The canonical payload, or an error envelope.
	 */
	public function validate( WP_REST_Request $request ) {
		$licenseKey = trim( (string) $request->get_param( 'license_key' ) );

		if ( '' === $licenseKey ) {
			return $this->invalidParams( array( __( 'A license_key parameter is required.', 'tiermint' ) ) );
		}

		$siteUrl     = trim( (string) $request->get_param( 'site_url' ) );
		$fingerprint = trim( (string) $request->get_param( 'fingerprint' ) );
		$productSlug = trim( (string) $request->get_param( 'product_slug' ) );

		try {
			$result = $this->validator->validate(
				$licenseKey,
				'' !== $siteUrl ? $siteUrl : null,
				'' !== $fingerprint ? $fingerprint : null,
				'' !== $productSlug ? $productSlug : null
			);
		} catch ( LicensePersistenceException $e ) {
			// Let the client's grace period handle an unreadable live result.
			return $this->serverError();
		}

		$payload = $this->withRenewalUrl( $result->toArray(), $licenseKey );

		/**
		 * Filters the completed validate response.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $payload The response payload about to be sent.
		 * @param WP_REST_Request      $request The incoming request.
		 */
		$payload = (array) apply_filters( 'tiermint_license_validate_response', $payload, $request );

		return new WP_REST_Response( $payload, 200 );
	}

	/**
	 * Claim an activation slot for a site or device.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error The canonical payload, or an error envelope.
	 */
	public function activate( WP_REST_Request $request ) {
		$input = $this->requireKeyAndSite( $request );

		if ( $input instanceof WP_Error ) {
			return $input;
		}

		$license = $this->lookupLicense( $input['license_key'] );

		if ( ! $license instanceof License ) {
			return $license;
		}

		try {
			$mismatch = $this->productMismatch( $license, $request );
		} catch ( ProductPersistenceException $e ) {
			return $this->serverError();
		}

		if ( null !== $mismatch ) {
			return $mismatch;
		}

		try {
			$activation = $this->activations->activate(
				$license,
				$input['site_url'],
				$input['fingerprint'],
				$this->sanitizedEnvironment( $request )
			);
		} catch ( ActivationLimitException $e ) {
			return $this->limitReached( $license, $e );
		} catch ( LicenseValidationException $e ) {
			return $this->refusalFor( $license, $e );
		} catch ( LicensePersistenceException $e ) {
			return $this->serverError();
		}

		// A retry reports the saved activation without claiming another slot.
		try {
			// Reload the activation count.
			$fresh = $this->licenses->get( (int) $license->id() ) ?? $license;

			$payload = array(
				'success'    => true,
				'license'    => $this->licenseSummary( $fresh ),
				'activation' => array(
					'id'           => (int) $activation->id(),
					'site_url'     => $activation->siteUrl(),
					'activated_at' => $this->formatUtc( $activation->activatedAt() ),
					'is_local'     => $activation->isLocal(),
				),
			);
		} catch ( LicensePersistenceException | ProductPersistenceException | CustomerPersistenceException $e ) {
			return $this->serverError();
		}

		/**
		 * Filters the completed activate response.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $payload    The response payload about to be sent.
		 * @param License              $license    The freshly reloaded license.
		 * @param Activation           $activation The activation that was claimed.
		 * @param WP_REST_Request      $request    The incoming request.
		 */
		$payload = (array) apply_filters( 'tiermint_license_activate_response', $payload, $fresh, $activation, $request );

		return new WP_REST_Response( $payload, 200 );
	}

	/**
	 * Release a site's activation slot, including for expired or disabled licenses.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error The success payload, or an error envelope.
	 */
	public function deactivate( WP_REST_Request $request ) {
		$input = $this->requireKeyAndSite( $request );

		if ( $input instanceof WP_Error ) {
			return $input;
		}

		$license = $this->lookupLicense( $input['license_key'] );

		if ( ! $license instanceof License ) {
			return $license;
		}

		try {
			$this->activations->deactivate( $license, $input['site_url'], $input['fingerprint'] );
		} catch ( ActivationNotFoundException $e ) {
			return $this->error( 'tiermint_activation_not_found', $e->getMessage(), 404 );
		} catch ( LicenseValidationException $e ) {
			return $this->invalidParams( $e->getErrors() );
		} catch ( LicensePersistenceException $e ) {
			return $this->serverError();
		}

		// A retry is safe if the activation count cannot be reloaded.
		try {
			$fresh = $this->licenses->get( (int) $license->id() ) ?? $license;
		} catch ( LicensePersistenceException $e ) {
			return $this->serverError();
		}

		return new WP_REST_Response(
			array(
				'success'          => true,
				'activation_count' => $fresh->activationCount(),
			),
			200
		);
	}

	/**
	 * Require the inputs shared by both write routes.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return array{license_key: string, site_url: string, fingerprint: ?string}|WP_Error
	 *         The trimmed inputs, or the 400 envelope when one is missing.
	 */
	private function requireKeyAndSite( WP_REST_Request $request ) {
		$licenseKey  = trim( (string) $request->get_param( 'license_key' ) );
		$siteUrl     = trim( (string) $request->get_param( 'site_url' ) );
		$fingerprint = trim( (string) $request->get_param( 'fingerprint' ) );

		$errors = array();

		if ( '' === $licenseKey ) {
			$errors[] = __( 'A license_key parameter is required.', 'tiermint' );
		}

		if ( '' === $siteUrl ) {
			$errors[] = __( 'A site_url parameter is required.', 'tiermint' );
		}

		if ( array() !== $errors ) {
			return $this->invalidParams( $errors );
		}

		return array(
			'license_key' => $licenseKey,
			'site_url'    => $siteUrl,
			'fingerprint' => '' !== $fingerprint ? $fingerprint : null,
		);
	}

	/**
	 * Find a write-route license without exposing an unknown key.
	 *
	 * @since 1.0.0
	 *
	 * @param string $licenseKey The presented key.
	 * @return License|WP_Error The license, or the error envelope to return.
	 */
	private function lookupLicense( string $licenseKey ) {
		try {
			$license = $this->licenses->findByKey( $licenseKey );
		} catch ( LicensePersistenceException $e ) {
			return $this->serverError();
		}

		if ( null === $license ) {
			return $this->error(
				'tiermint_invalid_key',
				__( 'No license matches the supplied key.', 'tiermint' ),
				404
			);
		}

		return $license;
	}

	/**
	 * Check the optional product claim without exposing license details.
	 *
	 * @since 1.0.0
	 *
	 * @param License         $license The resolved license.
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_Error|null The 404 mismatch envelope, or null when the claim matches.
	 *
	 * @throws ProductPersistenceException When the product read faults (the caller
	 *                                     maps it to the generic 500 envelope).
	 */
	private function productMismatch( License $license, WP_REST_Request $request ): ?WP_Error {
		$claim = trim( (string) $request->get_param( 'product_slug' ) );

		if ( '' === $claim ) {
			return null;
		}

		if ( $claim === $this->productSlugFor( $license ) ) {
			return null;
		}

		return $this->error(
			'tiermint_key_mismatch',
			__( 'The license key does not belong to the supplied product.', 'tiermint' ),
			404
		);
	}

	/**
	 * Build the activation-limit response with active sites.
	 *
	 * @since 1.0.0
	 *
	 * @param License                  $license The full license.
	 * @param ActivationLimitException $e       The refusal, carrying the limit.
	 * @return WP_Error The tiermint_activation_limit_reached envelope.
	 */
	private function limitReached( License $license, ActivationLimitException $e ): WP_Error {
		$sites = array();

		try {
			foreach ( $this->activations->listForLicense( $license, ActivationStatus::Active ) as $activation ) {
				$sites[] = array(
					'id'       => (int) $activation->id(),
					'site_url' => $activation->siteUrl(),
				);
			}
		} catch ( LicensePersistenceException $listFault ) {
			// The refusal does not depend on the site list.
			$sites = array();
		}

		return $this->error(
			'tiermint_activation_limit_reached',
			sprintf(
				/* translators: %d: the license's activation limit. */
				__( 'This license key has reached its activation limit (%d sites).', 'tiermint' ),
				$e->getLimit()
			),
			403,
			array(
				'activation_limit' => $e->getLimit(),
				'activation_count' => $license->activationCount(),
				'activations'      => $sites,
			)
		);
	}

	/**
	 * Map an activation refusal to a stable response.
	 *
	 * @since 1.0.0
	 *
	 * @param License                    $license The license as loaded before the attempt.
	 * @param LicenseValidationException $e       The refusal.
	 * @return WP_Error The mapped envelope.
	 */
	private function refusalFor( License $license, LicenseValidationException $e ): WP_Error {
		try {
			$current = $this->licenses->get( (int) $license->id() ) ?? $license;
		} catch ( LicensePersistenceException $reloadFault ) {
			$current = $license;
		}

		if ( LicenseStatus::Expired === $current->status() ) {
			return $this->error(
				'tiermint_license_expired',
				__( 'This license has expired. Renew it to activate new sites.', 'tiermint' ),
				403,
				array( 'expires_at' => $this->formatUtc( $current->expiresAt() ) )
			);
		}

		if ( LicenseStatus::Disabled === $current->status() ) {
			return $this->error(
				'tiermint_license_disabled',
				__( 'This license has been disabled.', 'tiermint' ),
				403
			);
		}

		return $this->invalidParams( $e->getErrors() );
	}

	/**
	 * The license summary block for activate responses.
	 *
	 * @since 1.0.0
	 *
	 * @param License $license The freshly reloaded license.
	 * @return array<string, mixed> The summary block.
	 *
	 * @throws ProductPersistenceException  When the product read faults.
	 * @throws CustomerPersistenceException When the customer read faults.
	 */
	private function licenseSummary( License $license ): array {
		return array(
			'status'           => $license->status()->value,
			'product_slug'     => $this->productSlugFor( $license ),
			'expires_at'       => $this->formatUtc( $license->expiresAt() ),
			'activation_limit' => $license->activationLimit(),
			'activation_count' => $license->activationCount(),
			'customer_email'   => $this->maskedOwnerEmail( $license ),
		);
	}

	/**
	 * Resolve the slug of the license's own product, or null when the product
	 * no longer exists.
	 *
	 * @since 1.0.0
	 *
	 * @param License $license The license.
	 * @return string|null The product slug, or null.
	 */
	private function productSlugFor( License $license ): ?string {
		$product = $this->products->get( $license->productId() );

		return null !== $product ? $product->slug() : null;
	}

	/**
	 * Return the masked owner email for the public payload.
	 *
	 * @since 1.0.0
	 *
	 * @param License $license The license.
	 * @return string|null The masked email, or null when the owner is unknown.
	 */
	private function maskedOwnerEmail( License $license ): ?string {
		$customer = $this->customers->get( $license->customerId() );

		if ( null === $customer ) {
			return null;
		}

		$email = $customer->email();
		$at    = strpos( $email, '@' );

		if ( false === $at || 0 === $at ) {
			return null;
		}

		// Do not expose a one-character local part.
		$lead = $at > 1 ? substr( $email, 0, 1 ) : '';

		return $lead . '***' . substr( $email, $at );
	}

	/**
	 * Add a renewal URL when an extension provides one.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $payload    The payload built so far.
	 * @param string               $licenseKey The presented license key.
	 * @return array<string, mixed> The payload, with renewal_url when available.
	 */
	private function withRenewalUrl( array $payload, string $licenseKey ): array {
		/**
		 * Filters the renewal URL for licensing clients.
		 *
		 * @since 1.0.0
		 *
		 * @param string|null          $url        The renewal URL, or null for none.
		 * @param string               $licenseKey The presented license key.
		 * @param array<string, mixed> $payload    The canonical payload built so far.
		 */
		$url = apply_filters( 'tiermint_license_renewal_url', null, $licenseKey, $payload );

		if ( is_string( $url ) && '' !== $url ) {
			$payload['renewal_url'] = $url;
		}

		return $payload;
	}

	/**
	 * Sanitize the client environment report to a short flat map.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return array<string, string>|null The sanitized map, or null when absent.
	 */
	private function sanitizedEnvironment( WP_REST_Request $request ): ?array {
		$raw = $request->get_param( 'environment' );

		if ( ! is_array( $raw ) ) {
			return null;
		}

		$clean = array();

		foreach ( $raw as $key => $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$cleanKey = substr( sanitize_text_field( (string) $key ), 0, 50 );

			if ( '' === $cleanKey ) {
				continue;
			}

			$clean[ $cleanKey ] = substr( sanitize_text_field( (string) $value ), 0, 100 );

			if ( count( $clean ) >= 20 ) {
				break;
			}
		}

		return array() !== $clean ? $clean : null;
	}

	/**
	 * Format a datetime as the contract's ISO-8601 UTC shape.
	 *
	 * @since 1.0.0
	 *
	 * @param DateTimeImmutable|null $date The datetime, or null.
	 * @return string|null The `Y-m-d\TH:i:s\Z` string, or null.
	 */
	private function formatUtc( ?DateTimeImmutable $date ): ?string {
		if ( null === $date ) {
			return null;
		}

		return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
	}

	/**
	 * The `args` schema shared by the licensing routes.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>> The parameter schema.
	 */
	private function commonArgs(): array {
		return array(
			'license_key'  => array(
				'description'       => __( 'The license key issued at purchase.', 'tiermint' ),
				'type'              => 'string',
				'maxLength'         => 64,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'site_url'     => array(
				'description'       => __( 'The URL of the site the license is used on.', 'tiermint' ),
				'type'              => 'string',
				'maxLength'         => 200,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'product_slug' => array(
				'description'       => __( 'The slug of the product the key should belong to.', 'tiermint' ),
				'type'              => 'string',
				'maxLength'         => 200,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'fingerprint'  => array(
				'description'       => __( 'An opaque device fingerprint for non-web clients.', 'tiermint' ),
				'type'              => 'string',
				'maxLength'         => 64,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * The `args` schema for the activate-only environment report.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>> The parameter schema.
	 */
	private function environmentArg(): array {
		return array(
			'environment' => array(
				'description'       => __( 'A flat map describing the client environment (PHP, WordPress, product version).', 'tiermint' ),
				'type'              => 'object',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}
}
