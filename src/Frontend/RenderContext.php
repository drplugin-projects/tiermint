<?php
/**
 * Immutable per-request front-end state.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend;

use DrPlugin\WP\TierMint\Customers\Customer;
use DrPlugin\WP\TierMint\Products\Product;

defined( 'ABSPATH' ) || exit;

/**
 * Stores front-end state for one request.
 *
 * License and preview products load only when needed.
 *
 * @since 1.0.0
 */
final class RenderContext {

	/**
	 * Current customer, or null.
	 *
	 * @var Customer|null
	 */
	private ?Customer $customer;

	/**
	 * Deferred license query; invoked at most once by licenses().
	 *
	 * @var callable(): array<int, \DrPlugin\WP\TierMint\Licensing\License>
	 */
	private $licenseLoader;

	/**
	 * Memoized licenses() result; null until the loader has run.
	 *
	 * @var array<int, \DrPlugin\WP\TierMint\Licensing\License>|null
	 */
	private ?array $licenses = null;

	/**
	 * Whether this is an editor preview.
	 *
	 * @var bool
	 */
	private bool $isEditorPreview;

	/**
	 * Whether a child preview omits its own caption.
	 *
	 * @var bool
	 */
	private bool $suppressSampleCaption;

	/**
	 * Routed product, or null.
	 *
	 * @var Product|null
	 */
	private ?Product $product;

	/**
	 * Deferred product query for editor previews.
	 *
	 * @var (callable(): ?Product)|null
	 */
	private $productLoader;

	/**
	 * Whether the deferred product loader has already run.
	 *
	 * @var bool
	 */
	private bool $productResolved = false;

	/**
	 * Build a render context.
	 *
	 * @param Customer|null $customer        Current customer, if any.
	 * @param callable      $licenseLoader   Deferred license query returning License[].
	 * @param bool          $isEditorPreview       True inside editor previews.
	 * @param Product|null  $product               Routed product on a details page, or null.
	 * @param callable|null $productLoader         Deferred fallback product query (editor sample), or null.
	 * @param bool          $suppressSampleCaption True for a panel composed inside a sampling parent, so it omits its own editor caption.
	 */
	public function __construct(
		?Customer $customer,
		callable $licenseLoader,
		bool $isEditorPreview = false,
		?Product $product = null,
		?callable $productLoader = null,
		bool $suppressSampleCaption = false
	) {
		$this->customer              = $customer;
		$this->licenseLoader         = $licenseLoader;
		$this->isEditorPreview       = $isEditorPreview;
		$this->product               = $product;
		$this->productLoader         = $productLoader;
		$this->suppressSampleCaption = $suppressSampleCaption;
	}

	/**
	 * The current customer, or null when anonymous.
	 *
	 * @return Customer|null
	 */
	public function customer(): ?Customer {
		return $this->customer;
	}

	/**
	 * Get customer licenses.
	 *
	 * @return array<int, \DrPlugin\WP\TierMint\Licensing\License>
	 */
	public function licenses(): array {
		if ( null === $this->customer ) {
			return array();
		}

		if ( null === $this->licenses ) {
			$this->licenses = ( $this->licenseLoader )();
		}

		return $this->licenses;
	}

	/**
	 * Whether components should render placeholder-safe editor markup.
	 *
	 * @return bool
	 */
	public function isEditorPreview(): bool {
		return $this->isEditorPreview;
	}

	/**
	 * Whether a child preview omits its own caption.
	 *
	 * @return bool
	 */
	public function suppressSampleCaption(): bool {
		return $this->suppressSampleCaption;
	}

	/**
	 * Get the routed or preview product.
	 *
	 * @since 1.0.0
	 *
	 * @return Product|null
	 */
	public function product(): ?Product {
		if ( null === $this->product && ! $this->productResolved && null !== $this->productLoader ) {
			$this->productResolved = true;
			$this->product         = ( $this->productLoader )();
		}

		return $this->product;
	}
}
