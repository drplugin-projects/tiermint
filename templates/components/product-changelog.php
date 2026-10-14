<?php
/**
 * Product changelog component template.
 *
 * Release HTML is sanitized before this template uses it.
 *
 * @package DrPlugin\WP\TierMint
 *
 * @var array{
 *     releases: array<int, array{version: string, date: string|null, html: string, compatibility: array<int, array{label: string, value: string}>}>,
 *     show_version_heading: bool
 * } $data View data supplied by the component.
 */

defined( 'ABSPATH' ) || exit;

$tiermint_releases     = ( isset( $data['releases'] ) && is_array( $data['releases'] ) ) ? $data['releases'] : array();
$tiermint_show_heading = ! empty( $data['show_version_heading'] );

if ( array() === $tiermint_releases ) {
	return;
}
?>
<div class="tiermint-component tiermint-product-changelog">
	<?php foreach ( $tiermint_releases as $tiermint_release ) : ?>
		<?php
		$tiermint_version = isset( $tiermint_release['version'] ) ? (string) $tiermint_release['version'] : '';
		$tiermint_date    = ( isset( $tiermint_release['date'] ) && '' !== (string) $tiermint_release['date'] ) ? (string) $tiermint_release['date'] : null;
		$tiermint_notes   = isset( $tiermint_release['html'] ) ? (string) $tiermint_release['html'] : '';
		$tiermint_compat  = ( isset( $tiermint_release['compatibility'] ) && is_array( $tiermint_release['compatibility'] ) ) ? $tiermint_release['compatibility'] : array();
		?>
		<section class="tiermint-product-changelog__release">
			<?php if ( $tiermint_show_heading || null !== $tiermint_date ) : ?>
				<header class="tiermint-product-changelog__header">
					<?php if ( $tiermint_show_heading && '' !== $tiermint_version ) : ?>
						<h3 class="tiermint-product-changelog__version">
							<?php
							/* translators: %s: a release version number, e.g. 1.4.0. */
							echo esc_html( sprintf( __( 'Version %s', 'tiermint' ), $tiermint_version ) );
							?>
						</h3>
					<?php endif; ?>
					<?php if ( null !== $tiermint_date ) : ?>
						<span class="tiermint-product-changelog__date"><?php echo esc_html( $tiermint_date ); ?></span>
					<?php endif; ?>
				</header>
			<?php endif; ?>

			<?php if ( '' !== $tiermint_notes ) : ?>
				<div class="tiermint-product-changelog__notes">
					<?php
					// ChangelogRenderer returns wp_kses_post-sanitized HTML.
					echo $tiermint_notes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ChangelogRenderer returns wp_kses_post-sanitized HTML.
					?>
				</div>
			<?php endif; ?>

			<?php if ( array() !== $tiermint_compat ) : ?>
				<p class="tiermint-product-changelog__compat">
					<?php foreach ( $tiermint_compat as $tiermint_compat_item ) : ?>
						<span class="tiermint-product-changelog__compat-item">
							<?php
							/* translators: 1: a label, 2: its value. */
							echo esc_html( sprintf( __( '%1$s: %2$s', 'tiermint' ), (string) $tiermint_compat_item['label'], (string) $tiermint_compat_item['value'] ) );
							?>
						</span>
					<?php endforeach; ?>
				</p>
			<?php endif; ?>
		</section>
	<?php endforeach; ?>
</div>
