<?php
/**
 * Prints the document title and social meta tags for a routed product.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend;

use DrPlugin\WP\TierMint\Core\Hookable;
use DrPlugin\WP\TierMint\Products\Product;

defined( 'ABSPATH' ) || exit;

/**
 * Sets the title, canonical link and Open Graph tags for routed products.
 *
 * `tiermint_product_meta_tags` can replace the tag values.
 *
 * @since 1.0.0
 */
class ProductHeadTags implements Hookable {

	/**
	 * The shared request context supplying the routed product.
	 *
	 * @var ProductRequestContext
	 */
	private ProductRequestContext $request;

	/**
	 * The canonical URL builder.
	 *
	 * @var ProductUrlProvider
	 */
	private ProductUrlProvider $urls;

	/**
	 * The memoized tag set for the routed product.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $tags = null;

	/**
	 * Wire the request context and URL builder.
	 *
	 * @param ProductRequestContext $request The routed-product source.
	 * @param ProductUrlProvider    $urls    The canonical URL builder.
	 */
	public function __construct( ProductRequestContext $request, ProductUrlProvider $urls ) {
		$this->request = $request;
		$this->urls    = $urls;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_filter( 'pre_get_document_title', array( $this, 'filterTitle' ) );
		add_action( 'template_redirect', array( $this, 'suppressCoreCanonical' ), 20 );
		add_action( 'wp_head', array( $this, 'printMetaTags' ), 1 );
	}

	/**
	 * Replace the routed product document title.
	 *
	 * @param string $title The title WordPress would otherwise use.
	 * @return string
	 */
	public function filterTitle( $title ): string {
		$product = $this->request->product();

		if ( null === $product ) {
			return (string) $title;
		}

		$tags = $this->tags( $product );
		$name = $tags['title'];

		return '' !== $tags['site_name'] ? $name . ' - ' . $tags['site_name'] : $name;
	}

	/**
	 * Remove the core canonical link for a routed product.
	 *
	 * @return void
	 */
	public function suppressCoreCanonical(): void {
		if ( null === $this->request->product() ) {
			return;
		}

		remove_action( 'wp_head', 'rel_canonical' );
	}

	/**
	 * Print the canonical link and the Open Graph tag set for the routed product.
	 *
	 * @return void
	 */
	public function printMetaTags(): void {
		$product = $this->request->product();

		if ( null === $product ) {
			return;
		}

		$tags = $this->tags( $product );

		if ( '' !== $tags['url'] ) {
			echo '<link rel="canonical" href="' . esc_url( $tags['url'] ) . '" />' . "\n";
			echo '<meta property="og:url" content="' . esc_url( $tags['url'] ) . '" />' . "\n";
		}

		echo '<meta property="og:title" content="' . esc_attr( $tags['title'] ) . '" />' . "\n";

		if ( '' !== $tags['description'] ) {
			echo '<meta property="og:description" content="' . esc_attr( $tags['description'] ) . '" />' . "\n";
		}

		if ( '' !== $tags['image'] ) {
			echo '<meta property="og:image" content="' . esc_url( $tags['image'] ) . '" />' . "\n";

			if ( '' !== $tags['image_width'] ) {
				echo '<meta property="og:image:width" content="' . esc_attr( $tags['image_width'] ) . '" />' . "\n";
			}

			if ( '' !== $tags['image_height'] ) {
				echo '<meta property="og:image:height" content="' . esc_attr( $tags['image_height'] ) . '" />' . "\n";
			}

			if ( '' !== $tags['image_type'] ) {
				echo '<meta property="og:image:type" content="' . esc_attr( $tags['image_type'] ) . '" />' . "\n";
			}
		}

		echo '<meta property="og:type" content="' . esc_attr( $tags['type'] ) . '" />' . "\n";

		if ( '' !== $tags['site_name'] ) {
			echo '<meta property="og:site_name" content="' . esc_attr( $tags['site_name'] ) . '" />' . "\n";
		}
	}

	/**
	 * Build and filter the product meta tags.
	 *
	 * @param Product $product The routed product.
	 * @return array<string, string> The title, description, image, image width/height/type, url, type, and site name.
	 */
	private function tags( Product $product ): array {
		if ( null !== $this->tags ) {
			return $this->tags;
		}

		$url   = $this->urls->url( $product );
		$image = $this->imageData( $product );

		$tags = array(
			'title'        => $product->name(),
			'description'  => $this->description( $product ),
			'image'        => $image['image'],
			'image_width'  => $image['image_width'],
			'image_height' => $image['image_height'],
			'image_type'   => $image['image_type'],
			'url'          => null === $url ? '' : $url,
			'type'         => 'product',
			'site_name'    => (string) get_bloginfo( 'name' ),
		);

		/**
		 * Filters meta tags before rendering.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string> $tags    The title, description, image, image
		 *                                       width/height/type, url, type, and site
		 *                                       name. A filter overriding `image` should
		 *                                       also set the matching dimensions.
		 * @param Product               $product The routed product.
		 * @param string|null           $url     The product canonical URL, or null.
		 */
		$filtered = apply_filters( 'tiermint_product_meta_tags', $tags, $product, $url );

		// Keep defaults for omitted keys and normalize output values to strings.
		$merged = is_array( $filtered ) ? wp_parse_args( $filtered, $tags ) : $tags;

		$this->tags = array_map( 'strval', $merged );

		return $this->tags;
	}

	/**
	 * Get the plain-text overview or description.
	 *
	 * @param Product $product The routed product.
	 * @return string
	 */
	private function description( Product $product ): string {
		$source = $product->overview();

		if ( null === $source || '' === trim( $source ) ) {
			$source = $product->description();
		}

		if ( null === $source ) {
			return '';
		}

		return (string) wp_strip_all_tags( $source );
	}

	/**
	 * Get the social image data, with the main image as fallback.
	 *
	 * @param Product $product The routed product.
	 * @return array{image: string, image_width: string, image_height: string, image_type: string}
	 */
	private function imageData( Product $product ): array {
		$empty = array(
			'image'        => '',
			'image_width'  => '',
			'image_height' => '',
			'image_type'   => '',
		);

		$settings = $product->settings();
		$id       = (int) ( $settings['og_image'] ?? 0 );

		if ( $id <= 0 ) {
			$id = (int) ( $settings['main_image'] ?? 0 );
		}

		if ( $id <= 0 ) {
			return $empty;
		}

		$src = wp_get_attachment_image_src( $id, 'large' );

		if ( ! is_array( $src ) || ! isset( $src[0] ) || '' === (string) $src[0] ) {
			return $empty;
		}

		$mime = get_post_mime_type( $id );

		return array(
			'image'        => (string) $src[0],
			'image_width'  => isset( $src[1] ) ? (string) (int) $src[1] : '',
			'image_height' => isset( $src[2] ) ? (string) (int) $src[2] : '',
			'image_type'   => is_string( $mime ) ? $mime : '',
		);
	}
}
