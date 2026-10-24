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
	<?php echo wp_kses( $tiermint_image, \DrPlugin\WP\TierMint\Frontend\Kses::image() ); ?>
</figure>
