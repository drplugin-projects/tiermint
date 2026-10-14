<?php
/**
 * Populates Elementor product/term pickers inside the editor only.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend\Adapters\Elementor;

use DrPlugin\WP\TierMint\Products\ProductService;
use DrPlugin\WP\TierMint\Products\ProductTaxonomy;
use DrPlugin\WP\TierMint\Products\TermService;

defined( 'ABSPATH' ) || exit;

/**
 * Provides bounded picker options in the Elementor editor.
 *
 * Front-end rendering gets no options, so it runs no catalog or page query.
 *
 * @since 1.0.0
 */
class ControlOptions {

	/**
	 * Maximum options returned for one picker.
	 *
	 * @var int
	 */
	private const MAX = 100;

	/**
	 * The catalog service.
	 *
	 * @var ProductService
	 */
	private ProductService $products;

	/**
	 * The taxonomy service.
	 *
	 * @var TermService
	 */
	private TermService $terms;

	/**
	 * Wire the provider's collaborators.
	 *
	 * @param ProductService $products The catalog service.
	 * @param TermService    $terms    The taxonomy service.
	 */
	public function __construct( ProductService $products, TermService $terms ) {
		$this->products = $products;
		$this->terms    = $terms;
	}

	/**
	 * Resolve a picker's options for one kind, or an empty list off-editor.
	 *
	 * @param string               $kind       Picker kind (`product`, `term`, or `page`).
	 * @param array<string, mixed> $definition The attribute definition (carries `taxonomy`/`zero_label`).
	 * @return array<string, string> Option value to label.
	 */
	public function __invoke( string $kind, array $definition ): array {
		if ( ! $this->inEditorContext() ) {
			return array();
		}

		switch ( $kind ) {
			case 'product':
				return $this->productOptions();
			case 'term':
				return $this->termOptions( (string) ( $definition['taxonomy'] ?? '' ) );
			case 'page':
				return $this->pageOptions( $definition );
			default:
				return array();
		}
	}

	/**
	 * Published products as id => name, bounded to {@see self::MAX}.
	 *
	 * @return array<string, string>
	 */
	private function productOptions(): array {
		$options = array();

		foreach ( $this->products->list(
			array(
				'status'   => 'published',
				'per_page' => self::MAX,
			)
		) as $product ) {
			$id = $product->id();

			if ( null !== $id ) {
				$options[ (string) $id ] = $product->name();
			}
		}

		return $options;
	}

	/**
	 * Get known taxonomy terms as slug-to-name options.
	 *
	 * @param string $taxonomy The taxonomy slug hint.
	 * @return array<string, string>
	 */
	private function termOptions( string $taxonomy ): array {
		$resolved = ProductTaxonomy::tryFrom( $taxonomy );

		if ( null === $resolved ) {
			return array();
		}

		$options = array();

		foreach ( $this->terms->listByTaxonomy( $resolved ) as $term ) {
			$options[ $term->slug() ] = $term->name();
		}

		return $options;
	}

	/**
	 * Get published pages with the site-wide setting first.
	 *
	 * @param array<string, mixed> $definition The attribute definition (carries `zero_label`).
	 * @return array<string, string>
	 */
	private function pageOptions( array $definition ): array {
		$options = array( '0' => $this->pageZeroLabel( $definition ) );

		$pages = get_posts(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'orderby'     => 'title',
				'order'       => 'ASC',
				'numberposts' => self::MAX,
			)
		);

		foreach ( $pages as $page ) {
			$options[ (string) $page->ID ] = $page->post_title;
		}

		return $options;
	}

	/**
	 * Get the site-wide setting label for a page picker.
	 *
	 * @param array<string, mixed> $definition The attribute definition.
	 * @return string
	 */
	private function pageZeroLabel( array $definition ): string {
		$label = (string) ( $definition['zero_label'] ?? '' );

		return '' !== $label ? $label : __( 'Default', 'tiermint' );
	}

	/**
	 * Check whether picker options may load for this request.
	 *
	 * @return bool
	 */
	protected function inEditorContext(): bool {
		return $this->isElementorEditMode() || $this->isAuthorizedEditorAjax();
	}

	/**
	 * Whether Elementor reports an active edit-mode render.
	 *
	 * @return bool
	 */
	private function isElementorEditMode(): bool {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}

		$plugin = \Elementor\Plugin::$instance;

		if ( null === $plugin ) {
			return false;
		}

		return isset( $plugin->editor ) && $plugin->editor->is_edit_mode();
	}

	/**
	 * Check for authorised Elementor widget configuration AJAX.
	 *
	 * @return bool
	 */
	private function isAuthorizedEditorAjax(): bool {
		if ( ! wp_doing_ajax() ) {
			return false;
		}

		// Elementor owns the nonce. The capability check limits this read-only request.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only gate on the running action; capability-checked below, nonce owned by Elementor.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

		return 'elementor_ajax' === $action && current_user_can( 'edit_posts' );
	}
}
