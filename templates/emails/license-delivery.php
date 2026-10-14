<?php
/**
 * License delivery email body.
 *
 * Theme override: {theme}/tiermint/emails/license-delivery.php.
 *
 * @package DrPlugin\WP\TierMint
 *
 * @var array<string, mixed> $data {
 *     @type string $product_name Product name.
 *     @type string $license_key License key.
 *     @type string $expires_at Expiry date.
 *     @type array<int, array<string, mixed>> $downloads Download entries.
 * }
 */

defined( 'ABSPATH' ) || exit;

$tiermint_product   = (string) ( $data['product_name'] ?? '' );
$tiermint_key       = (string) ( $data['license_key'] ?? '' );
$tiermint_expires   = (string) ( $data['expires_at'] ?? '' );
$tiermint_downloads = is_array( $data['downloads'] ?? null ) ? $data['downloads'] : array();
?>
<h2 style="margin:0 0 16px; font-size:18px; font-weight:600; color:#1f2933;">
	<?php esc_html_e( 'Your license key', 'tiermint' ); ?>
</h2>

<p style="margin:0 0 24px; color:#52606d;">
	<?php
	printf(
		/* translators: %s: the product name. */
		esc_html__( 'Here is your license key for %s.', 'tiermint' ),
		esc_html( $tiermint_product )
	);
	?>
</p>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; border-collapse:collapse; margin-bottom:24px;">
	<tbody>
		<tr>
			<td style="padding:10px 12px; background-color:#f8fafc; border:1px solid #e4e7eb;">
				<span style="display:block; color:#52606d; font-size:14px; margin-bottom:4px;"><?php echo esc_html( $tiermint_product ); ?></span>
				<code style="font-family:'SFMono-Regular',Consolas,'Liberation Mono',Menlo,monospace; font-size:15px; color:#1f2933; word-break:break-all;"><?php echo esc_html( $tiermint_key ); ?></code>
			</td>
		</tr>
	</tbody>
</table>

<?php if ( '' !== $tiermint_expires ) : ?>
	<p style="margin:0 0 24px; color:#52606d;">
		<?php
		printf(
			/* translators: %s: the license expiry date. */
			esc_html__( 'This license is valid until %s.', 'tiermint' ),
			esc_html( $tiermint_expires )
		);
		?>
	</p>
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
