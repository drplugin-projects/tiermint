<?php
/**
 * Cart line controls for cart and checkout.
 *
 * @package DrPlugin\WP\TierMint
 *
 * @var array{
 *     product_id: int,
 *     price_id: int,
 *     quantity: int,
 *     min: int,
 *     max: int|null,
 *     fixed_single: bool,
 *     action_url: string,
 *     id?: string
 * } $tiermint_line_controls Line-control data supplied by the including template.
 */

defined( 'ABSPATH' ) || exit;

$tiermint_line_controls = ( isset( $tiermint_line_controls ) && is_array( $tiermint_line_controls ) ) ? $tiermint_line_controls : array();

$tiermint_lc_product = (int) ( $tiermint_line_controls['product_id'] ?? 0 );
$tiermint_lc_price   = (int) ( $tiermint_line_controls['price_id'] ?? 0 );
$tiermint_lc_qty     = (int) ( $tiermint_line_controls['quantity'] ?? 1 );
$tiermint_lc_min     = (int) ( $tiermint_line_controls['min'] ?? 1 );
$tiermint_lc_max     = ( array_key_exists( 'max', $tiermint_line_controls ) && null !== $tiermint_line_controls['max'] ) ? (int) $tiermint_line_controls['max'] : null;
$tiermint_lc_fixed   = ! empty( $tiermint_line_controls['fixed_single'] );
$tiermint_lc_action  = (string) ( $tiermint_line_controls['action_url'] ?? '' );
$tiermint_lc_id      = (string) ( $tiermint_line_controls['id'] ?? ( $tiermint_lc_product . '-' . $tiermint_lc_price ) );
?>
<div class="tiermint-cart-line-controls">
	<?php
	// Single-unit tiers have no quantity control.
	?>
	<?php if ( ! $tiermint_lc_fixed ) : ?>
		<form method="post" action="<?php echo esc_url( $tiermint_lc_action ); ?>" class="tiermint-cart-line-controls__qty-form">
			<input type="hidden" name="tiermint_cart_action" value="update">
			<input type="hidden" name="product_id" value="<?php echo esc_attr( (string) $tiermint_lc_product ); ?>">
			<input type="hidden" name="price_id" value="<?php echo esc_attr( (string) $tiermint_lc_price ); ?>">
			<label class="screen-reader-text" for="tiermint-cart-qty-<?php echo esc_attr( $tiermint_lc_id ); ?>">
				<?php echo esc_html__( 'Quantity', 'tiermint' ); ?>
			</label>
			<input
				type="number"
				class="tiermint-input tiermint-checkout__line-qty-input"
				id="tiermint-cart-qty-<?php echo esc_attr( $tiermint_lc_id ); ?>"
				name="quantity"
				value="<?php echo esc_attr( (string) $tiermint_lc_qty ); ?>"
				min="<?php echo esc_attr( (string) $tiermint_lc_min ); ?>"
				<?php if ( null !== $tiermint_lc_max ) : ?>
					max="<?php echo esc_attr( (string) $tiermint_lc_max ); ?>"
				<?php endif; ?>
			>
			<?php wp_nonce_field( \DrPlugin\WP\TierMint\Frontend\CartActionListener::NONCE_ACTION, \DrPlugin\WP\TierMint\Frontend\CartActionListener::NONCE_FIELD ); ?>
			<button type="submit" class="tiermint-cart-line-controls__update tiermint-button tiermint-button--secondary tiermint-button--small"><?php echo esc_html__( 'Update', 'tiermint' ); ?></button>
		</form>
	<?php endif; ?>
	<form method="post" action="<?php echo esc_url( $tiermint_lc_action ); ?>" class="tiermint-cart-line-controls__remove-form">
		<input type="hidden" name="tiermint_cart_action" value="remove">
		<input type="hidden" name="product_id" value="<?php echo esc_attr( (string) $tiermint_lc_product ); ?>">
		<input type="hidden" name="price_id" value="<?php echo esc_attr( (string) $tiermint_lc_price ); ?>">
		<?php wp_nonce_field( \DrPlugin\WP\TierMint\Frontend\CartActionListener::NONCE_ACTION, \DrPlugin\WP\TierMint\Frontend\CartActionListener::NONCE_FIELD ); ?>
		<button type="submit" class="tiermint-checkout__line-remove tiermint-button tiermint-button--danger tiermint-button--small"><?php echo esc_html__( 'Remove', 'tiermint' ); ?></button>
	</form>
</div>
