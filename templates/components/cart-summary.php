<?php
/**
 * Cart summary template.
 *
 * Theme override: {theme}/tiermint/components/cart-summary.php.
 *
 * @package DrPlugin\WP\TierMint
 *
 * @var array<string, mixed> $data {
 *     @type string $title Optional heading.
 *     @type bool $show_actions Show cart controls.
 *     @type bool $show_plan_details Show plan details.
 *     @type bool $is_empty Cart has no lines.
 *     @type array<int, array<string, mixed>> $lines Cart lines.
 *     @type string $subtotal Formatted total.
 *     @type string $total_label Label of the last row.
 *     @type array<string, mixed> $discount Discount data.
 *     @type string|null $checkout_url Checkout link.
 *     @type array<int, string> $notices Cart notices.
 * }
 */

use DrPlugin\WP\TierMint\Frontend\CartNotices;

defined( 'ABSPATH' ) || exit;

// Read the redirect marker without changing state.
$tiermint_cart_error_code = isset( $_GET['tiermint_cart_error'] ) ? sanitize_key( wp_unslash( $_GET['tiermint_cart_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of a redirect marker; no state change.

$tiermint_cart_error_messages = array(
	'invalid' => __( 'That item could not be added to the cart.', 'tiermint' ),
	'failed'  => __( 'Something went wrong updating your cart. Please try again.', 'tiermint' ),
);

// Show the shared success notice.
$tiermint_success = CartNotices::view()['success'];

// Keep mutation forms off the error-marker URL.
$tiermint_form_action_raw = remove_query_arg( 'tiermint_cart_error' );
?>
<div class="tiermint-component tiermint-cart-summary">
	<?php if ( ! empty( $tiermint_success['show'] ) ) : ?>
		<div class="tiermint-cart-notice tiermint-cart-notice--success" role="status">
			<p class="tiermint-cart-notice__text"><?php echo esc_html( (string) ( $tiermint_success['text'] ?? '' ) ); ?></p>
			<?php if ( ! empty( $tiermint_success['checkout_url'] ) ) : ?>
				<a class="tiermint-cart-notice__checkout" href="<?php echo esc_url( (string) $tiermint_success['checkout_url'] ); ?>"><?php echo esc_html__( 'Go to checkout', 'tiermint' ); ?></a>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( isset( $tiermint_cart_error_messages[ $tiermint_cart_error_code ] ) ) : ?>
		<div class="tiermint-cart-summary__error tiermint-notice tiermint-notice--error" role="alert">
			<?php echo esc_html( $tiermint_cart_error_messages[ $tiermint_cart_error_code ] ); ?>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $data['notices'] ) && is_array( $data['notices'] ) ) : ?>
		<div class="tiermint-cart-summary__notice tiermint-notice tiermint-notice--info" role="status">
			<?php foreach ( $data['notices'] as $tiermint_notice ) : ?>
				<p><?php echo esc_html( (string) $tiermint_notice ); ?></p>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<div class="tiermint-cart-summary__panel tiermint-card">
		<?php if ( '' !== $data['title'] ) : ?>
			<h2 class="tiermint-cart-summary__title"><?php echo esc_html( $data['title'] ); ?></h2>
		<?php endif; ?>

		<?php if ( $data['is_empty'] ) : ?>

			<p class="tiermint-cart-summary__empty"><?php echo esc_html__( 'Your cart is empty.', 'tiermint' ); ?></p>

		<?php else : ?>

			<table class="tiermint-cart-summary__table tiermint-table">
				<thead>
					<tr>
						<th scope="col"><?php echo esc_html__( 'Product', 'tiermint' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Quantity', 'tiermint' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Price', 'tiermint' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Subtotal', 'tiermint' ); ?></th>
						<?php if ( $data['show_actions'] ) : ?>
							<th scope="col"><span class="screen-reader-text"><?php echo esc_html__( 'Actions', 'tiermint' ); ?></span></th>
						<?php endif; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $data['lines'] as $tiermint_line ) : ?>
						<?php $tiermint_line_id = $tiermint_line['product_id'] . '-' . $tiermint_line['price_id']; ?>
						<tr class="tiermint-cart-summary__line">
							<td data-label="<?php echo esc_attr__( 'Product', 'tiermint' ); ?>">
								<?php echo esc_html( $tiermint_line['product_name'] ); ?>
								<?php if ( null !== $tiermint_line['price_name'] && '' !== $tiermint_line['price_name'] ) : ?>
									<span class="tiermint-cart-summary__tier tiermint-badge"><?php echo esc_html( $tiermint_line['price_name'] ); ?></span>
								<?php endif; ?>
								<?php
								$tiermint_line_meta = ( ! empty( $data['show_plan_details'] ) && ! empty( $tiermint_line['meta'] ) && is_array( $tiermint_line['meta'] ) )
									? $tiermint_line['meta']
									: array();
								?>
								<?php if ( array() !== $tiermint_line_meta ) : ?>
									<ul class="tiermint-cart-summary__line-meta">
										<?php foreach ( $tiermint_line_meta as $tiermint_meta_line ) : ?>
											<li class="tiermint-cart-summary__line-meta-item"><?php echo esc_html( (string) $tiermint_meta_line ); ?></li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							</td>
							<td data-label="<?php echo esc_attr__( 'Quantity', 'tiermint' ); ?>">
								<?php echo esc_html( (string) $tiermint_line['quantity'] ); ?>
							</td>
							<td data-label="<?php echo esc_attr__( 'Price', 'tiermint' ); ?>">
								<?php echo esc_html( $tiermint_line['unit_amount'] ); ?>
							</td>
							<td data-label="<?php echo esc_attr__( 'Subtotal', 'tiermint' ); ?>">
								<?php echo esc_html( $tiermint_line['subtotal'] ); ?>
							</td>
							<?php if ( $data['show_actions'] ) : ?>
								<td class="tiermint-cart-summary__actions">
									<?php
									// Use the shared cart controls.
									$tiermint_line_controls = array(
										'product_id'   => $tiermint_line['product_id'],
										'price_id'     => $tiermint_line['price_id'],
										'quantity'     => $tiermint_line['quantity'],
										'min'          => $tiermint_line['min'] ?? 1,
										'max'          => $tiermint_line['max'] ?? null,
										'fixed_single' => ! empty( $tiermint_line['fixed_single'] ),
										'action_url'   => $tiermint_form_action_raw,
										'id'           => $tiermint_line_id,
									);
									require TIERMINT_PATH . 'templates/components/partials/cart-line-controls.php';
									?>
								</td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
				<tfoot>
					<?php // A stored valid discount code shows its own row above the total, which is already discounted. ?>
					<?php if ( 'applied' === ( $data['discount']['state'] ?? '' ) ) : ?>
						<tr class="tiermint-cart-summary__discount">
							<th scope="row" colspan="3"><?php echo esc_html( (string) ( $data['discount']['label'] ?? '' ) ); ?></th>
							<td>-<?php echo esc_html( (string) ( $data['discount']['amount'] ?? '' ) ); ?></td>
							<?php if ( $data['show_actions'] ) : ?>
								<td></td>
							<?php endif; ?>
						</tr>
					<?php endif; ?>
					<?php // The automatic (no-code) discount shows its own row, so the lines and the total reconcile. ?>
					<?php if ( ! empty( $data['discount']['auto']['applied'] ) ) : ?>
						<tr class="tiermint-cart-summary__discount tiermint-cart-summary__discount--auto">
							<th scope="row" colspan="3"><?php echo esc_html( (string) ( $data['discount']['auto']['label'] ?? '' ) ); ?></th>
							<td>-<?php echo esc_html( (string) ( $data['discount']['auto']['amount'] ?? '' ) ); ?></td>
							<?php if ( $data['show_actions'] ) : ?>
								<td></td>
							<?php endif; ?>
						</tr>
					<?php endif; ?>
					<tr class="tiermint-cart-summary__totals">
						<th scope="row" colspan="3"><?php echo esc_html( $data['total_label'] ?? __( 'Total', 'tiermint' ) ); ?></th>
						<td><?php echo esc_html( $data['subtotal'] ); ?></td>
						<?php if ( $data['show_actions'] ) : ?>
							<td></td>
						<?php endif; ?>
					</tr>
				</tfoot>
			</table>

			<?php if ( null !== $data['checkout_url'] ) : ?>
				<a class="tiermint-cart-summary__checkout tiermint-button tiermint-button--primary" href="<?php echo esc_url( $data['checkout_url'] ); ?>">
					<?php echo esc_html__( 'Proceed to checkout', 'tiermint' ); ?>
				</a>
			<?php endif; ?>

		<?php endif; ?>
	</div>
</div>
