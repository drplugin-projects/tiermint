<?php
/**
 * The front-end component contract.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * A front-end rendering unit shared by all adapters.
 *
 * @since 1.0.0
 */
interface Component {

	/**
	 * Get the unique kebab-case component identifier.
	 *
	 * @return string
	 */
	public function slug(): string;

	/**
	 * Get machine-readable attribute definitions.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function schema(): array;

	/**
	 * Render escaped HTML with resolved attributes.
	 *
	 * @param array<string, mixed> $attrs Resolved attribute values.
	 * @param RenderContext        $ctx   Per-request customer/license state.
	 * @return string Escaped HTML.
	 */
	public function render( array $attrs, RenderContext $ctx ): string;

	/**
	 * Registered style/script handles to enqueue when this component renders.
	 *
	 * @return array<string, string[]> Map with 'styles' and 'scripts' lists.
	 */
	public function assets(): array;
}
