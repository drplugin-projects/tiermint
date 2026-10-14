<?php
/**
 * License manager template.
 *
 * Theme override: {theme}/tiermint/components/license-manager.php.
 * Show keys only to owners.
 *
 * @package DrPlugin\WP\TierMint
 *
 * @var array<string, mixed> $data {
 *     @type string $state View state.
 *     @type string $title Optional heading.
 *     @type string $login_url Login link.
 *     @type string|null $registration_url Registration link.
 *     @type bool $show_key Show license keys.
 *     @type bool $show_activations Show site controls.
 *     @type array<int, array<string, mixed>> $licenses License rows.
 * }
 */

defined( 'ABSPATH' ) || exit;

?>
<div class="tiermint-component tiermint-license-manager" data-msg-error="<?php esc_attr_e( 'Something went wrong. Please try again.', 'tiermint' ); ?>">
	<?php if ( '' !== ( $data['title'] ?? '' ) ) : ?>
		<h2 class="tiermint-license-manager__title"><?php echo esc_html( $data['title'] ); ?></h2>
	<?php endif; ?>

	<?php if ( 'logged_out' === $data['state'] ) : ?>

		<div class="tiermint-license-manager__prompt">
			<p><?php esc_html_e( 'Please log in to view and manage your licenses.', 'tiermint' ); ?></p>
			<p>
				<a class="tiermint-license-manager__login" href="<?php echo esc_url( $data['login_url'] ); ?>">
					<?php esc_html_e( 'Log in', 'tiermint' ); ?>
				</a>
				<?php if ( null !== $data['registration_url'] ) : ?>
					<a class="tiermint-license-manager__register" href="<?php echo esc_url( $data['registration_url'] ); ?>">
						<?php esc_html_e( 'Create an account', 'tiermint' ); ?>
					</a>
				<?php endif; ?>
			</p>
		</div>

	<?php elseif ( 'empty' === $data['state'] ) : ?>

		<p class="tiermint-license-manager__empty">
			<?php esc_html_e( 'You do not have any licenses yet. Your license keys will appear here after your first purchase.', 'tiermint' ); ?>
		</p>

	<?php else : ?>

		<?php if ( $data['show_activations'] ) : ?>
			<div class="tiermint-license-manager__feedback" role="alert" aria-live="assertive" data-tiermint-feedback></div>
			<noscript>
				<p class="tiermint-license-manager__noscript">
					<?php esc_html_e( 'Managing your site activations requires JavaScript. Please enable it to activate or deactivate sites.', 'tiermint' ); ?>
				</p>
			</noscript>
		<?php endif; ?>

		<ul class="tiermint-license-manager__list">
			<?php foreach ( (array) $data['licenses'] as $tiermint_license ) : ?>
				<li
					class="tiermint-license-manager__card"
					data-tiermint-license
					data-license-id="<?php echo esc_attr( (string) $tiermint_license['id'] ); ?>"
				>
					<div class="tiermint-license-manager__header">
						<span class="tiermint-license-manager__product"><?php echo esc_html( $tiermint_license['product_name'] ); ?></span>
						<span class="tiermint-license-manager__status tiermint-badge tiermint-license-manager__status--<?php echo esc_attr( $tiermint_license['status_slug'] ); ?>">
							<?php echo esc_html( $tiermint_license['status_label'] ); ?>
						</span>
					</div>

					<?php if ( $data['show_key'] ) : ?>
						<p class="tiermint-license-manager__key-row">
							<span class="tiermint-license-manager__key-label"><?php esc_html_e( 'License key', 'tiermint' ); ?></span>
							<code class="tiermint-license-manager__key"><?php echo esc_html( $tiermint_license['license_key'] ); ?></code>
						</p>
					<?php endif; ?>

					<dl class="tiermint-license-manager__meta">
						<div class="tiermint-license-manager__meta-item">
							<dt><?php esc_html_e( 'Expires', 'tiermint' ); ?></dt>
							<dd><?php echo esc_html( $tiermint_license['expiry_label'] ); ?></dd>
						</div>
						<div class="tiermint-license-manager__meta-item">
							<dt><?php esc_html_e( 'Active sites', 'tiermint' ); ?></dt>
							<dd class="tiermint-license-manager__usage">
								<span class="tiermint-license-manager__usage-count" data-tiermint-usage-count><?php echo esc_html( (string) $tiermint_license['activation_count'] ); ?></span>
								<span class="tiermint-license-manager__usage-sep" aria-hidden="true">/</span>
								<span class="tiermint-license-manager__usage-limit"><?php echo esc_html( $tiermint_license['activation_limit_label'] ); ?></span>
							</dd>
						</div>
					</dl>

					<?php if ( $data['show_activations'] ) : ?>
						<div class="tiermint-license-manager__activations">
							<?php if ( array() !== $tiermint_license['activations'] ) : ?>
								<ul class="tiermint-license-manager__sites">
									<?php foreach ( $tiermint_license['activations'] as $tiermint_activation ) : ?>
										<li class="tiermint-license-manager__site" data-tiermint-activation>
											<span class="tiermint-license-manager__site-url"><?php echo esc_html( $tiermint_activation['site_url'] ); ?></span>
											<button
												type="button"
												class="tiermint-license-manager__deactivate tiermint-button tiermint-button--secondary tiermint-button--small"
												data-tiermint-deactivate
												data-license-key="<?php echo esc_attr( $tiermint_license['license_key'] ); ?>"
												data-site-url="<?php echo esc_attr( $tiermint_activation['site_url'] ); ?>"
											>
												<?php esc_html_e( 'Deactivate', 'tiermint' ); ?>
											</button>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php else : ?>
								<p class="tiermint-license-manager__no-sites"><?php esc_html_e( 'No sites are activated yet.', 'tiermint' ); ?></p>
							<?php endif; ?>

							<?php if ( $tiermint_license['can_activate'] ) : ?>
								<?php $tiermint_input_id = 'tiermint-license-manager-site-' . (int) $tiermint_license['id']; ?>
								<form
									class="tiermint-license-manager__activate-form"
									data-tiermint-activate-form
									data-license-key="<?php echo esc_attr( $tiermint_license['license_key'] ); ?>"
								>
									<label class="tiermint-license-manager__activate-label" for="<?php echo esc_attr( $tiermint_input_id ); ?>">
										<?php esc_html_e( 'Activate a new site', 'tiermint' ); ?>
									</label>
									<input
										type="url"
										id="<?php echo esc_attr( $tiermint_input_id ); ?>"
										class="tiermint-license-manager__activate-input tiermint-input"
										name="site_url"
										placeholder="https://example.com"
										required
										aria-required="true"
										data-tiermint-site-input
									>
									<button type="submit" class="tiermint-license-manager__activate tiermint-button tiermint-button--primary">
										<?php esc_html_e( 'Activate', 'tiermint' ); ?>
									</button>
								</form>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>

	<?php endif; ?>
</div>
