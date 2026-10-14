<?php
/**
 * Purchase receipt email body.
 *
 * Theme override: {theme}/tiermint/emails/purchase-receipt.php.
 * Show keys in the receipt.
 *
 * @package DrPlugin\WP\TierMint
 *
 * @var array<string, mixed> $data {
 *     @type string $store_name Store name.
 *     @type array $order Order data.
 *     @type array<int, array<string, mixed>> $items Line items.
 *     @type string $subtotal Formatted subtotal.
 *     @type array|null $discount Discount data.
 *     @type string $total Formatted total.
 *     @type array<int, array<string, string>> $licenses License rows.
 *     @type array<int, array<string, mixed>> $downloads Download entries.
 * }
 */

defined( 'ABSPATH' ) || exit;

$tiermint_order     = is_array( $data['order'] ?? null ) ? $data['order'] : array();
$tiermint_reference = (string) ( $tiermint_order['reference'] ?? '' );
$tiermint_date      = (string) ( $tiermint_order['date'] ?? '' );
$tiermint_items     = is_array( $data['items'] ?? null ) ? $data['items'] : array();
$tiermint_discount  = is_array( $data['discount'] ?? null ) ? $data['discount'] : null;
$tiermint_licenses  = is_array( $data['licenses'] ?? null ) ? $data['licenses'] : array();
$tiermint_downloads = is_array( $data['downloads'] ?? null ) ? $data['downloads'] : array();
?>
<h2 style="margin:0 0 16px; font-size:18px; font-weight:600; color:#1f2933;">
	<?php esc_html_e( 'Thank you for your purchase', 'tiermint' ); ?>
</h2>

<p style="margin:0 0 24px; color:#52606d;">
	<?php
	printf(
		/* translators: 1: the order reference number, 2: the order date. */
		esc_html__( 'Here is your receipt for order #%1$s placed on %2$s.', 'tiermint' ),
		esc_html( $tiermint_reference ),
		esc_html( $tiermint_date )
	);
	?>
</p>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; border-collapse:collapse; margin-bottom:24px;">
	<thead>
		<tr>
			<th align="left" style="padding:8px 0; border-bottom:2px solid #e4e7eb; font-size:13px; text-transform:uppercase; letter-spacing:0.04em; color:#7b8794;"><?php esc_html_e( 'Item', 'tiermint' ); ?></th>
			<th align="center" style="padding:8px 0; border-bottom:2px solid #e4e7eb; font-size:13px; text-transform:uppercase; letter-spacing:0.04em; color:#7b8794;"><?php esc_html_e( 'Qty', 'tiermint' ); ?></th>
			<th align="right" style="padding:8px 0; border-bottom:2px solid #e4e7eb; font-size:13px; text-transform:uppercase; letter-spacing:0.04em; color:#7b8794;"><?php esc_html_e( 'Total', 'tiermint' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $tiermint_items as $tiermint_item ) : ?>
			<tr>
				<td align="left" style="padding:12px 0; border-bottom:1px solid #f0f1f3; color:#1f2933;"><?php echo esc_html( (string) ( $tiermint_item['name'] ?? '' ) ); ?></td>
				<td align="center" style="padding:12px 0; border-bottom:1px solid #f0f1f3; color:#52606d;"><?php echo esc_html( (string) ( $tiermint_item['quantity'] ?? '' ) ); ?></td>
				<td align="right" style="padding:12px 0; border-bottom:1px solid #f0f1f3; color:#1f2933;"><?php echo esc_html( (string) ( $tiermint_item['total'] ?? '' ) ); ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
	<tfoot>
		<tr>
			<td align="right" colspan="2" style="padding:12px 8px 4px 0; color:#52606d;"><?php esc_html_e( 'Subtotal', 'tiermint' ); ?></td>
			<td align="right" style="padding:12px 0 4px; color:#1f2933;"><?php echo esc_html( (string) ( $data['subtotal'] ?? '' ) ); ?></td>
		</tr>
		<?php if ( null !== $tiermint_discount ) : ?>
			<tr>
				<td align="right" colspan="2" style="padding:4px 8px 4px 0; color:#52606d;">
					<?php
					printf(
						/* translators: %s: the discount code the buyer applied. */
						esc_html__( 'Discount (%s)', 'tiermint' ),
						esc_html( (string) ( $tiermint_discount['code'] ?? '' ) )
					);
					?>
				</td>
				<td align="right" style="padding:4px 0; color:#1f2933;">&minus;<?php echo esc_html( (string) ( $tiermint_discount['amount'] ?? '' ) ); ?></td>
			</tr>
		<?php endif; ?>
		<tr>
			<td align="right" colspan="2" style="padding:8px 8px 0 0; font-weight:600; color:#1f2933;"><?php esc_html_e( 'Order total', 'tiermint' ); ?></td>
			<td align="right" style="padding:8px 0 0; font-weight:600; color:#1f2933;"><?php echo esc_html( (string) ( $data['total'] ?? '' ) ); ?></td>
		</tr>
	</tfoot>
</table>

<?php if ( array() !== $tiermint_licenses ) : ?>
	<h3 style="margin:0 0 12px; font-size:16px; font-weight:600; color:#1f2933;">
		<?php esc_html_e( 'Your license keys', 'tiermint' ); ?>
	</h3>
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; border-collapse:collapse; margin-bottom:24px;">
		<tbody>
			<?php foreach ( $tiermint_licenses as $tiermint_license ) : ?>
				<tr>
					<td style="padding:10px 12px; background-color:#f8fafc; border:1px solid #e4e7eb;">
						<span style="display:block; color:#52606d; font-size:14px; margin-bottom:4px;"><?php echo esc_html( (string) ( $tiermint_license['product_name'] ?? '' ) ); ?></span>
						<code style="font-family:'SFMono-Regular',Consolas,'Liberation Mono',Menlo,monospace; font-size:15px; color:#1f2933; word-break:break-all;"><?php echo esc_html( (string) ( $tiermint_license['license_key'] ?? '' ) ); ?></code>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>

<?php if ( array() !== $tiermint_downloads ) : ?>
	<h3 style="margin:0 0 12px; font-size:16px; font-weight:600; color:#1f2933;">
		<?php esc_html_e( 'Your downloads', 'tiermint' ); ?>
	</h3>
	<p style="margin:0 0 12px; color:#7b8794; font-size:13px;">
		<?php esc_html_e( 'These links are valid for a limited time. Save your files somewhere safe.', 'tiermint' ); ?>
	</p>
	<?php foreach ( $tiermint_downloads as $tiermint_entry ) : ?>
		<p style="margin:0 0 6px; color:#1f2933; font-weight:600;"><?php echo esc_html( (string) ( $tiermint_entry['product_name'] ?? '' ) ); ?></p>
		<ul style="margin:0 0 16px; padding-left:20px;">
			<?php foreach ( ( is_array( $tiermint_entry['files'] ?? null ) ? $tiermint_entry['files'] : array() ) as $tiermint_file ) : ?>
				<li style="margin-bottom:4px;">
					<a href="<?php echo esc_url( (string) ( $tiermint_file['download_url'] ?? '' ) ); ?>" style="color:#2563eb; text-decoration:underline;">
						<?php echo esc_html( (string) ( $tiermint_file['label'] ?? '' ) ); ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endforeach; ?>
<?php endif; ?>
