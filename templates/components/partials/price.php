<?php
/**
 * Sale price pair.
 *
 * The screen-reader sentence announces both formatted prices once.
 *
 * @package DrPlugin\WP\TierMint
 *
 * @var string $tiermint_was Formatted original price.
 * @var string $tiermint_now Formatted sale price.
 * @var string $tiermint_note Price note, or '' for none.
 */

defined( 'ABSPATH' ) || exit;

$tiermint_was  = (string) $tiermint_was;
$tiermint_now  = (string) $tiermint_now;
$tiermint_note = isset( $tiermint_note ) ? (string) $tiermint_note : '';

/* translators: 1: original price, 2: discounted price. */
$tiermint_sr = sprintf( __( 'Original price was %1$s, discounted price is %2$s.', 'tiermint' ), $tiermint_was, $tiermint_now );

// The note trails the assistive sentence, so a screen reader hears the sentence
// then the note once. With no note the pair is unchanged.
?>
<span class="tiermint-price tiermint-price--discounted">
	<del aria-hidden="true"><span class="tiermint-price__was"><?php echo esc_html( $tiermint_was ); ?></span></del>
	<ins><span class="tiermint-price__now"><?php echo esc_html( $tiermint_now ); ?></span></ins>
	<span class="screen-reader-text"><?php echo esc_html( $tiermint_sr ); ?></span>
<?php
if ( '' !== $tiermint_note ) {
	printf( ' <span class="tiermint-price__note">%s</span>', esc_html( $tiermint_note ) );
}
?>
</span>
