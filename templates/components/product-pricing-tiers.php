<?php
/**
 * Product pricing tiers template.
 *
 * Uses the shared purchase form for consistent tier selection and cart actions.
 *
 * @package DrPlugin\WP\TierMint
 *
 * @var array<string, mixed> $data Purchase-form data.
 */

defined( 'ABSPATH' ) || exit;

$tiermint_form = ( isset( $data ) && is_array( $data ) ) ? $data : array();

if ( empty( $tiermint_form['has_tiers'] ) ) {
	return;
}
?>
<div class="tiermint-component tiermint-product-pricing-tiers">
	<?php require TIERMINT_PATH . 'templates/components/partials/product-purchase-form.php'; ?>
</div>
