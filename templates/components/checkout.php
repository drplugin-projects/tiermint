<?php
/**
 * Checkout template.
 *
 * Theme override: {theme}/tiermint/components/checkout.php.
 *
 * @package DrPlugin\WP\TierMint
 */

defined( 'ABSPATH' ) || exit;

?>
<div class="tiermint-component tiermint-checkout tiermint-checkout--<?php echo esc_attr( 'single' === ( $data['layout'] ?? 'two-column' ) ? 'single' : 'two-column' ); ?>" data-tiermint-checkout>
	<?php // Keep the width cap outside the theme content column. ?>
	<div class="tiermint-checkout__inner">
		<?php if ( '' !== ( $data['title'] ?? '' ) ) : ?>
			<h2 class="tiermint-checkout__title"><?php echo esc_html( $data['title'] ); ?></h2>
		<?php endif; ?>

		<?php if ( ! empty( $data['cart_error'] ) ) : ?>
			<div class="tiermint-checkout__cart-error tiermint-notice tiermint-notice--error" role="alert">
				<p><?php echo esc_html( $data['cart_error'] ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $data['renewal'] ) && is_array( $data['renewal'] ) ) : ?>
			<div class="tiermint-checkout__renewal">
				<p>
					<?php
					printf(
						/* translators: %s: product name. */
						esc_html__( 'You are buying a new license for %s. It comes with a new license key.', 'tiermint' ),
						esc_html( $data['renewal']['product_name'] )
					);
					?>
				</p>
				<?php if ( empty( $data['renewal']['in_cart'] ) ) : ?>
					<form method="post" action="<?php echo esc_url( $data['action_url'] ?? '' ); ?>">
						<input type="hidden" name="tiermint_cart_action" value="add">
						<input type="hidden" name="product_id" value="<?php echo esc_attr( (string) $data['renewal']['product_id'] ); ?>">
						<input type="hidden" name="price_id" value="<?php echo esc_attr( (string) $data['renewal']['price_id'] ); ?>">
						<?php wp_nonce_field( \DrPlugin\WP\TierMint\Frontend\CartActionListener::NONCE_ACTION, \DrPlugin\WP\TierMint\Frontend\CartActionListener::NONCE_FIELD ); ?>
						<button type="submit" class="tiermint-checkout__renewal-add tiermint-button tiermint-button--primary">
							<?php esc_html_e( 'Add it to the cart', 'tiermint' ); ?>
						</button>
					</form>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php if ( 'confirmation' === $data['state'] ) : ?>
			<div
				class="tiermint-checkout__confirmation tiermint-card"
				role="status"
				aria-live="polite"
				data-order-uuid="<?php echo esc_attr( $data['order_uuid'] ); ?>"
				data-msg-confirmed="<?php esc_attr_e( 'Payment confirmed! Your receipt and any license keys are on their way to your inbox.', 'tiermint' ); ?>"
			>
				<h3><?php esc_html_e( 'Thank you for your purchase!', 'tiermint' ); ?></h3>
				<p class="tiermint-checkout__confirmation-status">
					<?php esc_html_e( 'We are confirming your payment. This page will update in a moment, and your receipt is on its way to your inbox.', 'tiermint' ); ?>
				</p>
			</div>
		<?php elseif ( 'empty' === $data['state'] ) : ?>
			<p class="tiermint-checkout__empty"><?php esc_html_e( 'Your cart is empty.', 'tiermint' ); ?></p>
		<?php elseif ( 'unavailable' === $data['state'] ) : ?>
			<div class="tiermint-checkout__unavailable" role="alert">
				<p><?php esc_html_e( 'Checkout is not available right now. Please try again later.', 'tiermint' ); ?></p>
				<?php if ( ! empty( $data['admin_hint'] ) ) : ?>
					<p><?php esc_html_e( 'Admins: add your Stripe keys in the TierMint payment settings to enable checkout.', 'tiermint' ); ?></p>
				<?php endif; ?>
			</div>
		<?php elseif ( 'form' === $data['state'] ) : ?>
			<?php if ( ! empty( $data['cart_notices'] ) && is_array( $data['cart_notices'] ) ) : ?>
				<div class="tiermint-checkout__notice tiermint-notice tiermint-notice--info" role="status">
					<?php foreach ( $data['cart_notices'] as $tiermint_cart_notice ) : ?>
						<p><?php echo esc_html( (string) $tiermint_cart_notice ); ?></p>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<?php
			/* Keep the form first in reading order. */
			$tiermint_has_aside = ! empty( $data['show_summary'] );

			// A discount needs a payment form.
			$tiermint_show_discount = ! empty( $data['show_discount'] ) && empty( $data['login_required'] );
			?>
			<?php if ( $tiermint_has_aside ) : ?>
				<div class="tiermint-checkout__columns">
					<div class="tiermint-checkout__main">
			<?php endif; ?>
			<?php if ( empty( $data['login_required'] ) && ! empty( $data['login_html'] ) ) : ?>
				<details class="tiermint-checkout__login tiermint-card">
					<summary class="tiermint-checkout__login-toggle">
						<span class="tiermint-checkout__login-prompt"><?php esc_html_e( 'Returning customer?', 'tiermint' ); ?></span>
						<span class="tiermint-checkout__login-cta"><?php esc_html_e( 'Log in', 'tiermint' ); ?></span>
					</summary>
					<div class="tiermint-checkout__login-body">
						<?php echo wp_kses( $data['login_html'], \DrPlugin\WP\TierMint\Frontend\Kses::allowed() ); ?>
					</div>
				</details>
			<?php endif; ?>

			<?php if ( ! empty( $data['login_required'] ) ) : ?>
				<div class="tiermint-checkout__login tiermint-checkout__login--required tiermint-card">
					<h3 class="tiermint-checkout__login-heading"><?php esc_html_e( 'Please log in to check out', 'tiermint' ); ?></h3>
					<p><?php esc_html_e( 'An account is required to complete your purchase.', 'tiermint' ); ?></p>
					<?php echo wp_kses( $data['login_html'], \DrPlugin\WP\TierMint\Frontend\Kses::allowed() ); ?>
				</div>
			<?php else : ?>
				<?php if ( ! empty( $data['account_notice'] ) ) : ?>
				<div class="tiermint-checkout__account-notice tiermint-notice tiermint-notice--info" role="status">
					<p><?php echo esc_html( (string) $data['account_notice'] ); ?></p>
				</div>
			<?php endif; ?>
			<form
				class="tiermint-checkout__form tiermint-card"
				method="post"
				action="<?php echo esc_url( $data['action_url'] ?? '' ); ?>"
				data-msg-error="<?php esc_attr_e( 'Something went wrong. Please check your details and try again.', 'tiermint' ); ?>"
				data-msg-continue="<?php esc_attr_e( 'Enter your payment details above to finish.', 'tiermint' ); ?>"
				data-msg-reload="<?php esc_attr_e( 'Reload the page to sign in.', 'tiermint' ); ?>"
				<?php
				/* The checkout code knows the total changed, not why. */
				?>
				data-msg-total-changed="<?php esc_attr_e( 'Your total changed since this page loaded. Please review it before paying.', 'tiermint' ); ?>"
			>
				<div class="tiermint-checkout__section">
					<h3 class="tiermint-checkout__section-label"><?php esc_html_e( 'Contact', 'tiermint' ); ?></h3>
					<p class="tiermint-checkout__field tiermint-field">
						<label for="tiermint-checkout-email"><?php esc_html_e( 'Email address', 'tiermint' ); ?></label>
						<input
							type="email"
							class="tiermint-input" id="tiermint-checkout-email"
							name="email"
							autocomplete="email"
							required
							aria-required="true"
							value="<?php echo esc_attr( $data['email'] ); ?>"
							<?php echo ! empty( $data['email_readonly'] ) ? 'readonly' : ''; ?>
						>
					</p>
					<?php $tiermint_require_first = ! empty( $data['require_first_name'] ); ?>
					<p class="tiermint-checkout__field tiermint-field">
						<label for="tiermint-checkout-first-name"><?php echo $tiermint_require_first ? esc_html__( 'First name', 'tiermint' ) : esc_html__( 'First name (optional)', 'tiermint' ); ?></label>
						<input
							type="text"
							class="tiermint-input" id="tiermint-checkout-first-name"
							name="first_name"
							autocomplete="given-name"
							value="<?php echo esc_attr( (string) ( $data['first_name'] ?? '' ) ); ?>"
							<?php echo $tiermint_require_first ? 'required aria-required="true"' : ''; ?>
						>
					</p>
					<?php $tiermint_require_last = ! empty( $data['require_last_name'] ); ?>
					<p class="tiermint-checkout__field tiermint-field">
						<label for="tiermint-checkout-last-name"><?php echo $tiermint_require_last ? esc_html__( 'Last name', 'tiermint' ) : esc_html__( 'Last name (optional)', 'tiermint' ); ?></label>
						<input
							type="text"
							class="tiermint-input" id="tiermint-checkout-last-name"
							name="last_name"
							autocomplete="family-name"
							value="<?php echo esc_attr( (string) ( $data['last_name'] ?? '' ) ); ?>"
							<?php echo $tiermint_require_last ? 'required aria-required="true"' : ''; ?>
						>
					</p>
				</div>
				<?php
				/* Core billing fields for the quote and payment intent. */
				$tiermint_billing = isset( $data['billing'] ) && is_array( $data['billing'] ) ? $data['billing'] : array();

				// Show a heading only when billing fields render.
				$tiermint_has_billing = ! empty( $tiermint_billing['country']['show'] )
					|| ! empty( $tiermint_billing['company']['show'] )
					|| ! empty( $tiermint_billing['vat']['show'] )
					|| 'none' !== ( $tiermint_billing['address']['mode'] ?? 'none' )
					|| array() !== (array) ( $data['fields'] ?? array() );
				?>
				<?php if ( $tiermint_has_billing ) : ?>
					<div class="tiermint-checkout__section">
						<h3 class="tiermint-checkout__section-label"><?php esc_html_e( 'Billing', 'tiermint' ); ?></h3>
						<?php if ( ! empty( $tiermint_billing['country']['show'] ) ) : ?>
							<?php
							$tiermint_country_value       = (string) ( $tiermint_billing['country']['value'] ?? '' );
							$tiermint_country_required    = ! empty( $tiermint_billing['country']['required'] );
							$tiermint_country_placeholder = $tiermint_country_required && '' === $tiermint_country_value;
							$tiermint_country_label       = (string) ( $tiermint_billing['country']['label'] ?? '' );
							$tiermint_country_ph_text     = (string) ( $tiermint_billing['country']['placeholder'] ?? '' );
							$tiermint_country_help        = (string) ( $tiermint_billing['country']['help'] ?? '' );
							?>
							<p class="tiermint-checkout__field tiermint-field tiermint-checkout__field--country">
								<label for="tiermint-checkout-billing-country"><?php echo esc_html( $tiermint_country_label ); ?></label>
								<select
									class="tiermint-select" id="tiermint-checkout-billing-country"
									name="billing_country"
									data-tiermint-field="billing_country"
									autocomplete="country"
									<?php echo '' !== $tiermint_country_help ? 'aria-describedby="tiermint-checkout-billing-country-help"' : ''; ?>
									<?php echo $tiermint_country_required ? 'required aria-required="true"' : ''; ?>
								>
									<option value=""<?php echo $tiermint_country_required ? ' disabled' : ''; ?><?php echo $tiermint_country_placeholder ? ' selected' : ''; ?>><?php echo esc_html( $tiermint_country_ph_text ); ?></option>
									<?php foreach ( (array) ( $tiermint_billing['country']['options'] ?? array() ) as $tiermint_cc => $tiermint_cname ) : ?>
										<option value="<?php echo esc_attr( (string) $tiermint_cc ); ?>"<?php echo (string) $tiermint_cc === $tiermint_country_value ? ' selected' : ''; ?>><?php echo esc_html( (string) $tiermint_cname ); ?></option>
									<?php endforeach; ?>
								</select>
								<?php if ( '' !== $tiermint_country_help ) : ?>
									<span class="tiermint-checkout__help" id="tiermint-checkout-billing-country-help"><?php echo esc_html( $tiermint_country_help ); ?></span>
								<?php endif; ?>
							</p>
						<?php endif; ?>
						<?php
						/* Render the tax ID once to keep its ID unique. */
						$tiermint_company_show = ! empty( $tiermint_billing['company']['show'] );
						$tiermint_vat_show     = ! empty( $tiermint_billing['vat']['show'] );
						$tiermint_reveal_open  = ! empty( $tiermint_billing['company']['reveal_open'] );
						?>
						<?php if ( $tiermint_company_show ) : ?>
							<p class="tiermint-checkout__field tiermint-field tiermint-checkout__field--company-toggle">
								<label class="tiermint-checkout__company-check">
									<input
										type="checkbox"
										class="tiermint-checkbox" id="tiermint-checkout-company-toggle"
										aria-controls="tiermint-checkout-company-fields"
										<?php echo $tiermint_reveal_open ? 'checked' : ''; ?>
									>
									<span><?php esc_html_e( 'I am buying as a company', 'tiermint' ); ?></span>
								</label>
							</p>
							<div id="tiermint-checkout-company-fields" class="tiermint-checkout__company-fields">
								<p class="tiermint-checkout__field tiermint-field tiermint-checkout__field--company">
									<label for="tiermint-checkout-company"><?php echo esc_html( (string) ( $tiermint_billing['company']['label'] ?? '' ) ); ?></label>
									<input
										type="text"
										class="tiermint-input" id="tiermint-checkout-company"
										name="company_name"
										data-tiermint-field="company_name"
										autocomplete="organization"
										value="<?php echo esc_attr( (string) ( $tiermint_billing['company']['value'] ?? '' ) ); ?>"
									>
								</p>
						<?php endif; ?>
						<?php if ( $tiermint_vat_show ) : ?>
							<?php
							$tiermint_vat_label = (string) ( $tiermint_billing['vat']['label'] ?? '' );
							$tiermint_vat_ph    = (string) ( $tiermint_billing['vat']['placeholder'] ?? '' );
							$tiermint_vat_help  = (string) ( $tiermint_billing['vat']['help'] ?? '' );
							?>
							<p class="tiermint-checkout__field tiermint-field tiermint-checkout__field--vat">
								<label for="tiermint-checkout-vat"><?php echo esc_html( $tiermint_vat_label ); ?></label>
								<input
									type="text"
									class="tiermint-input" id="tiermint-checkout-vat"
									name="vat_number"
									data-tiermint-field="vat_number"
									autocomplete="off"
									<?php echo '' !== $tiermint_vat_ph ? 'placeholder="' . esc_attr( $tiermint_vat_ph ) . '"' : ''; ?>
									<?php echo '' !== $tiermint_vat_help ? 'aria-describedby="tiermint-checkout-vat-help"' : ''; ?>
									value="<?php echo esc_attr( (string) ( $tiermint_billing['vat']['value'] ?? '' ) ); ?>"
								>
								<?php if ( '' !== $tiermint_vat_help ) : ?>
									<span class="tiermint-checkout__help" id="tiermint-checkout-vat-help"><?php echo esc_html( $tiermint_vat_help ); ?></span>
								<?php endif; ?>
							</p>
						<?php endif; ?>
						<?php if ( $tiermint_company_show ) : ?>
							</div>
						<?php endif; ?>
						<?php if ( 'none' !== ( $tiermint_billing['address']['mode'] ?? 'none' ) ) : ?>
							<?php $tiermint_address_required = 'required' === ( $tiermint_billing['address']['mode'] ?? 'none' ); ?>
							<fieldset class="tiermint-checkout__address">
								<legend><?php esc_html_e( 'Billing address', 'tiermint' ); ?></legend>
								<p class="tiermint-checkout__field tiermint-field">
									<label for="tiermint-checkout-address-1"><?php esc_html_e( 'Address', 'tiermint' ); ?></label>
									<input type="text" class="tiermint-input" id="tiermint-checkout-address-1" name="billing_address_1" data-tiermint-field="billing_address_1" autocomplete="address-line1" <?php echo $tiermint_address_required ? 'required aria-required="true"' : ''; ?>>
								</p>
								<p class="tiermint-checkout__field tiermint-field">
									<label for="tiermint-checkout-address-2"><?php esc_html_e( 'Address line 2 (optional)', 'tiermint' ); ?></label>
									<input type="text" class="tiermint-input" id="tiermint-checkout-address-2" name="billing_address_2" data-tiermint-field="billing_address_2" autocomplete="address-line2">
								</p>
								<p class="tiermint-checkout__field tiermint-field">
									<label for="tiermint-checkout-city"><?php esc_html_e( 'City', 'tiermint' ); ?></label>
									<input type="text" class="tiermint-input" id="tiermint-checkout-city" name="billing_city" data-tiermint-field="billing_city" autocomplete="address-level2" <?php echo $tiermint_address_required ? 'required aria-required="true"' : ''; ?>>
								</p>
								<p class="tiermint-checkout__field tiermint-field">
									<label for="tiermint-checkout-state"><?php esc_html_e( 'State or region (optional)', 'tiermint' ); ?></label>
									<input type="text" class="tiermint-input" id="tiermint-checkout-state" name="billing_state" data-tiermint-field="billing_state" autocomplete="address-level1">
								</p>
								<p class="tiermint-checkout__field tiermint-field">
									<label for="tiermint-checkout-postcode"><?php esc_html_e( 'Postal code (optional)', 'tiermint' ); ?></label>
									<input type="text" class="tiermint-input" id="tiermint-checkout-postcode" name="billing_postcode" data-tiermint-field="billing_postcode" autocomplete="postal-code">
								</p>
							</fieldset>
						<?php endif; ?>
						<?php
						/* Extensions add fields used by the quote and payment intent. */
						foreach ( (array) ( $data['fields'] ?? array() ) as $tiermint_field ) :
							$tiermint_field_id   = 'tiermint-checkout-field-' . $tiermint_field['id'];
							$tiermint_field_help = '' !== $tiermint_field['help'] ? $tiermint_field_id . '-help' : '';
							?>
							<div class="tiermint-checkout__field tiermint-field tiermint-checkout__field--extra">
								<label for="<?php echo esc_attr( $tiermint_field_id ); ?>"><?php echo esc_html( $tiermint_field['label'] ); ?></label>
								<?php if ( 'select' === $tiermint_field['type'] ) : ?>
									<select
										id="<?php echo esc_attr( $tiermint_field_id ); ?>"
										data-tiermint-field="<?php echo esc_attr( $tiermint_field['id'] ); ?>"
										<?php echo '' !== $tiermint_field['autocomplete'] ? 'autocomplete="' . esc_attr( $tiermint_field['autocomplete'] ) . '"' : ''; ?>
										<?php echo '' !== $tiermint_field_help ? 'aria-describedby="' . esc_attr( $tiermint_field_help ) . '"' : ''; ?>
										<?php echo $tiermint_field['required'] ? 'required aria-required="true"' : ''; ?>
									>
										<?php
										/* Keep the required-select placeholder invalid until a choice. */
										$tiermint_placeholder_selected = $tiermint_field['required'] && '' === $tiermint_field['value'];
										?>
										<option value=""<?php echo $tiermint_field['required'] ? ' disabled' : ''; ?><?php echo $tiermint_placeholder_selected ? ' selected' : ''; ?>><?php echo esc_html( $tiermint_field['placeholder'] ); ?></option>
										<?php foreach ( (array) $tiermint_field['options'] as $tiermint_option_value => $tiermint_option_label ) : ?>
											<option value="<?php echo esc_attr( (string) $tiermint_option_value ); ?>"<?php echo (string) $tiermint_option_value === $tiermint_field['value'] ? ' selected' : ''; ?>><?php echo esc_html( (string) $tiermint_option_label ); ?></option>
										<?php endforeach; ?>
									</select>
								<?php elseif ( 'checkbox' === $tiermint_field['type'] ) : ?>
									<input
										type="checkbox"
										id="<?php echo esc_attr( $tiermint_field_id ); ?>"
										data-tiermint-field="<?php echo esc_attr( $tiermint_field['id'] ); ?>"
										value="1"
										<?php echo '' !== $tiermint_field_help ? 'aria-describedby="' . esc_attr( $tiermint_field_help ) . '"' : ''; ?>
										<?php echo $tiermint_field['required'] ? 'required aria-required="true"' : ''; ?>
										<?php echo '1' === $tiermint_field['value'] ? 'checked' : ''; ?>
									>
								<?php else : ?>
									<input
										type="<?php echo esc_attr( $tiermint_field['type'] ); ?>"
										id="<?php echo esc_attr( $tiermint_field_id ); ?>"
										data-tiermint-field="<?php echo esc_attr( $tiermint_field['id'] ); ?>"
										value="<?php echo esc_attr( $tiermint_field['value'] ); ?>"
										<?php echo '' !== $tiermint_field['placeholder'] ? 'placeholder="' . esc_attr( $tiermint_field['placeholder'] ) . '"' : ''; ?>
										<?php echo '' !== $tiermint_field['autocomplete'] ? 'autocomplete="' . esc_attr( $tiermint_field['autocomplete'] ) . '"' : ''; ?>
										<?php echo '' !== $tiermint_field_help ? 'aria-describedby="' . esc_attr( $tiermint_field_help ) . '"' : ''; ?>
										<?php echo $tiermint_field['required'] ? 'required aria-required="true"' : ''; ?>
									>
								<?php endif; ?>
								<?php if ( '' !== $tiermint_field_help ) : ?>
									<p class="tiermint-checkout__help" id="<?php echo esc_attr( $tiermint_field_help ); ?>"><?php echo esc_html( $tiermint_field['help'] ); ?></p>
								<?php endif; ?>
							</div>
							<?php
						endforeach;
						?>
					</div>
				<?php endif; ?>
				<div class="tiermint-checkout__section">
					<h3 class="tiermint-checkout__section-label"><?php esc_html_e( 'Payment', 'tiermint' ); ?></h3>
					<?php if ( ! empty( $tiermint_billing['terms']['show'] ) ) : ?>
						<?php
						$tiermint_terms_text   = esc_html__( 'Terms and Conditions', 'tiermint' );
						$tiermint_privacy_text = esc_html__( 'Privacy Policy', 'tiermint' );
						$tiermint_terms_url    = $tiermint_billing['terms']['terms_url'] ?? null;
						$tiermint_privacy_url  = $tiermint_billing['terms']['privacy_url'] ?? null;
						$tiermint_terms_link   = null !== $tiermint_terms_url
							? '<a href="' . esc_url( (string) $tiermint_terms_url ) . '" target="_blank" rel="noopener noreferrer">' . $tiermint_terms_text . '</a>'
							: $tiermint_terms_text;
						$tiermint_privacy_link = null !== $tiermint_privacy_url
							? '<a href="' . esc_url( (string) $tiermint_privacy_url ) . '" target="_blank" rel="noopener noreferrer">' . $tiermint_privacy_text . '</a>'
							: $tiermint_privacy_text;
						$tiermint_terms_label  = sprintf(
							/* translators: 1: link to or name of the terms page, 2: link to or name of the privacy policy page. */
							esc_html__( 'I agree to the %1$s and %2$s.', 'tiermint' ),
							$tiermint_terms_link,
							$tiermint_privacy_link
						);
						$tiermint_terms_allowed = array(
							'a' => array(
								'href'   => array(),
								'target' => array(),
								'rel'    => array(),
							),
						);
						?>
						<p class="tiermint-checkout__field tiermint-field tiermint-checkout__field--terms">
							<label class="tiermint-checkout__terms">
								<input
									type="checkbox"
									class="tiermint-checkbox" id="tiermint-checkout-terms"
									name="terms_accepted"
									data-tiermint-field="terms_accepted"
									value="1"
									required
									aria-required="true"
								>
								<span><?php echo wp_kses( $tiermint_terms_label, $tiermint_terms_allowed ); ?></span>
							</label>
						</p>
					<?php endif; ?>
					<?php $tiermint_gateways = isset( $data['gateways'] ) && is_array( $data['gateways'] ) ? $data['gateways'] : array(); ?>
					<?php $tiermint_default_gateway = (string) ( $data['default_gateway'] ?? '' ); ?>

					<?php if ( count( $tiermint_gateways ) > 1 ) : ?>
						<fieldset class="tiermint-checkout__gateways" data-tiermint-gateways>
							<legend><?php esc_html_e( 'Payment method', 'tiermint' ); ?></legend>
							<?php foreach ( $tiermint_gateways as $tiermint_gid => $tiermint_glabel ) : ?>
								<label class="tiermint-checkout__gateway-choice">
									<input
										type="radio"
										name="tiermint_gateway"
										value="<?php echo esc_attr( (string) $tiermint_gid ); ?>"
										data-tiermint-gateway-radio
										<?php echo ( (string) $tiermint_gid === $tiermint_default_gateway ) ? 'checked' : ''; ?>
									>
									<span><?php echo esc_html( (string) $tiermint_glabel ); ?></span>
								</label>
							<?php endforeach; ?>
						</fieldset>
					<?php endif; ?>

					<?php foreach ( $tiermint_gateways as $tiermint_gid => $tiermint_glabel ) : ?>
						<div
							class="tiermint-checkout__gateway"
							data-gateway="<?php echo esc_attr( (string) $tiermint_gid ); ?>"
							<?php echo ( (string) $tiermint_gid === $tiermint_default_gateway ) ? '' : 'hidden'; ?>
						>
							<?php if ( 'stripe' === (string) $tiermint_gid ) : ?>
								<div id="tiermint-checkout-payment" class="tiermint-checkout__payment" aria-label="<?php esc_attr_e( 'Payment details', 'tiermint' ); ?>"></div>
							<?php endif; ?>
							<button type="submit" class="tiermint-checkout__submit tiermint-button tiermint-button--primary" data-tiermint-submit data-gateway="<?php echo esc_attr( (string) $tiermint_gid ); ?>">
								<?php
								if ( 'stripe' === (string) $tiermint_gid ) {
									echo esc_html( $data['button_text'] );
								} else {
									printf(
										/* translators: %s: payment gateway name. */
										esc_html__( 'Continue with %s', 'tiermint' ),
										esc_html( (string) $tiermint_glabel )
									);
								}
								?>
							</button>
							<p class="tiermint-checkout__secure"><?php esc_html_e( 'Payments are processed securely.', 'tiermint' ); ?></p>
						</div>
					<?php endforeach; ?>
					<div class="tiermint-checkout__errors" role="alert" aria-live="assertive"></div>
					<noscript>
						<p class="tiermint-checkout__noscript">
							<?php esc_html_e( 'JavaScript is required to complete your payment securely. Please enable it and reload this page.', 'tiermint' ); ?>
						</p>
					</noscript>
				</div>
			</form>
			<?php endif; ?>
			<?php if ( $tiermint_has_aside ) : ?>
				</div><?php // .tiermint-checkout__main ?>
				<div class="tiermint-checkout__aside">
					<?php
					// The editable summary lets the buyer change a quantity or drop a
					// line straight from the aside. Its forms are CartActionListener
					// POSTs, and they must stay OUTSIDE the payment form (nested forms
					// silently flatten): the aside is a sibling of the form column, so
					// the placement guarantees it. A merchant can turn this off for a
					// read-only summary, which keeps the plain "x N" quantity badge.
					$tiermint_summary_editable = ! empty( $data['editable_summary'] );
					?>
					<section class="tiermint-checkout__summary tiermint-card" aria-label="<?php esc_attr_e( 'Order summary', 'tiermint' ); ?>">
						<h3><?php esc_html_e( 'Order summary', 'tiermint' ); ?></h3>
						<ul class="tiermint-checkout__lines">
							<?php foreach ( (array) $data['lines'] as $tiermint_line ) : ?>
								<?php $tiermint_line_editable = $tiermint_summary_editable && empty( $tiermint_line['fixed_single'] ); ?>
								<li class="tiermint-checkout__line<?php echo $tiermint_summary_editable ? ' tiermint-checkout__line--editable' : ''; ?>">
									<span class="tiermint-checkout__line-name">
										<?php echo esc_html( $tiermint_line['name'] ); ?>
										<?php if ( '' !== $tiermint_line['price_name'] ) : ?>
											<span class="tiermint-checkout__line-tier"><?php echo esc_html( $tiermint_line['price_name'] ); ?></span>
										<?php endif; ?>
									</span>
									<?php // A read-only summary, or a fixed-single line with no editable quantity, shows the figure as a badge. ?>
									<?php if ( ! $tiermint_line_editable ) : ?>
										<span class="tiermint-checkout__line-qty tiermint-badge">
											<?php
											printf(
												/* translators: %d: quantity of one cart line. */
												esc_html__( 'x %d', 'tiermint' ),
												(int) $tiermint_line['quantity']
											);
											?>
										</span>
									<?php endif; ?>
									<span class="tiermint-checkout__line-amount"><?php echo esc_html( $tiermint_line['subtotal'] ); ?></span>
									<?php
									// The plan-detail meta lines repeat the tier facts from the product page.
									// They drop to their own full-width row beneath the name and amount, above
									// any edit controls. Empty when the tier is gone or the toggle is off.
									$tiermint_line_meta = ( ! empty( $data['show_plan_details'] ) && ! empty( $tiermint_line['meta'] ) && is_array( $tiermint_line['meta'] ) )
										? $tiermint_line['meta']
										: array();
									?>
									<?php if ( array() !== $tiermint_line_meta ) : ?>
										<ul class="tiermint-checkout__line-meta">
											<?php foreach ( $tiermint_line_meta as $tiermint_meta_line ) : ?>
												<li class="tiermint-checkout__line-meta-item"><?php echo esc_html( (string) $tiermint_meta_line ); ?></li>
											<?php endforeach; ?>
										</ul>
									<?php endif; ?>
									<?php
									if ( $tiermint_summary_editable ) {
										$tiermint_line_controls = array(
											'product_id'   => $tiermint_line['product_id'] ?? 0,
											'price_id'     => $tiermint_line['price_id'] ?? 0,
											'quantity'     => $tiermint_line['quantity'] ?? 1,
											'min'          => $tiermint_line['min'] ?? 1,
											'max'          => $tiermint_line['max'] ?? null,
											'fixed_single' => ! empty( $tiermint_line['fixed_single'] ),
											'action_url'   => $data['action_url'] ?? '',
											// Scope the control id to this surface so a page that also carries a
											// cart-summary block does not emit the same quantity-input id twice.
											'id'           => 'checkout-' . ( $tiermint_line['product_id'] ?? 0 ) . '-' . ( $tiermint_line['price_id'] ?? 0 ),
										);
										require TIERMINT_PATH . 'templates/components/partials/cart-line-controls.php';
									}
									?>
								</li>
							<?php endforeach; ?>
						</ul>
						<?php
						// A stored valid discount code renders its line server-side, in the
						// exact node shape the checkout module's writeDiscount() builds, so
						// the JS updates or removes this line rather than duplicating it. It
						// carries data-discount-minor so reconcileTotal can attribute a
						// discount that dies before payment.
						$tiermint_discount = isset( $data['discount'] ) && is_array( $data['discount'] ) ? $data['discount'] : array();
						?>
						<?php if ( 'applied' === ( $tiermint_discount['state'] ?? '' ) ) : ?>
							<p class="tiermint-checkout__discount-line" data-tiermint-discount-line data-discount-minor="<?php echo esc_attr( (string) ( $tiermint_discount['amount_minor'] ?? 0 ) ); ?>">
								<span><?php echo esc_html( (string) ( $tiermint_discount['label'] ?? '' ) ); ?></span>
								<span data-tiermint-discount-amount>-<?php echo esc_html( (string) ( $tiermint_discount['amount'] ?? '' ) ); ?></span>
							</p>
						<?php endif; ?>
						<?php
						// The automatic (no-code) discount rides its own line, right after the code
						// line, in the node shape the module's writeAutoDiscount() builds. It carries
						// its own data-discount-minor so reconcileTotal can sum both reductions.
						$tiermint_auto = isset( $tiermint_discount['auto'] ) && is_array( $tiermint_discount['auto'] ) ? $tiermint_discount['auto'] : array();
						?>
						<?php if ( ! empty( $tiermint_auto['applied'] ) ) : ?>
							<p class="tiermint-checkout__discount-line" data-tiermint-auto-discount-line data-discount-minor="<?php echo esc_attr( (string) ( $tiermint_auto['amount_minor'] ?? 0 ) ); ?>">
								<span><?php echo esc_html( (string) ( $tiermint_auto['label'] ?? '' ) ); ?></span>
								<span data-tiermint-auto-discount-amount>-<?php echo esc_html( (string) ( $tiermint_auto['amount'] ?? '' ) ); ?></span>
							</p>
						<?php endif; ?>
						<p
							class="tiermint-checkout__tax"
							data-tiermint-tax-line
							data-tax-minor="<?php echo esc_attr( (string) ( $data['tax_minor'] ?? 0 ) ); ?>"
							<?php echo empty( $data['tax_minor'] ) ? 'hidden' : ''; ?>
						>
							<span><?php echo esc_html( $data['tax_label'] ?? __( 'Tax', 'tiermint' ) ); ?></span>
							<strong data-tiermint-tax-amount><?php echo esc_html( (string) ( $data['tax'] ?? '' ) ); ?></strong>
						</p>
						<p class="tiermint-checkout__total">
							<span><?php esc_html_e( 'Total', 'tiermint' ); ?></span>
							<strong data-total-minor="<?php echo esc_attr( (string) ( $data['total_minor'] ?? '' ) ); ?>"><?php echo esc_html( $data['total'] ); ?></strong>
						</p>
					</section>
					<?php if ( $tiermint_show_discount ) : ?>
						<?php require TIERMINT_PATH . 'templates/components/partials/checkout-discount.php'; ?>
					<?php endif; ?>
				</div>
			</div><?php // .tiermint-checkout__columns ?>
			<?php endif; ?>
			<?php // No aside to host the discount section: it falls to a sibling right after the form. ?>
			<?php if ( ! $tiermint_has_aside && $tiermint_show_discount ) : ?>
				<?php require TIERMINT_PATH . 'templates/components/partials/checkout-discount.php'; ?>
			<?php endif; ?>
		<?php endif; ?>
	</div>
</div>
