<?php
/**
 * Product details template.
 *
 * Theme override: {theme}/tiermint/components/product-details.php.
 *
 * @package DrPlugin\WP\TierMint
 *
 * @var array{
 *     name: string,
 *     show_name: bool,
 *     layout: string,
 *     main_image: array{url: string, alt: string}|null,
 *     overview: string|null,
 *     description: string|null,
 *     screenshots: array<int, array{full: string, thumb: string, alt: string, caption: string}>,
 *     purchase: array<string, mixed>
 * } $data View data.
 */

defined( 'ABSPATH' ) || exit;

$tiermint_main_image  = ( isset( $data['main_image'] ) && is_array( $data['main_image'] ) ) ? $data['main_image'] : null;
$tiermint_screenshots = ( isset( $data['screenshots'] ) && is_array( $data['screenshots'] ) ) ? $data['screenshots'] : array();
$tiermint_form        = ( isset( $data['purchase'] ) && is_array( $data['purchase'] ) ) ? $data['purchase'] : array();
$tiermint_layout      = ( isset( $data['layout'] ) && 'single' === $data['layout'] ) ? 'single' : 'two-column';
$tiermint_two_column  = ( 'two-column' === $tiermint_layout );

// Omit empty media columns.
$tiermint_has_media = ( null !== $tiermint_main_image ) || ( $tiermint_two_column && array() !== $tiermint_screenshots );
?>
<div class="tiermint-component tiermint-product-details tiermint-product-details--<?php echo esc_attr( $tiermint_layout ); ?>">
	<div class="tiermint-product-details__inner">
		<div class="tiermint-product-details__columns">
			<?php if ( $tiermint_has_media ) : ?>
				<div class="tiermint-product-details__media">
					<?php if ( null !== $tiermint_main_image ) : ?>
						<figure class="tiermint-product-details__main-image">
							<img src="<?php echo esc_url( (string) $tiermint_main_image['url'] ); ?>" alt="<?php echo esc_attr( (string) $tiermint_main_image['alt'] ); ?>" />
						</figure>
					<?php endif; ?>

					<?php
					// Render the shared screenshot gallery.
					if ( $tiermint_two_column && array() !== $tiermint_screenshots ) {
						require TIERMINT_PATH . 'templates/components/partials/product-screenshots-grid.php';
					}
					?>
				</div>
			<?php endif; ?>

			<div class="tiermint-product-details__buy">
				<?php if ( ! empty( $data['show_name'] ) ) : ?>
					<h1 class="tiermint-product-details__name"><?php echo esc_html( (string) $data['name'] ); ?></h1>
				<?php endif; ?>

				<?php if ( isset( $data['overview'] ) && null !== $data['overview'] ) : ?>
					<div class="tiermint-product-details__overview"><?php echo wp_kses_post( (string) $data['overview'] ); ?></div>
				<?php endif; ?>

				<?php
				// Use the shared purchase form.
				require TIERMINT_PATH . 'templates/components/partials/product-purchase-form.php';
				?>
			</div>
		</div>

		<?php if ( isset( $data['description'] ) && null !== $data['description'] ) : ?>
			<div class="tiermint-product-details__description"><?php echo wp_kses_post( (string) $data['description'] ); ?></div>
		<?php endif; ?>

		<?php
		// Show single-layout screenshots after details.
		if ( ! $tiermint_two_column && array() !== $tiermint_screenshots ) {
			require TIERMINT_PATH . 'templates/components/partials/product-screenshots-grid.php';
		}
		?>
	</div>
</div>
