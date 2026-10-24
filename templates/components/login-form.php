<?php
/**
 * Login form template.
 *
 * Theme override: {theme}/tiermint/components/login-form.php.
 *
 * @package DrPlugin\WP\TierMint
 *
 * @var array<string, mixed> $data {
 *     @type bool $logged_in Login state.
 *     @type string $display_name Display name.
 *     @type string|null $account_url Account link.
 *     @type string $logout_url Logout link.
 *     @type bool $login_error Show sign-in error.
 *     @type string $login_form Login markup.
 *     @type string $lostpassword_url Password-reset link.
 *     @type string|null $registration_url Registration link.
 * }
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="tiermint-component tiermint-login-form">
	<?php // Keep the width cap outside the theme content column. ?>
	<div class="tiermint-login-form__card tiermint-card">
	<?php if ( $data['logged_in'] ) : ?>

		<div class="tiermint-login-form--logged-in">
			<p class="tiermint-login-form__greeting">
				<?php
				printf(
					/* translators: %s: the logged-in visitor's display name. */
					esc_html__( 'Logged in as %s.', 'tiermint' ),
					esc_html( $data['display_name'] )
				);
				?>
			</p>
			<?php if ( null !== $data['account_url'] ) : ?>
				<a class="tiermint-login-form__account" href="<?php echo esc_url( $data['account_url'] ); ?>">
					<?php echo esc_html__( 'Your account', 'tiermint' ); ?>
				</a>
			<?php endif; ?>
			<a class="tiermint-login-form__logout" href="<?php echo esc_url( $data['logout_url'] ); ?>">
				<?php echo esc_html__( 'Log out', 'tiermint' ); ?>
			</a>
		</div>

	<?php else : ?>

		<?php if ( $data['login_error'] ) : ?>
			<p class="tiermint-login-form__error tiermint-notice tiermint-notice--error" role="alert">
				<?php echo esc_html__( 'Sign-in failed. Check your username and password and try again.', 'tiermint' ); ?>
			</p>
		<?php endif; ?>

		<?php
		// The form subset keeps the login fields and plain form markup, and drops scripts and handlers.
		echo wp_kses( $data['login_form'], \DrPlugin\WP\TierMint\Frontend\Kses::form() );
		?>

		<p class="tiermint-login-form__lost-password">
			<a href="<?php echo esc_url( $data['lostpassword_url'] ); ?>">
				<?php echo esc_html__( 'Lost your password?', 'tiermint' ); ?>
			</a>
		</p>

		<?php if ( null !== $data['registration_url'] ) : ?>
			<p class="tiermint-login-form__register">
				<a href="<?php echo esc_url( $data['registration_url'] ); ?>">
					<?php echo esc_html__( 'Create an account', 'tiermint' ); ?>
				</a>
			</p>
		<?php endif; ?>

	<?php endif; ?>
	</div>
</div>
