<?php
/**
 * Email header partial.
 *
 * Theme override: {theme}/tiermint/emails/header.php.
 * Use inline styles for email clients.
 *
 * @package DrPlugin\WP\TierMint
 *
 * @var array<string, mixed> $data View data.
 */

defined( 'ABSPATH' ) || exit;

$tiermint_store_name = (string) ( $data['store_name'] ?? '' );
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( str_replace( '_', '-', get_locale() ) ); ?>">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
	<title><?php echo esc_html( $tiermint_store_name ); ?></title>
</head>
<body style="margin:0; padding:0; background-color:#f4f4f5; color:#1f2933; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:1.5;">
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f4f5;">
		<tr>
			<td align="center" style="padding:24px 12px;">
				<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:600px; background-color:#ffffff; border-radius:8px; overflow:hidden; border:1px solid #e4e7eb;">
					<?php if ( '' !== $tiermint_store_name ) : ?>
						<tr>
							<td style="padding:24px 32px; background-color:#1f2933;">
								<h1 style="margin:0; color:#ffffff; font-size:20px; font-weight:600;">
									<?php echo esc_html( $tiermint_store_name ); ?>
								</h1>
							</td>
						</tr>
					<?php endif; ?>
					<tr>
						<td style="padding:32px;">
