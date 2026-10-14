<?php
/**
 * Checkout discount controls.
 *
 * The forms work without JavaScript.
 *
 * @package DrPlugin\WP\TierMint
 */

defined( 'ABSPATH' ) || exit;

/* translators: %s: the discount code the buyer applied. */
$tiermint_discount_applied_tpl = __( 'Code %s applied', 'tiermint' );

$tiermint_discount       = isset( $data['discount'] ) && is_array( $data['discount'] ) ? $data['discount'] : array();
$tiermint_discount_state = (string) ( $tiermint_discount['state'] ?? '' );
$tiermint_is_applied     = 'applied' === $tiermint_discount_state;
$tiermint_is_invalid     = 'invalid' === $tiermint_discount_state;
$tiermint_discount_code  = (string) ( $tiermint_discount['code'] ?? '' );
$tiermint_discount_error = (string) ( $tiermint_discount['error'] ?? '' );
$tiermint_action_url     = (string) ( $data['action_url'] ?? '' );

?>
<section
	class="tiermint-checkout__discount tiermint-card"
	aria-label="<?php esc_attr_e( 'Discount code', 'tiermint' ); ?>"
	data-msg-applied="<?php echo esc_attr( $tiermint_discount_applied_tpl ); ?>"
	data-msg-error="<?php esc_attr_e( 'The discount could not be applied. Please try again.', 'tiermint' ); ?>"
>
	<div class="tiermint-checkout__discount-field tiermint-field">
		<label for="tiermint-checkout-discount"><?php esc_html_e( 'Discount code', 'tiermint' ); ?></label>
		<?php // A normal POST keeps Apply working without JavaScript. ?>
		<form class="tiermint-checkout__discount-apply-form" method="post" action="<?php echo esc_url( $tiermint_action_url ); ?>">
			<input type="hidden" name="tiermint_cart_action" value="apply_discount">
			<?php echo wp_nonce_field( \DrPlugin\WP\TierMint\Frontend\CartActionListener::NONCE_ACTION, \DrPlugin\WP\TierMint\Frontend\CartActionListener::NONCE_FIELD, true, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field() returns escaped markup. ?>
			<div class="tiermint-checkout__discount-row"<?php echo $tiermint_is_applied ? ' hidden' : ''; ?>>
				<input
					type="text"
					class="tiermint-input" id="tiermint-checkout-discount"
					name="discount_code"
					autocomplete="off"
					autocapitalize="characters"
					spellcheck="false"
					maxlength="50"
				>
				<button type="submit" class="tiermint-checkout__discount-apply tiermint-button tiermint-button--secondary" data-tiermint-discount-apply>
					<?php esc_html_e( 'Apply', 'tiermint' ); ?>
				</button>
			</div>
		</form>
		<?php // Removing a code uses a separate PRG form. ?>
		<div class="tiermint-checkout__discount-chip" data-tiermint-discount-chip<?php echo $tiermint_is_applied ? '' : ' hidden'; ?>>
			<span class="tiermint-checkout__discount-chip-label" data-tiermint-discount-chip-label>
				<?php
				if ( $tiermint_is_applied ) {
					echo esc_html( sprintf( $tiermint_discount_applied_tpl, $tiermint_discount_code ) );
				}
				?>
			</span>
			<form class="tiermint-checkout__discount-remove-form" method="post" action="<?php echo esc_url( $tiermint_action_url ); ?>">
				<input type="hidden" name="tiermint_cart_action" value="remove_discount">
				<?php echo wp_nonce_field( \DrPlugin\WP\TierMint\Frontend\CartActionListener::NONCE_ACTION, \DrPlugin\WP\TierMint\Frontend\CartActionListener::NONCE_FIELD, true, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field() returns escaped markup. ?>
				<button type="submit" class="tiermint-checkout__discount-remove tiermint-button tiermint-button--ghost tiermint-button--small" data-tiermint-discount-remove>
					<?php esc_html_e( 'Remove', 'tiermint' ); ?>
				</button>
			</form>
		</div>
		<p class="tiermint-checkout__discount-error" data-tiermint-discount-error role="alert"<?php echo $tiermint_is_invalid ? '' : ' hidden'; ?>><?php echo $tiermint_is_invalid ? esc_html( $tiermint_discount_error ) : ''; ?></p>
	</div>
	<noscript>
		<p class="tiermint-checkout__discount-note"><?php esc_html_e( 'You can apply or remove a discount code without JavaScript. Completing payment still requires JavaScript.', 'tiermint' ); ?></p>
	</noscript>
</section>
