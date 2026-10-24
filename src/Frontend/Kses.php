<?php
/**
 * Allowed HTML for component output.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the HTML that component output may hold, for wp_kses() where it is printed.
 *
 * It extends WordPress's post allowlist, so content printed through wp_kses_post() keeps its markup.
 *
 * @since 1.0.0
 */
final class Kses {

	/**
	 * Filter that extends the allowlist.
	 *
	 * @var string
	 */
	public const FILTER = 'tiermint_kses_allowed_html';

	/**
	 * Attributes allowed on every element: WordPress's global set and three more that components print.
	 *
	 * @var array<string, bool>
	 */
	private const GLOBAL_ATTRIBUTES = array(
		'aria-controls'    => true,
		'aria-current'     => true,
		'aria-describedby' => true,
		'aria-details'     => true,
		'aria-expanded'    => true,
		'aria-hidden'      => true,
		'aria-label'       => true,
		'aria-labelledby'  => true,
		'aria-live'        => true,
		'aria-required'    => true,
		'aria-selected'    => true,
		'class'            => true,
		'data-*'           => true,
		'dir'              => true,
		'hidden'           => true,
		'id'               => true,
		'lang'             => true,
		'role'             => true,
		'style'            => true,
		'tabindex'         => true,
		'title'            => true,
		'xml:lang'         => true,
	);

	/**
	 * Elements and attributes components print that the post allowlist lacks.
	 *
	 * @var array<string, array<string, bool>>
	 */
	private const COMPONENT_TAGS = array(
		'a'        => array(
			'download' => true,
		),
		'circle'   => array(
			'cx'   => true,
			'cy'   => true,
			'fill' => true,
			'r'    => true,
		),
		'form'     => array(
			'action'       => true,
			'autocomplete' => true,
			'method'       => true,
			'name'         => true,
		),
		'img'      => array(
			'decoding'      => true,
			'fetchpriority' => true,
			'loading'       => true,
			'sizes'         => true,
			'srcset'        => true,
		),
		'input'    => array(
			'autocapitalize' => true,
			'autocomplete'   => true,
			'checked'        => true,
			'max'            => true,
			'maxlength'      => true,
			'min'            => true,
			'minlength'      => true,
			'name'           => true,
			'placeholder'    => true,
			'readonly'       => true,
			'required'       => true,
			'size'           => true,
			'spellcheck'     => true,
			'type'           => true,
			'value'          => true,
		),
		'noscript' => array(),
		'option'   => array(
			'disabled' => true,
			'selected' => true,
			'value'    => true,
		),
		'path'     => array(
			'd'    => true,
			'fill' => true,
		),
		'rect'     => array(
			'fill'   => true,
			'height' => true,
			'width'  => true,
		),
		'select'   => array(
			'autocomplete' => true,
			'name'         => true,
			'required'     => true,
		),
		'svg'      => array(
			'height'              => true,
			'preserveaspectratio' => true,
			'viewbox'             => true,
			'width'               => true,
			'xmlns'               => true,
		),
	);

	/**
	 * Elements kept in the image subset: an image, or the editor sample tile's SVG.
	 *
	 * @var array<int, string>
	 */
	private const IMAGE_ELEMENTS = array( 'img', 'svg', 'rect', 'circle', 'path' );

	/**
	 * Elements kept in the form subset: what wp_login_form() prints, plus plain markup and images.
	 *
	 * @var array<int, string>
	 */
	private const FORM_ELEMENTS = array( 'a', 'br', 'button', 'div', 'form', 'img', 'input', 'label', 'p', 'span' );

	/**
	 * The post allowlist the merge was built from.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $postTags = null;

	/**
	 * The post allowlist merged with the component tags, before the filter.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $merged = array();

	/**
	 * Get the HTML that component output may hold.
	 *
	 * @return array<string, array<string, mixed>> Elements and their attributes, as wp_kses() takes them.
	 */
	public static function allowed(): array {
		return self::filtered( self::merged(), 'component' );
	}

	/**
	 * Get the subset for image markup: WordPress attachment and avatar images, or the sample tile.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function image(): array {
		return self::filtered( array_intersect_key( self::merged(), array_flip( self::IMAGE_ELEMENTS ) ), 'image' );
	}

	/**
	 * Get the subset for wp_login_form() markup.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function form(): array {
		return self::filtered( array_intersect_key( self::merged(), array_flip( self::FORM_ELEMENTS ) ), 'form' );
	}

	/**
	 * Get the post allowlist merged with the component tags.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function merged(): array {
		$post = wp_kses_allowed_html( 'post' );
		$post = is_array( $post ) ? $post : array();

		// The merge is costly, so redo it only when the post allowlist changes.
		if ( $post !== self::$postTags ) {
			self::$postTags = $post;
			self::$merged   = self::merge( $post );
		}

		return self::$merged;
	}

	/**
	 * Run one list through the extension filter.
	 *
	 * @param array<string, array<string, mixed>> $tags    The list before the filter.
	 * @param string                              $context Which list: component, image or form.
	 * @return array<string, array<string, mixed>>
	 */
	private static function filtered( array $tags, string $context ): array {
		/**
		 * Filters the HTML that component output may hold.
		 *
		 * The component list applies to whole component output. Inside templates, WordPress image
		 * and avatar markup also passes the image list, and wp_login_form() markup the form list.
		 * Markup that passes those lists must be on the component list too. A callback that
		 * ignores the context adds to all three.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, array<string, mixed>> $tags    Elements and their attributes, as wp_kses() takes them.
		 * @param string                              $context Which list: 'component', 'image' or 'form'.
		 */
		$filtered = apply_filters( 'tiermint_kses_allowed_html', $tags, $context );

		return is_array( $filtered ) ? $filtered : $tags;
	}

	/**
	 * Add the component tags and the global attributes to the post allowlist.
	 *
	 * @param array<string, mixed> $post The post allowlist.
	 * @return array<string, array<string, mixed>>
	 */
	private static function merge( array $post ): array {
		$tags = $post;

		foreach ( self::COMPONENT_TAGS as $element => $attrs ) {
			$own              = isset( $tags[ $element ] ) && is_array( $tags[ $element ] ) ? $tags[ $element ] : array();
			$tags[ $element ] = array_merge( $own, $attrs );
		}

		foreach ( $tags as $element => $attrs ) {
			$tags[ $element ] = array_merge( is_array( $attrs ) ? $attrs : array(), self::GLOBAL_ATTRIBUTES );
		}

		return $tags;
	}
}
