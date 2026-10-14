<?php
/**
 * Product meta template.
 *
 * Theme override: {theme}/tiermint/components/product-meta.php.
 *
 * @package DrPlugin\WP\TierMint
 *
 * @var array{
 *     categories: array<int, array{name: string, url: string|null}>,
 *     tags: array<int, array{name: string, url: string|null}>,
 *     version: string|null,
 *     updated: string|null
 * } $data View data.
 */

defined( 'ABSPATH' ) || exit;

$tiermint_categories = ( isset( $data['categories'] ) && is_array( $data['categories'] ) ) ? $data['categories'] : array();
$tiermint_tags       = ( isset( $data['tags'] ) && is_array( $data['tags'] ) ) ? $data['tags'] : array();
$tiermint_version    = ( isset( $data['version'] ) && '' !== (string) $data['version'] ) ? (string) $data['version'] : null;
$tiermint_updated    = ( isset( $data['updated'] ) && '' !== (string) $data['updated'] ) ? (string) $data['updated'] : null;

$tiermint_has_terms   = array() !== $tiermint_categories || array() !== $tiermint_tags;
$tiermint_has_release = null !== $tiermint_version || null !== $tiermint_updated;

if ( ! $tiermint_has_terms && ! $tiermint_has_release ) {
	return;
}

/**
 * Render one taxonomy row.
 *
 * @param string $label Row label.
 * @param string $kind Row modifier.
 * @param array<int, array{name: string, url: string|null}> $terms Term rows.
 * @return void
 */
$tiermint_render_terms = static function ( string $label, string $kind, array $terms ): void {
	?>
	<div class="tiermint-product-meta__row tiermint-product-meta__row--<?php echo esc_attr( $kind ); ?>">
		<span class="tiermint-product-meta__label"><?php echo esc_html( $label ); ?></span>
		<span class="tiermint-product-meta__terms">
			<?php foreach ( $terms as $tiermint_term ) : ?>
				<?php if ( isset( $tiermint_term['url'] ) && '' !== (string) $tiermint_term['url'] ) : ?>
					<a class="tiermint-product-meta__term" href="<?php echo esc_url( (string) $tiermint_term['url'] ); ?>"><?php echo esc_html( (string) $tiermint_term['name'] ); ?></a>
				<?php else : ?>
					<span class="tiermint-product-meta__term"><?php echo esc_html( (string) $tiermint_term['name'] ); ?></span>
				<?php endif; ?>
			<?php endforeach; ?>
		</span>
	</div>
	<?php
};
?>
<div class="tiermint-component tiermint-product-meta">
	<?php
	if ( array() !== $tiermint_categories ) {
		$tiermint_render_terms( __( 'Categories', 'tiermint' ), 'categories', $tiermint_categories );
	}

	if ( array() !== $tiermint_tags ) {
		$tiermint_render_terms( __( 'Tags', 'tiermint' ), 'tags', $tiermint_tags );
	}
	?>

	<?php if ( $tiermint_has_release ) : ?>
		<div class="tiermint-product-meta__row tiermint-product-meta__row--release">
			<?php if ( null !== $tiermint_version ) : ?>
				<span class="tiermint-product-meta__version">
					<?php
					/* translators: %s: a release version number, e.g. 1.4.0. */
					echo esc_html( sprintf( __( 'Version %s', 'tiermint' ), $tiermint_version ) );
					?>
				</span>
			<?php endif; ?>
			<?php if ( null !== $tiermint_updated ) : ?>
				<span class="tiermint-product-meta__updated">
					<?php
					/* translators: %s: the date of the latest published release. */
					echo esc_html( sprintf( __( 'Updated %s', 'tiermint' ), $tiermint_updated ) );
					?>
				</span>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>
