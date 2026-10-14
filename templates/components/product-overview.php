<?php
/**
 * Product overview template.
 *
 * This theme-overridable template prevents duplicate product H1 headings.
 *
 * @package DrPlugin\WP\TierMint
 *
 * @var array{name: string, show_name: bool, overview: string|null} $data View data.
 */

defined( 'ABSPATH' ) || exit;

?>
<div class="tiermint-component tiermint-product-overview">
	<?php if ( ! empty( $data['show_name'] ) ) : ?>
		<h1 class="tiermint-product-overview__name"><?php echo esc_html( (string) $data['name'] ); ?></h1>
	<?php endif; ?>

	<?php if ( isset( $data['overview'] ) && null !== $data['overview'] ) : ?>
		<div class="tiermint-product-overview__text"><?php echo wp_kses_post( (string) $data['overview'] ); ?></div>
	<?php endif; ?>
</div>
