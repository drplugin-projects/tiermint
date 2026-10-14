<?php
/**
 * Registration form template.
 *
 * @package DrPlugin\WP\TierMint
 *
 * @var array<string, mixed> $data {
 *     State-specific view data.
 *
 *     @type string       $state          Form state.
 *     @type string|null  $account_url    Account URL.
 *     @type string|null  $login_url      Sign-in URL.
 *     @type array         $error          Error data.
 *     @type bool          $show_name      Whether to show the name field.
 *     @type string        $nonce_field    Nonce field markup.
 *     @type string        $action_field   Hidden action field name.
 *     @type string        $email_field    Email field name.
 *     @type string        $name_field     Name field name.
 *     @type string        $honeypot_field Honeypot field name.
 * }
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="tiermint-component tiermint-registration-form">
	<?php // Keep the card width independent from the theme content column. ?>
	<div class="tiermint-registration-form__card tiermint-card">
	<?php if ( 'signed_in' === $data['state'] ) : ?>

		<div class="tiermint-registration-form__signed-in">
			<p><?php echo esc_html__( 'You are already signed in.', 'tiermint' ); ?></p>
			<?php if ( null !== $data['account_url'] ) : ?>
				<a class="tiermint-registration-form__account" href="<?php echo esc_url( $data['account_url'] ); ?>">
					<?php echo esc_html__( 'Go to your account', 'tiermint' ); ?>
				</a>
			<?php endif; ?>
		</div>

	<?php elseif ( 'closed' === $data['state'] ) : ?>

		<p class="tiermint-registration-form__closed">
			<?php echo esc_html__( 'Registration is currently closed.', 'tiermint' ); ?>
		</p>

	<?php elseif ( 'success' === $data['state'] ) : ?>

		<div class="tiermint-registration-form__success">
			<p><?php echo esc_html__( 'Thanks for registering. Check your email for a link to set your password.', 'tiermint' ); ?></p>
			<?php if ( null !== $data['login_url'] ) : ?>
				<a href="<?php echo esc_url( $data['login_url'] ); ?>">
					<?php echo esc_html__( 'Go to sign in', 'tiermint' ); ?>
				</a>
			<?php endif; ?>
		</div>

	<?php else : ?>

		<?php if ( $data['error']['show'] ) : ?>
			<p class="tiermint-registration-form__error tiermint-notice tiermint-notice--error" role="alert">
				<?php echo esc_html( $data['error']['text'] ); ?>
			</p>
		<?php endif; ?>

		<form class="tiermint-registration-form__form" method="post">
			<?php
			// wp_nonce_field() returns core-generated, self-escaping hidden inputs.
			echo $data['nonce_field']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core-generated nonce markup.
			?>
			<input type="hidden" name="<?php echo esc_attr( $data['action_field'] ); ?>" value="1" />

			<p class="tiermint-registration-form__field tiermint-field">
				<label for="tiermint-register-email"><?php echo esc_html__( 'Email address', 'tiermint' ); ?></label>
				<input type="email" class="tiermint-input" id="tiermint-register-email" name="<?php echo esc_attr( $data['email_field'] ); ?>" required autocomplete="email" />
			</p>

			<?php if ( $data['show_name'] ) : ?>
				<p class="tiermint-registration-form__field tiermint-field">
					<label for="tiermint-register-name"><?php echo esc_html__( 'Name (optional)', 'tiermint' ); ?></label>
					<input type="text" class="tiermint-input" id="tiermint-register-name" name="<?php echo esc_attr( $data['name_field'] ); ?>" autocomplete="name" />
				</p>
			<?php endif; ?>

			<?php
			// The honeypot: hidden from people, tempting to bots. A real visitor
			// never sees or fills it; a non-empty value on submit is discarded
			// server-side. aria-hidden and tabindex keep it out of the a11y tree
			// and the tab order.
			?>
			<div class="tiermint-registration-form__hp" aria-hidden="true">
				<label for="tiermint-register-website"><?php echo esc_html__( 'Leave this field empty', 'tiermint' ); ?></label>
				<input type="text" id="tiermint-register-website" name="<?php echo esc_attr( $data['honeypot_field'] ); ?>" tabindex="-1" autocomplete="off" />
			</div>

			<p class="tiermint-registration-form__submit">
				<button type="submit" class="tiermint-button tiermint-button--primary"><?php echo esc_html__( 'Create account', 'tiermint' ); ?></button>
			</p>
		</form>

		<?php if ( null !== $data['login_url'] ) : ?>
			<p class="tiermint-registration-form__sign-in">
				<a href="<?php echo esc_url( $data['login_url'] ); ?>">
					<?php echo esc_html__( 'Already have an account? Sign in', 'tiermint' ); ?>
				</a>
			</p>
		<?php endif; ?>

	<?php endif; ?>
	</div>
</div>
