<?php
/**
 * Pricing table template.
 *
 * Theme override: {theme}/tiermint/components/pricing-table.php.
 *
 * @package DrPlugin\WP\TierMint
 *
 * @var array{columns: string, button_text: string, popular_label: string, action_url: string, nonce_action: string, nonce_field: string, plans: array<int, array<string, mixed>>} $data View data.
 */

defined( 'ABSPATH' ) || exit;

$tiermint_notices = \DrPlugin\WP\TierMint\Frontend\CartNotices::view();
?>
<section class="tiermint-component tiermint-pricing-table" data-columns="<?php echo esc_attr( $data['columns'] ); ?>">
	<?php require TIERMINT_PATH . 'templates/components/partials/cart-notice.php'; ?>
	<?php foreach ( $data['plans'] as $tiermint_plan ) : ?>
		<article class="<?php echo esc_attr( $tiermint_plan['plan_class'] ); ?>">
			<?php if ( $tiermint_plan['is_highlight'] ) : ?>
				<span class="tiermint-pricing-table__badge"><?php echo esc_html( $data['popular_label'] ); ?></span>
			<?php endif; ?>

			<h3 class="tiermint-pricing-table__name"><?php echo esc_html( $tiermint_plan['name'] ); ?></h3>

			<p class="tiermint-pricing-table__price">
				<span class="tiermint-pricing-table__amount"><?php echo $tiermint_plan['price_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- PriceDisplay returns markup it has already escaped. ?></span>
				<span class="tiermint-pricing-table__tier"><?php echo esc_html( $tiermint_plan['tier_name'] ); ?></span>
			</p>

			<?php
			$tiermint_meta = ( isset( $tiermint_plan['meta'] ) && is_array( $tiermint_plan['meta'] ) ) ? $tiermint_plan['meta'] : array();
			?>
			<?php if ( array() !== $tiermint_meta ) : ?>
				<ul class="tiermint-pricing-table__tier-meta">
					<?php foreach ( $tiermint_meta as $tiermint_line ) : ?>
						<li class="tiermint-pricing-table__tier-meta-item"><?php echo esc_html( (string) $tiermint_line ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( '' !== $tiermint_plan['description'] ) : ?>
				<div class="tiermint-pricing-table__description"><?php echo wp_kses_post( $tiermint_plan['description'] ); ?></div>
			<?php endif; ?>

			<?php if ( array() !== $tiermint_plan['features'] ) : ?>
				<ul class="tiermint-pricing-table__features">
					<?php foreach ( $tiermint_plan['features'] as $tiermint_feature ) : ?>
						<li class="tiermint-pricing-table__feature"><?php echo esc_html( $tiermint_feature ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<form class="tiermint-pricing-table__form" method="post" action="<?php echo esc_url( $data['action_url'] ); ?>">
				<input type="hidden" name="tiermint_cart_action" value="add">
				<input type="hidden" name="product_id" value="<?php echo esc_attr( (string) $tiermint_plan['product_id'] ); ?>">
				<input type="hidden" name="price_id" value="<?php echo esc_attr( (string) $tiermint_plan['price_id'] ); ?>">
				<input type="hidden" name="quantity" value="<?php echo esc_attr( (string) ( $tiermint_plan['quantity'] ?? 1 ) ); ?>">
				<?php echo wp_nonce_field( $data['nonce_action'], $data['nonce_field'], true, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field() returns escaped markup. ?>
				<button type="submit" class="tiermint-pricing-table__button tiermint-button tiermint-button--primary"><?php echo esc_html( $data['button_text'] ); ?></button>
			</form>
		</article>
	<?php endforeach; ?>
</section>
