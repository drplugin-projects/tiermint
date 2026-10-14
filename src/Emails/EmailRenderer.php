<?php
/**
 * Renders email templates to HTML with a shared header and footer.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Emails;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Renders email templates with a shared header and footer.
 * Themes can override template files.
 *
 * @since 1.0.0
 */
class EmailRenderer {

	/**
	 * The template key for the shared document header (opens the HTML wrapper).
	 *
	 * @var string
	 */
	private const HEADER_KEY = 'header';

	/**
	 * The template key for the shared document footer (closes the HTML wrapper).
	 *
	 * @var string
	 */
	private const FOOTER_KEY = 'footer';

	/**
	 * Plugin email template directory.
	 *
	 * @var string
	 */
	private string $templatesDir;

	/**
	 * Wire the renderer with the base templates directory.
	 *
	 * @since 1.0.0
	 *
	 * @param string $templatesDir Absolute path (trailing slash) of templates/emails/.
	 */
	public function __construct( string $templatesDir ) {
		$this->templatesDir = rtrim( $templatesDir, '/\\' ) . '/';
	}

	/**
	 * Render an email body with the shared header and footer.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $key  The body template key (no directory/extension).
	 * @param array<string, mixed> $data The single view-data array passed to every part.
	 * @return string The full HTML document, or '' when the body template is missing.
	 */
	public function render( string $key, array $data ): string {
		$body = $this->renderPart( $data, $key );

		if ( '' === $body ) {
			return '';
		}

		return $this->renderPart( $data, self::HEADER_KEY ) . $body . $this->renderPart( $data, self::FOOTER_KEY );
	}

	/**
	 * Render a theme override or the plugin template.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $data The view-data array in scope as $data.
	 * @param string               $key  The template key.
	 * @return string The rendered fragment, or '' when the file does not exist.
	 */
	private function renderPart( array $data, string $key ): string {
		$template = locate_template( 'tiermint/emails/' . $key . '.php' );

		if ( ! is_string( $template ) || '' === $template ) {
			$template = $this->locatePluginTemplate( $key );
		}

		if ( '' === $template || ! file_exists( $template ) ) {
			return '';
		}

		ob_start();
		include $template;

		return (string) ob_get_clean();
	}

	/**
	 * Find an email template in the registered plugin directories.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key The template key.
	 * @return string The first matching absolute file path, or '' when none exists.
	 */
	private function locatePluginTemplate( string $key ): string {
		/**
		 * Filters plugin template directories.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, string> $dirs Absolute template directories, base first.
		 */
		$dirs = apply_filters( 'tiermint_email_template_paths', array( $this->templatesDir ) );

		foreach ( (array) $dirs as $dir ) {
			$candidate = rtrim( (string) $dir, '/\\' ) . '/' . $key . '.php';

			if ( file_exists( $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}
}
