<?php
/**
 * Account dashboard template.
 *
 * Theme override: {theme}/tiermint/components/account-dashboard.php.
 * Tabs enhance the live view.
 *
 * @package DrPlugin\WP\TierMint
 *
 * @var array<string, mixed> $data {
 *     @type string $state      View state.
 *     @type string $title      Optional heading.
 *     @type string $login_html Login form markup.
 *     @type string $login_url  Login link.
 *     @type array{avatar: string, greeting: string} $header Greeting data.
 *     @type array<int, array<string, mixed>> $tabs Prepared tabs.
 * }
 */

defined( 'ABSPATH' ) || exit;

?>
<div class="tiermint-component tiermint-account-dashboard">
	<?php if ( '' !== ( $data['title'] ?? '' ) ) : ?>
		<h2 class="tiermint-account-dashboard__title"><?php echo esc_html( $data['title'] ); ?></h2>
	<?php endif; ?>

	<?php if ( 'logged_out' === $data['state'] ) : ?>

		<div class="tiermint-account-dashboard__prompt">
			<p><?php esc_html_e( 'Please log in to view your account.', 'tiermint' ); ?></p>
			<?php if ( '' !== $data['login_html'] ) : ?>
				<?php echo $data['login_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- component renderers return fully escaped HTML. ?>
			<?php else : ?>
				<a class="tiermint-account-dashboard__login tiermint-button tiermint-button--primary" href="<?php echo esc_url( $data['login_url'] ); ?>">
					<?php esc_html_e( 'Log in', 'tiermint' ); ?>
				</a>
			<?php endif; ?>
		</div>

	<?php else : ?>

		<div class="tiermint-account-dashboard__header">
			<?php if ( '' !== $data['header']['avatar'] ) : ?>
				<span class="tiermint-account-dashboard__avatar">
					<?php echo $data['header']['avatar']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_avatar() returns an escaped <img> element. ?>
				</span>
			<?php endif; ?>
			<p class="tiermint-account-dashboard__greeting"><?php echo esc_html( $data['header']['greeting'] ); ?></p>
		</div>

		<div class="tiermint-account-dashboard__tabs" data-tiermint-account-dashboard>
			<ul class="tiermint-account-dashboard__tablist" role="tablist" aria-label="<?php esc_attr_e( 'Account sections', 'tiermint' ); ?>">
				<?php foreach ( (array) $data['tabs'] as $tiermint_tab ) : ?>
					<li class="tiermint-account-dashboard__tabitem" role="presentation">
						<a
							class="tiermint-account-dashboard__tab"
							id="<?php echo esc_attr( $tiermint_tab['tab_id'] ); ?>"
							href="#<?php echo esc_attr( $tiermint_tab['panel_id'] ); ?>"
							role="tab"
							aria-controls="<?php echo esc_attr( $tiermint_tab['panel_id'] ); ?>"
						<?php echo $tiermint_tab['tab_attrs']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static enhanced-tab-state attributes built from fixed literals, empty on a live render. ?>><?php echo esc_html( $tiermint_tab['label'] ); ?></a>
					</li>
				<?php endforeach; ?>
			</ul>

			<?php foreach ( (array) $data['tabs'] as $tiermint_tab ) : ?>
				<section
					class="tiermint-account-dashboard__panel"
					id="<?php echo esc_attr( $tiermint_tab['panel_id'] ); ?>"
					role="tabpanel"
					aria-labelledby="<?php echo esc_attr( $tiermint_tab['tab_id'] ); ?>"
				<?php echo $tiermint_tab['panel_attrs']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static ' hidden' attribute or empty string, set only in an editor preview. ?>>
					<h3 class="tiermint-account-dashboard__panel-title"><?php echo esc_html( $tiermint_tab['label'] ); ?></h3>

					<?php if ( 'purchases' === $tiermint_tab['key'] ) : ?>

						<?php if ( array() === $tiermint_tab['purchases'] ) : ?>
							<p class="tiermint-account-dashboard__empty">
								<?php esc_html_e( 'You have no purchases yet.', 'tiermint' ); ?>
							</p>
						<?php else : ?>
							<ul class="tiermint-account-dashboard__orders">
								<?php foreach ( $tiermint_tab['purchases'] as $tiermint_order ) : ?>
									<li class="tiermint-account-dashboard__order tiermint-card">
										<div class="tiermint-account-dashboard__order-header">
											<span class="tiermint-account-dashboard__order-ref"><?php echo esc_html( $tiermint_order['reference'] ); ?></span>
											<span class="tiermint-account-dashboard__order-date"><?php echo esc_html( $tiermint_order['date'] ); ?></span>
											<span class="tiermint-account-dashboard__order-status tiermint-badge tiermint-account-dashboard__order-status--<?php echo esc_attr( $tiermint_order['status_slug'] ); ?>">
												<?php echo esc_html( $tiermint_order['status_label'] ); ?>
											</span>
										</div>
										<ul class="tiermint-account-dashboard__order-items">
											<?php foreach ( $tiermint_order['items'] as $tiermint_item ) : ?>
												<li class="tiermint-account-dashboard__order-item">
													<span class="tiermint-account-dashboard__order-item-name"><?php echo esc_html( $tiermint_item['name'] ); ?></span>
													<span class="tiermint-account-dashboard__order-item-qty"><span aria-hidden="true">&times;</span><?php echo esc_html( (string) $tiermint_item['quantity'] ); ?></span>
												</li>
											<?php endforeach; ?>
										</ul>
										<p class="tiermint-account-dashboard__order-total"><?php echo esc_html( $tiermint_order['total'] ); ?></p>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>

					<?php else : ?>
						<?php echo $tiermint_tab['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- component renderers return fully escaped HTML. ?>
					<?php endif; ?>
				</section>
			<?php endforeach; ?>
		</div>

	<?php endif; ?>
</div>
