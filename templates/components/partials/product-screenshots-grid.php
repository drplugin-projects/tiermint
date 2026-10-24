<?php
/**
 * Product screenshot grid shared by product templates.
 *
 * @package DrPlugin\WP\TierMint
 *
 * @var array<int, array{full: string, thumb: string, alt: string, caption: string}> $tiermint_screenshots
 *     The resolved screenshots supplied by the including template.
 */

defined( 'ABSPATH' ) || exit;

$tiermint_screenshots = ( isset( $tiermint_screenshots ) && is_array( $tiermint_screenshots ) ) ? $tiermint_screenshots : array();

if ( array() === $tiermint_screenshots ) {
	return;
}

// The gallery script replaces these counter tokens.
$tiermint_counter_label =
	/* translators: 1: the current screenshot number, 2: the total number of screenshots. The %1$s and %2$s tokens are substituted by the gallery script. */
	__( '%1$s of %2$s', 'tiermint' );
?>
<div
	class="tiermint-component tiermint-product-screenshots"
	data-tiermint-lightbox-close="<?php echo esc_attr__( 'Close', 'tiermint' ); ?>"
	data-tiermint-gallery-label="<?php echo esc_attr__( 'Product screenshots', 'tiermint' ); ?>"
	data-tiermint-gallery-prev="<?php echo esc_attr__( 'Previous screenshot', 'tiermint' ); ?>"
	data-tiermint-gallery-next="<?php echo esc_attr__( 'Next screenshot', 'tiermint' ); ?>"
	data-tiermint-gallery-counter="<?php echo esc_attr( $tiermint_counter_label ); ?>"
>
	<ul class="tiermint-product-screenshots__grid">
		<?php foreach ( $tiermint_screenshots as $tiermint_shot ) : ?>
			<?php $tiermint_caption = isset( $tiermint_shot['caption'] ) ? (string) $tiermint_shot['caption'] : ''; ?>
			<li class="tiermint-product-screenshots__item">
				<a
					class="tiermint-product-screenshots__link"
					href="<?php echo esc_url( (string) $tiermint_shot['full'] ); ?>"
					data-tiermint-lightbox
					<?php if ( '' !== $tiermint_caption ) : ?>
						data-tiermint-caption="<?php echo esc_attr( $tiermint_caption ); ?>"
					<?php endif; ?>
				>
					<?php echo wp_kses( (string) $tiermint_shot['thumb'], \DrPlugin\WP\TierMint\Frontend\Kses::image() ); ?>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
</div>
