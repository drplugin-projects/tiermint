<?php
/**
 * Shared cart notice markup.
 *
 * The notice helper supplies safe messages for all purchase templates.
 *
 * @package DrPlugin\WP\TierMint
 *
 * @var array{success: array{show: bool, text: string, checkout_url: string|null}, error: array{show: bool, text: string}} $tiermint_notices Notice data.
 */

defined( 'ABSPATH' ) || exit;

$tiermint_notices = ( isset( $tiermint_notices ) && is_array( $tiermint_notices ) ) ? $tiermint_notices : array();
$tiermint_success = ( isset( $tiermint_notices['success'] ) && is_array( $tiermint_notices['success'] ) ) ? $tiermint_notices['success'] : array( 'show' => false );
$tiermint_error   = ( isset( $tiermint_notices['error'] ) && is_array( $tiermint_notices['error'] ) ) ? $tiermint_notices['error'] : array( 'show' => false );
?>
<?php if ( ! empty( $tiermint_success['show'] ) ) : ?>
	<div class="tiermint-cart-notice tiermint-cart-notice--success" role="status">
		<p class="tiermint-cart-notice__text"><?php echo esc_html( (string) ( $tiermint_success['text'] ?? '' ) ); ?></p>
		<?php if ( ! empty( $tiermint_success['checkout_url'] ) ) : ?>
			<a class="tiermint-cart-notice__checkout" href="<?php echo esc_url( (string) $tiermint_success['checkout_url'] ); ?>"><?php echo esc_html__( 'Go to checkout', 'tiermint' ); ?></a>
		<?php endif; ?>
	</div>
<?php endif; ?>
<?php if ( ! empty( $tiermint_error['show'] ) ) : ?>
	<div class="tiermint-cart-notice tiermint-cart-notice--error" role="alert">
		<p class="tiermint-cart-notice__text"><?php echo esc_html( (string) ( $tiermint_error['text'] ?? '' ) ); ?></p>
	</div>
<?php endif; ?>
