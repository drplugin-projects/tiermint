<?php
/**
 * Builds the per-request RenderContext.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend;

use DrPlugin\WP\TierMint\Customers\CustomerService;
use DrPlugin\WP\TierMint\Licensing\LicenseService;
use DrPlugin\WP\TierMint\Products\Product;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves shared front-end state for one request.
 *
 * @since 1.0.0
 */
class RenderContextResolver {

	/**
	 * Customer reads (user-id lookup).
	 *
	 * @var CustomerService
	 */
	private CustomerService $customers;

	/**
	 * License reads (per-customer listing for the lazy loader).
	 *
	 * @var LicenseService
	 */
	private LicenseService $licenses;

	/**
	 * Product request context, or null.
	 *
	 * @var ProductRequestContext|null
	 */
	private ?ProductRequestContext $request;

	/**
	 * Memoized context for this request; null until first resolve().
	 *
	 * @var RenderContext|null
	 */
	private ?RenderContext $context = null;

	/**
	 * Wire collaborator services.
	 *
	 * @param CustomerService            $customers Customer reads.
	 * @param LicenseService             $licenses  License reads.
	 * @param ProductRequestContext|null $request   Routed-product source, or null.
	 */
	public function __construct( CustomerService $customers, LicenseService $licenses, ?ProductRequestContext $request = null ) {
		$this->customers = $customers;
		$this->licenses  = $licenses;
		$this->request   = $request;
	}

	/**
	 * Resolve (and memoize) the context for the current request.
	 *
	 * @return RenderContext
	 */
	public function resolve(): RenderContext {
		if ( null !== $this->context ) {
			return $this->context;
		}

		$userId   = get_current_user_id();
		$customer = $userId > 0 ? $this->customers->getByUserId( $userId ) : null;

		$licenseLoader = function () use ( $customer ): array {
			// Guard direct calls with no customer.
			if ( null === $customer || null === $customer->id() ) {
				return array();
			}
			return $this->licenses->listByCustomer( $customer->id() );
		};

		$editorPreview = $this->isEditorPreview() || $this->isElementorPreview();

		// Preview products load only when a component needs one.
		$routedProduct = ( ! $editorPreview && null !== $this->request ) ? $this->request->product() : null;

		$productLoader = null;
		if ( $editorPreview && null !== $this->request ) {
			$request       = $this->request;
			$productLoader = static fn (): ?Product => $request->previewProduct();
		}

		$this->context = new RenderContext(
			$customer,
			$licenseLoader,
			$editorPreview,
			$routedProduct,
			$productLoader
		);

		return $this->context;
	}

	/**
	 * Clear the context cache.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->context = null;
	}

	/**
	 * Whether the request is an editor preview.
	 *
	 * @return bool
	 */
	private function isEditorPreview(): bool {
		return defined( 'REST_REQUEST' ) && REST_REQUEST;
	}

	/**
	 * Whether this render is in Elementor preview mode.
	 *
	 * @return bool
	 */
	protected function isElementorPreview(): bool {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}

		$plugin = \Elementor\Plugin::$instance;

		if ( null === $plugin ) {
			return false;
		}

		if ( isset( $plugin->editor ) && $plugin->editor->is_edit_mode() ) {
			return true;
		}

		return isset( $plugin->preview ) && $plugin->preview->is_preview_mode();
	}
}
