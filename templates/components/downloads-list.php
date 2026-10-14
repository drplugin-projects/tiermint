<?php
/**
 * Downloads list template.
 *
 * Theme override: {theme}/tiermint/components/downloads-list.php.
 *
 * @package DrPlugin\WP\TierMint
 *
 * @var array<string, mixed> $data {
 *     @type string $state View state.
 *     @type string $title Optional heading.
 *     @type string $login_url Login link.
 *     @type bool $show_updates Show updates.
 *     @type array<int, array<string, mixed>> $downloads Download entries.
 *     @type array<int, array<string, mixed>> $updates Update entries.
 * }
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="tiermint-component tiermint-downloads-list">
	<?php if ( '' !== $data['title'] ) : ?>
		<h2 class="tiermint-downloads-list__title"><?php echo esc_html( $data['title'] ); ?></h2>
	<?php endif; ?>

	<?php if ( 'logged_out' === $data['state'] ) : ?>

		<p class="tiermint-downloads-list__prompt">
			<?php echo esc_html__( 'Please log in to view your downloads.', 'tiermint' ); ?>
		</p>
		<a class="tiermint-downloads-list__login" href="<?php echo esc_url( $data['login_url'] ); ?>">
			<?php echo esc_html__( 'Log in', 'tiermint' ); ?>
		</a>

	<?php elseif ( 'empty' === $data['state'] ) : ?>

		<p class="tiermint-downloads-list__empty">
			<?php echo esc_html__( 'You have no downloads yet.', 'tiermint' ); ?>
		</p>

	<?php else : ?>

		<?php if ( array() !== $data['downloads'] ) : ?>
			<ul class="tiermint-downloads-list__products">
				<?php foreach ( $data['downloads'] as $tiermint_entry ) : ?>
					<li class="tiermint-downloads-list__product">
						<h3 class="tiermint-downloads-list__product-name">
							<?php echo esc_html( (string) $tiermint_entry['product_name'] ); ?>
						</h3>
						<ul class="tiermint-downloads-list__files">
							<?php foreach ( $tiermint_entry['files'] as $tiermint_file ) : ?>
								<li class="tiermint-downloads-list__file">
									<a
										class="tiermint-downloads-list__file-link"
										href="<?php echo esc_url( (string) $tiermint_file['download_url'] ); ?>"
										download
									>
										<?php echo esc_html( (string) $tiermint_file['label'] ); ?>
									</a>
								</li>
							<?php endforeach; ?>
						</ul>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<?php if ( $data['show_updates'] && array() !== $data['updates'] ) : ?>
			<section class="tiermint-downloads-list__updates">
				<h3 class="tiermint-downloads-list__updates-title">
					<?php echo esc_html__( 'Updates', 'tiermint' ); ?>
				</h3>
				<ul class="tiermint-downloads-list__update-items">
					<?php foreach ( $data['updates'] as $tiermint_update ) : ?>
						<li class="tiermint-downloads-list__update">
							<span class="tiermint-downloads-list__update-product">
								<?php echo esc_html( (string) $tiermint_update['product_name'] ); ?>
							</span>
							<span class="tiermint-downloads-list__update-version">
								<?php
								printf(
									/* translators: %s: a release version number, e.g. 1.4.0. */
									esc_html__( 'Version %s', 'tiermint' ),
									esc_html( (string) $tiermint_update['version'] )
								);
								?>
							</span>
							<a
								class="tiermint-downloads-list__update-link"
								href="<?php echo esc_url( (string) $tiermint_update['download_url'] ); ?>"
								download
							>
								<?php echo esc_html__( 'Download update', 'tiermint' ); ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>

	<?php endif; ?>
</div>
