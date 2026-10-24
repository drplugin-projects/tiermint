<?php
/**
 * The product changelog component.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend\Components;

use DrPlugin\WP\TierMint\Products\Product;
use DrPlugin\WP\TierMint\Products\ProductService;
use DrPlugin\WP\TierMint\Updates\ChangelogRenderer;
use DrPlugin\WP\TierMint\Updates\Release;
use DrPlugin\WP\TierMint\Updates\ReleaseRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Renders published release notes for a product.
 *
 * It uses the shared changelog renderer.
 *
 * @since 1.0.0
 */
final class ProductChangelog extends AbstractProductComponent {

	/**
	 * Maximum releases rendered by one block.
	 *
	 * @var int
	 */
	private const MAX_RELEASES = 20;

	/**
	 * The releases repository the changelog reads.
	 *
	 * @var ReleaseRepository
	 */
	private ReleaseRepository $releases;

	/**
	 * The Markdown-to-sanitized-HTML renderer.
	 *
	 * @var ChangelogRenderer
	 */
	private ChangelogRenderer $renderer;

	/**
	 * Wire the component's collaborators.
	 *
	 * @param ProductService    $products The catalog service (routed-product resolution).
	 * @param ReleaseRepository $releases The releases repository.
	 * @param ChangelogRenderer $renderer The Markdown-to-sanitized-HTML renderer.
	 */
	public function __construct( ProductService $products, ReleaseRepository $releases, ChangelogRenderer $renderer ) {
		parent::__construct( $products );
		$this->releases = $releases;
		$this->renderer = $renderer;
	}

	/**
	 * The component slug (template name, asset-handle suffix, shortcode base).
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'product-changelog';
	}

	/**
	 * The product, release count and display options.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function schema(): array {
		return parent::schema() + array(
			'releases'             => array(
				'type'        => 'integer',
				'default'     => 0,
				'label'       => __( 'Releases to show', 'tiermint' ),
				'description' => __( 'How many of the most recent releases to show. 0 shows the full history (up to 20).', 'tiermint' ),
				'control'     => 'number',
			),
			'show_version_heading' => array(
				'type'        => 'boolean',
				'default'     => true,
				'label'       => __( 'Show version heading', 'tiermint' ),
				'description' => __( 'Show each release version as a heading above its notes.', 'tiermint' ),
				'control'     => 'toggle',
			),
			'show_dates'           => array(
				'type'        => 'boolean',
				'default'     => true,
				'label'       => __( 'Show release dates', 'tiermint' ),
				'description' => __( 'Show the date each release was published.', 'tiermint' ),
				'control'     => 'toggle',
			),
			'show_compatibility'   => array(
				'type'        => 'boolean',
				'default'     => true,
				'label'       => __( 'Show compatibility', 'tiermint' ),
				'description' => __( 'Show each release\'s Requires WordPress, Tested up to, and Requires PHP.', 'tiermint' ),
				'control'     => 'toggle',
			),
		);
	}

	/**
	 * Reduce the product's latest published releases to rendered changelog rows.
	 *
	 * @param Product              $product The product to render.
	 * @param array<string, mixed> $attrs   Resolved attribute values (display toggles).
	 * @return array{releases: array<int, array{version: string, date: string|null, html: string, compatibility: array<int, array{label: string, value: string}>}>, show_version_heading: bool}
	 */
	protected function viewData( Product $product, array $attrs ): array {
		$productId = (int) $product->id();

		// Zero or negative values use the maximum release count.
		$count = (int) ( $attrs['releases'] ?? 0 );
		$count = $count <= 0 ? self::MAX_RELEASES : min( self::MAX_RELEASES, $count );

		$showHeading       = (bool) ( $attrs['show_version_heading'] ?? true );
		$showDates         = (bool) ( $attrs['show_dates'] ?? true );
		$showCompatibility = (bool) ( $attrs['show_compatibility'] ?? true );

		// The newest releases appear first.
		$published = $this->releases->published( $productId );

		$rows = array();
		foreach ( array_slice( $published, 0, $count ) as $release ) {
			$releasedAt = $release->releasedAt();

			$date = null;
			if ( $showDates && null !== $releasedAt ) {
				$formatted = wp_date( (string) get_option( 'date_format' ), $releasedAt->getTimestamp() );
				$date      = ( is_string( $formatted ) && '' !== $formatted ) ? $formatted : null;
			}

			$version = $release->version();
			$html    = $this->renderer->renderCached( (int) $release->id(), $release->changelog() ?? '' );
			$compat  = $showCompatibility ? $this->compatibilityLine( $release ) : array();

			// Do not render an empty release card.
			if ( ( ! $showHeading || '' === $version ) && null === $date && '' === $html && array() === $compat ) {
				continue;
			}

			$rows[] = array(
				'version'       => $version,
				'date'          => $date,
				'html'          => $html,
				'compatibility' => $compat,
			);
		}

		return array(
			'releases'             => $rows,
			'show_version_heading' => $showHeading,
		);
	}

