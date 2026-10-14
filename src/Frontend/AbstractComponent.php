<?php
/**
 * Base class for front-end components.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Renders component templates and declares their default asset handles.
 *
 * A missing template renders empty so a theme override cannot break a storefront page.
 *
 * @since 1.0.0
 */
abstract class AbstractComponent implements Component {

	/**
	 * Default component asset handles.
	 *
	 * @return array<string, string[]>
	 */
	public function assets(): array {
		return array(
			'styles'  => array( 'tiermint-' . $this->slug() ),
			'scripts' => array( 'tiermint-' . $this->slug() ),
		);
	}

	/**
	 * Render the theme override or plugin template with its view data.
	 *
	 * @param array<string, mixed> $data View data for the template.
	 * @return string The rendered HTML, or '' when no template exists.
	 */
	protected function renderTemplate( array $data ): string {
		$template = locate_template( 'tiermint/components/' . $this->slug() . '.php' );

		if ( ! is_string( $template ) || '' === $template ) {
			$template = $this->defaultTemplatePath();
		}

		if ( ! file_exists( $template ) ) {
			return '';
		}

		ob_start();
		include $template;

		return (string) ob_get_clean();
	}

	/**
	 * Render editor sample data and its caption.
	 *
	 * A parent sample context suppresses nested captions.
	 *
	 * @param array<string, mixed> $data Sample view data for the template.
	 * @param RenderContext|null   $ctx  The render context; when it suppresses the sample caption, the caption is omitted.
	 * @return string The rendered sample HTML, with the caption appended unless the context suppresses it.
	 */
	protected function renderSample( array $data, ?RenderContext $ctx = null ): string {
		$caption = ( null !== $ctx && $ctx->suppressSampleCaption() ) ? '' : $this->sampleCaption();

		return $this->renderTemplate( $data ) . $caption;
	}

	/**
	 * Render the editor sample caption.
	 *
	 * @return string The rendered caption partial.
	 */
	protected function sampleCaption(): string {
		$partial = $this->sampleCaptionPath();

		if ( ! file_exists( $partial ) ) {
			return '';
		}

		ob_start();
		include $partial;

		return (string) ob_get_clean();
	}

	/**
	 * Get the sample-caption partial path.
	 *
	 * @return string
	 */
	protected function sampleCaptionPath(): string {
		return TIERMINT_PATH . 'templates/components/partials/editor-sample-caption.php';
	}

	/**
	 * Get the default component template path.
	 *
	 * @return string
	 */
	protected function defaultTemplatePath(): string {
		return TIERMINT_PATH . 'templates/components/' . $this->slug() . '.php';
	}
}
