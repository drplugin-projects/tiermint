<?php
/**
 * Purchase form shared by product templates.
 *
 * @package DrPlugin\WP\TierMint
 *
 * @var array{
 *     block: string,
 *     tiers: array<int, array{id: int, name: string, price_html: string, is_default: bool, min: int, max: int|null, fixed_single: bool, meta: array<int, string>}>,
 *     has_tiers: bool,
 *     quantity: array{show: bool, min: int, max: int|null, value: int},
 *     product_id: int,
 *     action_url: string,
 *     nonce_action: string,
 *     nonce_field: string,
 *     add_label: string
 * } $tiermint_form Purchase-form data supplied by the including template.
 */

defined( 'ABSPATH' ) || exit;

$tiermint_form = ( isset( $tiermint_form ) && is_array( $tiermint_form ) ) ? $tiermint_form : array();

if ( empty( $tiermint_form['has_tiers'] ) ) {
	return;
}

$tiermint_block       = (string) ( $tiermint_form['block'] ?? 'tiermint-product-pricing-tiers' );
$tiermint_tiers       = ( isset( $tiermint_form['tiers'] ) && is_array( $tiermint_form['tiers'] ) ) ? $tiermint_form['tiers'] : array();
$tiermint_quantity    = ( isset( $tiermint_form['quantity'] ) && is_array( $tiermint_form['quantity'] ) ) ? $tiermint_form['quantity'] : array( 'show' => false );
$tiermint_quantity_id = $tiermint_block . '-quantity';

