<?php
/**
 * The customer downloads-list front-end component.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend\Components;

use DrPlugin\WP\TierMint\Frontend\AbstractComponent;
use DrPlugin\WP\TierMint\Frontend\RenderContext;
use DrPlugin\WP\TierMint\Updates\CustomerDownloads;
use DrPlugin\WP\TierMint\Util\RequestUrl;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a customer's downloads and available updates.
 *
 * Signed download links check the order and licence again when used.
 *
 * @since 1.0.0
 */
final class DownloadsList extends AbstractComponent {

	/**
	 * The shared purchased-files and updates assembler.
	 *
	 * @var CustomerDownloads
	 */
	private CustomerDownloads $downloads;

	/**
	 * Wire the component with the downloads assembler.
	 *
	 * @param CustomerDownloads $downloads The shared downloads/updates assembler.
	 */
	public function __construct( CustomerDownloads $downloads ) {
		$this->downloads = $downloads;
	}

	/**
	 * The component slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'downloads-list';
	}

	/**
	 * The attribute schema adapters map their controls onto.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function schema(): array {
		return array(
			'title'        => array(
				'type'    => 'string',
				'default' => __( 'Your downloads', 'tiermint' ),
				'control' => 'text',
				'label'   => __( 'Title', 'tiermint' ),
			),
			'show_updates' => array(
				'type'    => 'boolean',
				'default' => true,
				'control' => 'toggle',
				'label'   => __( 'Show available updates', 'tiermint' ),
			),
		);
	}

	/**
	 * Render the downloads list, the empty state, or the logged-out prompt.
	 *
	 * @param array<string, mixed> $attrs Resolved attribute values.
	 * @param RenderContext        $ctx   Per-request customer/license state.
	 * @return string Escaped HTML.
	 */
	public function render( array $attrs, RenderContext $ctx ): string {
		$showUpdates = (bool) $attrs['show_updates'];

		$data = array(
			'title'        => (string) $attrs['title'],
			'show_updates' => $showUpdates,
			'login_url'    => '',
			'downloads'    => array(),
			'updates'      => array(),
		);

		// Show a sample list instead of a login prompt in the editor.
		if ( ! is_user_logged_in() ) {
			if ( $ctx->isEditorPreview() ) {
				return $this->renderSample( $this->sampleData( $data['title'], $showUpdates ), $ctx );
			}

			$data['state']     = 'logged_out';
			$data['login_url'] = wp_login_url( RequestUrl::current() );

			return $this->renderTemplate( $data );
		}

		$customer = $ctx->customer();

		// A user without a customer record has no downloads.
		if ( null !== $customer ) {
			// Download endpoints check access again when the link is used.
			$ttl = 6 * HOUR_IN_SECONDS;

			$data['downloads'] = $this->downloads->purchasedFileEntries( $customer, 1, $ttl );
			$data['updates']   = $showUpdates
				? $this->downloads->updateEntries( $customer, $ttl )
				: array();
		}

		$hasContent = array() !== $data['downloads'] || array() !== $data['updates'];

		// Show sample data for an empty editor preview.
		if ( ! $hasContent && $ctx->isEditorPreview() ) {
			return $this->renderSample( $this->sampleData( $data['title'], $showUpdates ), $ctx );
		}

		$data['state'] = $hasContent ? 'list' : 'empty';

		return $this->renderTemplate( $data );
	}

	/**
	 * Sample list data for an editor preview.
	 *
	 * @param string $title       The resolved heading.
	 * @param bool   $showUpdates Whether the updates section may render.
	 * @return array<string, mixed>
	 */
	private function sampleData( string $title, bool $showUpdates ): array {
		return array(
			'state'        => 'list',
			'title'        => $title,
			'show_updates' => $showUpdates,
			'login_url'    => '',
			'downloads'    => array(
				array(
					'product_name' => __( 'Sample product', 'tiermint' ),
					'files'        => array(
						array(
							'label'        => __( 'Download (.zip)', 'tiermint' ),
							'download_url' => '#',
						),
					),
				),
			),
			'updates'      => $showUpdates ? array(
				array(
					'product_name' => __( 'Sample product', 'tiermint' ),
					'version'      => '2.1.0',
					'download_url' => '#',
				),
			) : array(),
		);
	}
}
