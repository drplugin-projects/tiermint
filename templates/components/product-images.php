<?php
/**
 * Product images component template.
 *
 * @package DrPlugin\WP\TierMint
 *
 * @var array{image: string|null} $data View data supplied by the component.
 */

defined( 'ABSPATH' ) || exit;

$tiermint_image = ( isset( $data['image'] ) && is_string( $data['image'] ) ) ? $data['image'] : '';

if ( '' === $tiermint_image ) {
	return;
}
?>
<figure class="tiermint-component tiermint-product-images">
	<?php
	// wp_get_attachment_image() returns escaped markup.
	echo $tiermint_image; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image() returns escaped markup.
	?>
</figure>