	/**
	 * The changelog is degenerate when there is no release row to show.
	 *
	 * @param array<string, mixed> $data The reduced view data.
	 * @return bool
	 */
	protected function viewDataIsEmpty( array $data ): bool {
		return array() === ( $data['releases'] ?? array() );
	}

	/**
	 * Sample changelog rows for an editor preview.
	 *
	 * @param Product              $product The resolved product (unused; the sample is fixed).
	 * @param array<string, mixed> $attrs   Resolved attribute values (display toggles).
	 * @return array{releases: array<int, array{version: string, date: string|null, html: string, compatibility: array<int, array{label: string, value: string}>}>, show_version_heading: bool}
	 */
	protected function sampleViewData( Product $product, array $attrs ): array {
		unset( $product );

		$showHeading       = (bool) ( $attrs['show_version_heading'] ?? true );
		$showDates         = (bool) ( $attrs['show_dates'] ?? true );
		$showCompatibility = (bool) ( $attrs['show_compatibility'] ?? true );

		$dateFormat = (string) get_option( 'date_format' );

		$samples = array(
			array(
				'version' => '2.1.0',
				'stamp'   => 1_722_506_400, // 2024-08-01.
				'notes'   => '<ul><li>' . esc_html__( 'Faster catalogue queries on large stores.', 'tiermint' ) . '</li><li>' . esc_html__( 'Fixed a rare checkout error on slow connections.', 'tiermint' ) . '</li></ul>',
				'compat'  => array(
					array(
						'label' => __( 'Requires WordPress', 'tiermint' ),
						'value' => '6.5',
					),
					array(
						'label' => __( 'Tested up to', 'tiermint' ),
						'value' => '6.9',
					),
					array(
						'label' => __( 'Requires PHP', 'tiermint' ),
						'value' => '8.1',
					),
				),
			),
			array(
				'version' => '2.0.0',
				'stamp'   => 1_719_828_000, // 2024-07-01.
				'notes'   => '<ul><li>' . esc_html__( 'New settings screen for the storefront.', 'tiermint' ) . '</li></ul>',
				'compat'  => array(
					array(
						'label' => __( 'Tested up to', 'tiermint' ),
						'value' => '6.8',
					),
				),
			),
		);

		$rows = array();
		foreach ( $samples as $sample ) {
			$date = null;
			if ( $showDates ) {
				$formatted = wp_date( $dateFormat, $sample['stamp'] );
				$date      = ( is_string( $formatted ) && '' !== $formatted ) ? $formatted : null;
			}

			$rows[] = array(
				'version'       => $sample['version'],
				'date'          => $date,
				'html'          => $sample['notes'],
				'compatibility' => $showCompatibility ? $sample['compat'] : array(),
			);
		}

		return array(
			'releases'             => $rows,
			'show_version_heading' => $showHeading,
		);
	}

	/**
	 * Build compatibility labels for the release metadata.
	 *
	 * @param Release $release The release.
	 * @return array<int, array{label: string, value: string}> The label/value pairs in a fixed order.
	 */
	private function compatibilityLine( Release $release ): array {
		$line = array();

		$fields = array(
			array( __( 'Requires WordPress', 'tiermint' ), $release->requiresWp() ),
			array( __( 'Tested up to', 'tiermint' ), $release->testedUpTo() ),
			array( __( 'Requires PHP', 'tiermint' ), $release->requiresPhp() ),
		);

		foreach ( $fields as $field ) {
			$value = $field[1];

			if ( null !== $value && '' !== $value ) {
				$line[] = array(
					'label' => $field[0],
					'value' => $value,
				);
			}
		}

		return $line;
	}
}
