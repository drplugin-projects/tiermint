<?php
/**
 * Purchase confirmation template.
 *
 * @package DrPlugin\WP\TierMint
 *
 * @var array<string, mixed> $data {
 *     State-specific view data.
 *
 *     @type string $state          Page state.
 *     @type string $title          Heading, empty to hide.
 *     @type string $login_url      Sign-in URL.
 *     @type string $order_uuid     Order UUID for polling.
 *     @type string $message        Processing message.
 *     @type int    $order_ref      Order reference.
 *     @type string $date           Localized order date.
 *     @type array<int, array<string, mixed>> $items    Order items.
 *     @type string $total          Formatted order total.
 *     @type bool   $show_licenses  Whether to show licenses.
 *     @type bool   $show_downloads Whether to show downloads.
 *     @type array<int, array<string, string>> $licenses License rows.
 *     @type array<int, array<string, mixed>>  $downloads Download entries.
 * }
 */

defined( 'ABSPATH' ) || exit;

?>
<div class="tiermint-component tiermint-purchase-confirmation">

	<?php if ( 'not_found' === $data['state'] ) : ?>

		<div class="tiermint-purchase-confirmation__notice" role="alert">
			<p><?php esc_html_e( 'We could not find that order.', 'tiermint' ); ?></p>
		</div>

	<?php elseif ( 'sign_in' === $data['state'] ) : ?>

		<div class="tiermint-purchase-confirmation__sign-in">
			<p><?php esc_html_e( 'Please sign in to view this order.', 'tiermint' ); ?></p>
			<a class="tiermint-purchase-confirmation__login tiermint-button tiermint-button--primary" href="<?php echo esc_url( $data['login_url'] ); ?>">
				<?php esc_html_e( 'Sign in', 'tiermint' ); ?>
			</a>
		</div>

	<?php elseif ( 'processing' === $data['state'] ) : ?>

		<?php if ( '' !== ( $data['title'] ?? '' ) ) : ?>
			<h2 class="tiermint-purchase-confirmation__title"><?php echo esc_html( $data['title'] ); ?></h2>
		<?php endif; ?>

		<div
			class="tiermint-purchase-confirmation__processing"
			data-tiermint-order-status
			data-order-uuid="<?php echo esc_attr( $data['order_uuid'] ); ?>"
			data-msg-confirmed="<?php esc_attr_e( 'Payment confirmed. Loading your receipt.', 'tiermint' ); ?>"
			role="status"
			aria-live="polite"
		>
			<p class="tiermint-purchase-confirmation__processing-message">
				<?php echo esc_html( $data['message'] ); ?>
			</p>
			<noscript>
				<p class="tiermint-purchase-confirmation__noscript">
					<?php esc_html_e( 'Refresh this page in a few moments to see your receipt.', 'tiermint' ); ?>
				</p>
			</noscript>
		</div>

	<?php elseif ( 'refunded' === $data['state'] ) : ?>

		<?php if ( '' !== ( $data['title'] ?? '' ) ) : ?>
			<h2 class="tiermint-purchase-confirmation__title"><?php echo esc_html( $data['title'] ); ?></h2>
		<?php endif; ?>

		<div class="tiermint-purchase-confirmation__refunded" role="status">
			<p>
				<?php
				printf(
					/* translators: %s: the order reference number. */
					esc_html__( 'Order #%s was refunded.', 'tiermint' ),
					esc_html( (string) $data['order_ref'] )
				);
				?>
			</p>
		</div>

	<?php else : ?>

		<?php if ( '' !== ( $data['title'] ?? '' ) ) : ?>
			<h2 class="tiermint-purchase-confirmation__title"><?php echo esc_html( $data['title'] ); ?></h2>
		<?php endif; ?>

		<div class="tiermint-purchase-confirmation__receipt tiermint-card">
			<dl class="tiermint-purchase-confirmation__summary">
				<div class="tiermint-purchase-confirmation__summary-item">
					<dt><?php esc_html_e( 'Order', 'tiermint' ); ?></dt>
					<dd>
						<?php
						printf(
							/* translators: %s: the order reference number. */
							esc_html__( '#%s', 'tiermint' ),
							esc_html( (string) $data['order_ref'] )
						);
						?>
					</dd>
				</div>
				<?php if ( '' !== $data['date'] ) : ?>
					<div class="tiermint-purchase-confirmation__summary-item">
						<dt><?php esc_html_e( 'Date', 'tiermint' ); ?></dt>
						<dd><?php echo esc_html( $data['date'] ); ?></dd>
					</div>
				<?php endif; ?>
			</dl>

			<table class="tiermint-purchase-confirmation__items tiermint-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Item', 'tiermint' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Quantity', 'tiermint' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Total', 'tiermint' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( (array) $data['items'] as $tiermint_item ) : ?>
						<tr class="tiermint-purchase-confirmation__item">
							<td class="tiermint-purchase-confirmation__item-name"><?php echo esc_html( (string) $tiermint_item['name'] ); ?></td>
							<td class="tiermint-purchase-confirmation__item-qty"><?php echo esc_html( (string) $tiermint_item['quantity'] ); ?></td>
							<td class="tiermint-purchase-confirmation__item-total"><?php echo esc_html( (string) $tiermint_item['line_total'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr class="tiermint-purchase-confirmation__total-row">
						<th scope="row" colspan="2"><?php esc_html_e( 'Order total', 'tiermint' ); ?></th>
						<td class="tiermint-purchase-confirmation__total"><?php echo esc_html( (string) $data['total'] ); ?></td>
					</tr>
				</tfoot>
			</table>

			<?php if ( $data['show_licenses'] && array() !== $data['licenses'] ) : ?>
				<section class="tiermint-purchase-confirmation__licenses">
					<h3 class="tiermint-purchase-confirmation__licenses-title">
						<?php esc_html_e( 'Your license keys', 'tiermint' ); ?>
					</h3>
					<ul class="tiermint-purchase-confirmation__license-list">
						<?php foreach ( $data['licenses'] as $tiermint_license ) : ?>
							<li class="tiermint-purchase-confirmation__license">
								<span class="tiermint-purchase-confirmation__license-product">
									<?php echo esc_html( (string) $tiermint_license['product_name'] ); ?>
								</span>
								<code class="tiermint-purchase-confirmation__license-key">
									<?php echo esc_html( (string) $tiermint_license['license_key'] ); ?>
								</code>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endif; ?>

			<?php if ( $data['show_downloads'] && array() !== $data['downloads'] ) : ?>
				<section class="tiermint-purchase-confirmation__downloads">
					<h3 class="tiermint-purchase-confirmation__downloads-title">
						<?php esc_html_e( 'Your downloads', 'tiermint' ); ?>
					</h3>
					<ul class="tiermint-purchase-confirmation__download-products">
						<?php foreach ( $data['downloads'] as $tiermint_entry ) : ?>
							<li class="tiermint-purchase-confirmation__download-product">
								<span class="tiermint-purchase-confirmation__download-name">
									<?php echo esc_html( (string) $tiermint_entry['product_name'] ); ?>
								</span>
								<ul class="tiermint-purchase-confirmation__files">
									<?php foreach ( $tiermint_entry['files'] as $tiermint_file ) : ?>
										<li class="tiermint-purchase-confirmation__file">
											<a
												class="tiermint-purchase-confirmation__file-link"
												href="<?php echo esc_url( (string) $tiermint_file['download_url'] ); ?>"
												download
											>
												<?php echo esc_html( (string) $tiermint_file['label'] ); ?>
											</a>
										</li>
									<?php endforeach; ?>
								</ul>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endif; ?>
		</div>

	<?php endif; ?>
</div>