$tiermint_notices = \DrPlugin\WP\TierMint\Frontend\CartNotices::view();
require TIERMINT_PATH . 'templates/components/partials/cart-notice.php';
?>
<form class="<?php echo esc_attr( $tiermint_block ); ?>__purchase" method="post" action="<?php echo esc_url( (string) $tiermint_form['action_url'] ); ?>">
	<input type="hidden" name="tiermint_cart_action" value="add" />
	<input type="hidden" name="product_id" value="<?php echo esc_attr( (string) $tiermint_form['product_id'] ); ?>" />

	<?php if ( 1 === count( $tiermint_tiers ) ) : ?>
		<?php
		$tiermint_only = $tiermint_tiers[0];
		$tiermint_meta = ( isset( $tiermint_only['meta'] ) && is_array( $tiermint_only['meta'] ) ) ? $tiermint_only['meta'] : array();
		?>
		<input type="hidden" name="price_id" value="<?php echo esc_attr( (string) $tiermint_only['id'] ); ?>" />
		<p class="<?php echo esc_attr( $tiermint_block ); ?>__price">
			<span class="<?php echo esc_attr( $tiermint_block ); ?>__tier-name"><?php echo esc_html( (string) $tiermint_only['name'] ); ?></span>
			<span class="<?php echo esc_attr( $tiermint_block ); ?>__tier-price"><?php echo wp_kses( $tiermint_only['price_html'], \DrPlugin\WP\TierMint\Frontend\Kses::allowed() ); ?></span>
		</p>
		<?php if ( array() !== $tiermint_meta ) : ?>
			<ul class="<?php echo esc_attr( $tiermint_block ); ?>__tier-meta">
				<?php foreach ( $tiermint_meta as $tiermint_line ) : ?>
					<li class="<?php echo esc_attr( $tiermint_block ); ?>__tier-meta-item"><?php echo esc_html( (string) $tiermint_line ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	<?php else : ?>
		<fieldset class="<?php echo esc_attr( $tiermint_block ); ?>__tiers">
			<legend class="<?php echo esc_attr( $tiermint_block ); ?>__tiers-legend"><?php echo esc_html__( 'Choose a plan', 'tiermint' ); ?></legend>
			<?php foreach ( $tiermint_tiers as $tiermint_tier ) : ?>
				<?php
				$tiermint_tier_id  = $tiermint_block . '-tier-' . (string) $tiermint_tier['id'];
				$tiermint_meta     = ( isset( $tiermint_tier['meta'] ) && is_array( $tiermint_tier['meta'] ) ) ? $tiermint_tier['meta'] : array();
				$tiermint_meta_id  = $tiermint_tier_id . '-meta';
				$tiermint_has_meta = array() !== $tiermint_meta;
				?>
				<div class="<?php echo esc_attr( $tiermint_block ); ?>__tier">
					<input
						type="radio"
						id="<?php echo esc_attr( $tiermint_tier_id ); ?>"
						name="price_id"
						value="<?php echo esc_attr( (string) $tiermint_tier['id'] ); ?>"<?php checked( (bool) $tiermint_tier['is_default'], true ); ?>
						data-min="<?php echo esc_attr( (string) $tiermint_tier['min'] ); ?>"
						data-max="<?php echo esc_attr( null === $tiermint_tier['max'] ? '0' : (string) $tiermint_tier['max'] ); ?>"
						data-fixed="<?php echo esc_attr( $tiermint_tier['fixed_single'] ? '1' : '0' ); ?>"
						<?php echo $tiermint_has_meta ? 'aria-describedby="' . esc_attr( $tiermint_meta_id ) . '"' : ''; ?>
					/>
					<label class="<?php echo esc_attr( $tiermint_block ); ?>__tier-label" for="<?php echo esc_attr( $tiermint_tier_id ); ?>">
						<span class="<?php echo esc_attr( $tiermint_block ); ?>__tier-name"><?php echo esc_html( (string) $tiermint_tier['name'] ); ?></span>
						<span class="<?php echo esc_attr( $tiermint_block ); ?>__tier-price"><?php echo wp_kses( $tiermint_tier['price_html'], \DrPlugin\WP\TierMint\Frontend\Kses::allowed() ); ?></span>
					</label>
					<?php if ( $tiermint_has_meta ) : ?>
						<ul class="<?php echo esc_attr( $tiermint_block ); ?>__tier-meta" id="<?php echo esc_attr( $tiermint_meta_id ); ?>">
							<?php foreach ( $tiermint_meta as $tiermint_line ) : ?>
								<li class="<?php echo esc_attr( $tiermint_block ); ?>__tier-meta-item"><?php echo esc_html( (string) $tiermint_line ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</fieldset>
	<?php endif; ?>

	<?php
	// Fixed single-unit tiers show plain text. The hidden input still posts one.
	?>
	<div class="<?php echo esc_attr( $tiermint_block ); ?>__quantity"<?php echo ( empty( $tiermint_quantity['show'] ) ) ? ' hidden' : ''; ?>>
		<label class="<?php echo esc_attr( $tiermint_block ); ?>__quantity-label" for="<?php echo esc_attr( $tiermint_quantity_id ); ?>"><?php echo esc_html__( 'Quantity', 'tiermint' ); ?></label>
		<input
			type="number"
			class="tiermint-input"
			id="<?php echo esc_attr( $tiermint_quantity_id ); ?>"
			name="quantity"
			value="<?php echo esc_attr( (string) ( $tiermint_quantity['value'] ?? 1 ) ); ?>"
			min="<?php echo esc_attr( (string) ( $tiermint_quantity['min'] ?? 1 ) ); ?>"
			<?php if ( isset( $tiermint_quantity['max'] ) && null !== $tiermint_quantity['max'] ) : ?>
				max="<?php echo esc_attr( (string) $tiermint_quantity['max'] ); ?>"
			<?php endif; ?>
		/>
	</div>

	<?php // Fixed single-unit tiers show this instead of a quantity control. ?>
	<p class="<?php echo esc_attr( $tiermint_block ); ?>__quantity-fixed"<?php echo ( empty( $tiermint_quantity['show'] ) ) ? '' : ' hidden'; ?>>
		<span class="<?php echo esc_attr( $tiermint_block ); ?>__quantity-label"><?php echo esc_html__( 'Quantity', 'tiermint' ); ?></span>
		<span class="<?php echo esc_attr( $tiermint_block ); ?>__quantity-fixed-value"><?php echo esc_html( (string) ( $tiermint_quantity['value'] ?? 1 ) ); ?></span>
	</p>

	<?php
	// wp_nonce_field() prints its escaped nonce markup.
	wp_nonce_field( (string) $tiermint_form['nonce_action'], (string) $tiermint_form['nonce_field'], true, true );
	?>
	<button type="submit" class="<?php echo esc_attr( $tiermint_block ); ?>__add tiermint-button tiermint-button--primary"><?php echo esc_html( (string) $tiermint_form['add_label'] ); ?></button>
</form>
