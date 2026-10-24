<?php
/**
 * Product grid template.
 *
 * Theme override: {theme}/tiermint/components/product-grid.php.
 *
 * @package DrPlugin\WP\TierMint
 *
 * @var array{
 *     columns: string,
 *     products: array<int, array{name: string, monogram: string, image: string|null, description: string|null, url: string|null, price_html: string|null, buy: array{product_id: int, price_id: int, quantity: int}|null}>,
 *     filter_bar: array{action_url: string, categories: array<int, array{slug: string, name: string, depth: int}>, tags: array<int, array{slug: string, name: string}>, selected_category: string, selected_tags: array<int, string>}|null
 * } $data View data.
 */

defined( 'ABSPATH' ) || exit;

$tiermint_columns    = isset( $data['columns'] ) ? (string) $data['columns'] : '3';
$tiermint_products   = isset( $data['products'] ) && is_array( $data['products'] ) ? $data['products'] : array();
$tiermint_filter_bar = ( isset( $data['filter_bar'] ) && is_array( $data['filter_bar'] ) ) ? $data['filter_bar'] : null;

$tiermint_notices = \DrPlugin\WP\TierMint\Frontend\CartNotices::view();
?>
<div class="tiermint-component tiermint-product-grid" data-columns="<?php echo esc_attr( $tiermint_columns ); ?>">
	<?php require TIERMINT_PATH . 'templates/components/partials/cart-notice.php'; ?>
	<?php if ( null !== $tiermint_filter_bar ) : ?>
		<form class="tiermint-product-grid__filters" method="get" action="<?php echo esc_url( (string) $tiermint_filter_bar['action_url'] ); ?>">
			<label class="tiermint-product-grid__filter">
				<span class="tiermint-product-grid__filter-label"><?php echo esc_html__( 'Category', 'tiermint' ); ?></span>
				<select class="tiermint-product-grid__select tiermint-select" name="tiermint_category">
					<option value=""><?php echo esc_html__( 'All categories', 'tiermint' ); ?></option>
					<?php foreach ( (array) $tiermint_filter_bar['categories'] as $tiermint_category ) : ?>
						<option value="<?php echo esc_attr( (string) $tiermint_category['slug'] ); ?>"<?php selected( (string) $tiermint_filter_bar['selected_category'], (string) $tiermint_category['slug'] ); ?>>
							<?php echo esc_html( str_repeat( '- ', max( 0, (int) $tiermint_category['depth'] ) ) . (string) $tiermint_category['name'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>

			<?php if ( array() !== (array) $tiermint_filter_bar['tags'] ) : ?>
				<fieldset class="tiermint-product-grid__tags">
					<legend class="tiermint-product-grid__filter-label"><?php echo esc_html__( 'Tags', 'tiermint' ); ?></legend>
					<?php foreach ( (array) $tiermint_filter_bar['tags'] as $tiermint_tag ) : ?>
						<label class="tiermint-product-grid__tag">
							<input type="checkbox" name="tiermint_tag[]" value="<?php echo esc_attr( (string) $tiermint_tag['slug'] ); ?>"<?php checked( in_array( (string) $tiermint_tag['slug'], (array) $tiermint_filter_bar['selected_tags'], true ) ); ?> />
							<span><?php echo esc_html( (string) $tiermint_tag['name'] ); ?></span>
						</label>
					<?php endforeach; ?>
				</fieldset>
			<?php endif; ?>

			<button type="submit" class="tiermint-product-grid__filter-submit tiermint-button tiermint-button--secondary"><?php echo esc_html__( 'Filter', 'tiermint' ); ?></button>
		</form>
	<?php endif; ?>

	<?php if ( array() === $tiermint_products ) : ?>
		<p class="tiermint-product-grid__empty"><?php echo esc_html__( 'No products found.', 'tiermint' ); ?></p>
	<?php else : ?>
		<ul class="tiermint-product-grid__items">
			<?php foreach ( $tiermint_products as $tiermint_product ) : ?>
				<li class="tiermint-product-grid__item tiermint-card">
					<?php
					// Hide duplicate media links from screen readers.
					$tiermint_image    = isset( $tiermint_product['image'] ) ? (string) $tiermint_product['image'] : '';
					$tiermint_monogram = (string) ( $tiermint_product['monogram'] ?? '' );
					?>
					<?php if ( isset( $tiermint_product['url'] ) && '' !== (string) $tiermint_product['url'] ) : ?>
						<a class="tiermint-product-grid__media" href="<?php echo esc_url( (string) $tiermint_product['url'] ); ?>" aria-hidden="true" tabindex="-1">
							<?php
							if ( '' !== $tiermint_image ) {
								echo wp_kses( $tiermint_image, \DrPlugin\WP\TierMint\Frontend\Kses::image() );
							} else {
								printf( '<span class="tiermint-product-grid__monogram">%s</span>', esc_html( $tiermint_monogram ) );
							}
							?>
						</a>
					<?php else : ?>
						<div class="tiermint-product-grid__media" aria-hidden="true">
							<?php
							if ( '' !== $tiermint_image ) {
								echo wp_kses( $tiermint_image, \DrPlugin\WP\TierMint\Frontend\Kses::image() );
							} else {
								printf( '<span class="tiermint-product-grid__monogram">%s</span>', esc_html( $tiermint_monogram ) );
							}
							?>
						</div>
					<?php endif; ?>

					<?php if ( isset( $tiermint_product['url'] ) && '' !== (string) $tiermint_product['url'] ) : ?>
						<h3 class="tiermint-product-grid__name"><a class="tiermint-product-grid__link" href="<?php echo esc_url( (string) $tiermint_product['url'] ); ?>"><?php echo esc_html( (string) $tiermint_product['name'] ); ?></a></h3>
					<?php else : ?>
						<h3 class="tiermint-product-grid__name"><?php echo esc_html( (string) $tiermint_product['name'] ); ?></h3>
					<?php endif; ?>

					<?php if ( isset( $tiermint_product['description'] ) && '' !== (string) $tiermint_product['description'] ) : ?>
						<div class="tiermint-product-grid__description"><?php echo wp_kses_post( (string) $tiermint_product['description'] ); ?></div>
					<?php endif; ?>

					<?php if ( isset( $tiermint_product['price_html'] ) && null !== $tiermint_product['price_html'] ) : ?>
						<p class="tiermint-product-grid__price"><?php echo wp_kses( $tiermint_product['price_html'], \DrPlugin\WP\TierMint\Frontend\Kses::allowed() ); ?></p>
					<?php endif; ?>

					<?php if ( isset( $tiermint_product['buy'] ) && is_array( $tiermint_product['buy'] ) ) : ?>
						<form class="tiermint-product-grid__buy" method="post" action="">
							<input type="hidden" name="tiermint_cart_action" value="add" />
							<input type="hidden" name="product_id" value="<?php echo esc_attr( (string) $tiermint_product['buy']['product_id'] ); ?>" />
							<input type="hidden" name="price_id" value="<?php echo esc_attr( (string) $tiermint_product['buy']['price_id'] ); ?>" />
							<input type="hidden" name="quantity" value="<?php echo esc_attr( (string) ( $tiermint_product['buy']['quantity'] ?? 1 ) ); ?>" />
							<?php
							// Print the cart nonce field.
							wp_nonce_field(
								\DrPlugin\WP\TierMint\Frontend\CartActionListener::NONCE_ACTION,
								\DrPlugin\WP\TierMint\Frontend\CartActionListener::NONCE_FIELD,
								true,
								true
							);
							?>
							<button type="submit" class="tiermint-product-grid__buy-button tiermint-button tiermint-button--primary tiermint-button--small"><?php echo esc_html__( 'Buy now', 'tiermint' ); ?></button>
						</form>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
