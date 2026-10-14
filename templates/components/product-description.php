<?php
/**
 * Product description template.
 *
 * Theme override: {theme}/tiermint/components/product-description.php.
 *
 * @package DrPlugin\WP\TierMint
 *
 * @var array{description: string|null} $data View data.
 */

defined( 'ABSPATH' ) || exit;

$tiermint_description = ( isset( $data['description'] ) && null !== $data['description'] ) ? (string) $data['description'] : '';

if ( '' === $tiermint_description ) {
	return;
}
?>
<div class="tiermint-component tiermint-product-description">
	<?php echo wp_kses_post( $tiermint_description ); ?>
</div>
