<?php
/**
 * Buy button template.
 *
 * Theme override: {theme}/tiermint/components/buy-button.php.
 *
 * @package DrPlugin\WP\TierMint
 *
 * @var array{action_url: string, product_id: int, price_id: int, quantity: int, label: string, price_html: string, nonce_action: string, nonce_field: string} $data View data.
 */

defined( 'ABSPATH' ) || exit;

$tiermint_notices = \DrPlugin\WP\TierMint\Frontend\CartNotices::view();
?>
<div class="tiermint-component tiermint-buy-button">
	<?php require TIERMINT_PATH . 'templates/components/partials/cart-notice.php'; ?>
	<form class="tiermint-buy-button__form" method="post" action="<?php echo esc_url( $data['action_url'] ); ?>">
		<input type="hidden" name="tiermint_cart_action" value="add">
		<input type="hidden" name="product_id" value="<?php echo esc_attr( (string) $data['product_id'] ); ?>">
		<input type="hidden" name="price_id" value="<?php echo esc_attr( (string) $data['price_id'] ); ?>">
		<input type="hidden" name="quantity" value="<?php echo esc_attr( (string) ( $data['quantity'] ?? 1 ) ); ?>">
		<?php echo wp_nonce_field( $data['nonce_action'], $data['nonce_field'], true, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field() returns escaped markup. ?>
		<button type="submit" class="tiermint-buy-button__button tiermint-button tiermint-button--primary">
			<span class="tiermint-buy-button__label"><?php echo esc_html( $data['label'] ); ?></span>
			<?php if ( '' !== $data['price_html'] ) : ?>
				<span class="tiermint-buy-button__price"><?php echo $data['price_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- PriceDisplay returns markup it has already escaped. ?></span>
			<?php endif; ?>
		</button>
	</form>
</div>
