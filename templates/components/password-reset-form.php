<?php
/**
 * Password reset form template.
 *
 * Theme override: {theme}/tiermint/components/password-reset-form.php.
 *
 * @package DrPlugin\WP\TierMint
 *
 * @var array<string, mixed> $data {
 *     State-specific view data.
 *
 *     @type string       $state          One of done|reset|sent|signed_in|request.
 *     @type string       $login_url      Sign-in URL (done, sent).
 *     @type string|null  $account_url    Account URL (signed_in).
 *     @type array         $error          Error notice data (reset, request).
 *     @type string        $login          The account login (reset).
 *     @type string        $rp_key         The reset key (reset).
 *     @type int           $min_length     Minimum password length (reset).
 *     @type string        $nonce_action   Nonce action (reset, request).
 *     @type string        $nonce_field    Nonce field name (reset, request).
 *     @type string        $action_field   Hidden action field name (reset, request).
 *     @type string        $key_field      Reset-key field name (reset).
 *     @type string        $pass1_field    New-password field name (reset).
 *     @type string        $pass2_field    Confirm-password field name (reset).
 *     @type string        $login_field    Email/username field name (request).
 *     @type string        $honeypot_field Honeypot field name (request).
 * }
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="tiermint-component tiermint-password-reset-form">
	<?php // Keep the card width independent from the theme content column. ?>
	<div class="tiermint-password-reset-form__card tiermint-card">
	<?php if ( 'done' === $data['state'] ) : ?>

		<div class="tiermint-password-reset-form__done">
			<p><?php echo esc_html__( 'Your password has been reset.', 'tiermint' ); ?></p>
			<a class="tiermint-password-reset-form__sign-in" href="<?php echo esc_url( $data['login_url'] ); ?>">
				<?php echo esc_html__( 'Sign in', 'tiermint' ); ?>
			</a>
		</div>

	<?php elseif ( 'sent' === $data['state'] ) : ?>

		<div class="tiermint-password-reset-form__sent">
			<p><?php echo esc_html__( 'If an account exists for that address, we have emailed a link to reset your password. The link expires in 24 hours.', 'tiermint' ); ?></p>
			<a class="tiermint-password-reset-form__sign-in" href="<?php echo esc_url( $data['login_url'] ); ?>">
				<?php echo esc_html__( 'Back to sign in', 'tiermint' ); ?>
			</a>
		</div>

	<?php elseif ( 'signed_in' === $data['state'] ) : ?>

		<div class="tiermint-password-reset-form__signed-in">
			<p><?php echo esc_html__( 'You are signed in.', 'tiermint' ); ?></p>
			<?php if ( null !== $data['account_url'] ) : ?>
				<a class="tiermint-password-reset-form__account" href="<?php echo esc_url( $data['account_url'] ); ?>">
					<?php echo esc_html__( 'Go to your account', 'tiermint' ); ?>
				</a>
			<?php endif; ?>
		</div>

	<?php elseif ( 'reset' === $data['state'] ) : ?>

		<?php if ( $data['error']['show'] ) : ?>
			<p class="tiermint-password-reset-form__error tiermint-notice tiermint-notice--error" role="alert">
				<?php echo esc_html( $data['error']['text'] ); ?>
			</p>
		<?php endif; ?>

		<form class="tiermint-password-reset-form__form" method="post" autocomplete="on">
			<?php wp_nonce_field( $data['nonce_action'], $data['nonce_field'] ); ?>
			<input type="hidden" name="<?php echo esc_attr( $data['action_field'] ); ?>" value="1" />
			<input type="hidden" name="<?php echo esc_attr( $data['key_field'] ); ?>" value="<?php echo esc_attr( $data['rp_key'] ); ?>" />

			<?php // A hidden username so password managers save the credential against the account. ?>
			<input type="text" class="tiermint-password-reset-form__username" name="username" value="<?php echo esc_attr( $data['login'] ); ?>" autocomplete="username" readonly hidden aria-hidden="true" tabindex="-1" />

			<p class="tiermint-password-reset-form__field tiermint-field">
				<label for="tiermint-reset-pass1"><?php echo esc_html__( 'New password', 'tiermint' ); ?></label>
				<input type="password" class="tiermint-input" id="tiermint-reset-pass1" name="<?php echo esc_attr( $data['pass1_field'] ); ?>" autocomplete="new-password" minlength="<?php echo esc_attr( (string) $data['min_length'] ); ?>" required />
			</p>

			<p class="tiermint-password-reset-form__field tiermint-field">
				<label for="tiermint-reset-pass2"><?php echo esc_html__( 'Confirm new password', 'tiermint' ); ?></label>
				<input type="password" class="tiermint-input" id="tiermint-reset-pass2" name="<?php echo esc_attr( $data['pass2_field'] ); ?>" autocomplete="new-password" minlength="<?php echo esc_attr( (string) $data['min_length'] ); ?>" required />
			</p>

			<p class="tiermint-password-reset-form__submit">
				<button type="submit" class="tiermint-button tiermint-button--primary"><?php echo esc_html__( 'Save password', 'tiermint' ); ?></button>
			</p>
		</form>

	<?php else : ?>

		<?php if ( $data['error']['show'] ) : ?>
			<p class="tiermint-password-reset-form__error tiermint-notice tiermint-notice--error" role="alert">
				<?php echo esc_html( $data['error']['text'] ); ?>
			</p>
		<?php endif; ?>

		<p class="tiermint-password-reset-form__intro">
			<?php echo esc_html__( 'Enter your email address or username and we will email you a link to reset your password.', 'tiermint' ); ?>
		</p>

		<form class="tiermint-password-reset-form__form" method="post">
			<?php wp_nonce_field( $data['nonce_action'], $data['nonce_field'] ); ?>
			<input type="hidden" name="<?php echo esc_attr( $data['action_field'] ); ?>" value="1" />

			<p class="tiermint-password-reset-form__field tiermint-field">
				<label for="tiermint-reset-login"><?php echo esc_html__( 'Email address or username', 'tiermint' ); ?></label>
				<input type="text" class="tiermint-input" id="tiermint-reset-login" name="<?php echo esc_attr( $data['login_field'] ); ?>" autocomplete="username" maxlength="254" required />
			</p>

			<?php
			// The honeypot: hidden from people, tempting to bots. A non-empty value on
			// submit is treated as a bot and silently faked as success server-side.
			?>
			<div class="tiermint-password-reset-form__hp" aria-hidden="true">
				<label for="tiermint-reset-website"><?php echo esc_html__( 'Leave this field empty', 'tiermint' ); ?></label>
				<input type="text" id="tiermint-reset-website" name="<?php echo esc_attr( $data['honeypot_field'] ); ?>" tabindex="-1" autocomplete="off" />
			</div>

			<p class="tiermint-password-reset-form__submit">
				<button type="submit" class="tiermint-button tiermint-button--primary"><?php echo esc_html__( 'Send reset link', 'tiermint' ); ?></button>
			</p>
		</form>

	<?php endif; ?>
	</div>
</div>
