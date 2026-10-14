<?php
/**
 * Email footer partial.
 *
 * Theme override: {theme}/tiermint/emails/footer.php.
 *
 * @package DrPlugin\WP\TierMint
 *
 * @var array<string, mixed> $data View data.
 */

defined( 'ABSPATH' ) || exit;

$tiermint_store_name = (string) ( $data['store_name'] ?? '' );
?>
						</td>
					</tr>
					<tr>
						<td style="padding:24px 32px; background-color:#f8fafc; border-top:1px solid #e4e7eb;">
							<p style="margin:0; color:#7b8794; font-size:13px; line-height:1.5;">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: the store name. */
										__( 'This message was sent by %s regarding your order.', 'tiermint' ),
										$tiermint_store_name
									)
								);
								?>
							</p>
						</td>
					</tr>
				</table>
			</td>
		</tr>
	</table>
</body>
</html>
