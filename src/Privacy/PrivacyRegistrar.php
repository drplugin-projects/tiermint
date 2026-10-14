<?php
/**
 * Registers TierMint's GDPR exporter and eraser with WordPress.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Privacy;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Core\Hookable;

/**
 * Register TierMint privacy tools and policy text.
 *
 * @since 1.0.0
 */
final class PrivacyRegistrar implements Hookable {

	/**
	 * The key both the exporter and eraser register under.
	 *
	 * @var string
	 */
	private const KEY = 'tiermint';

	/**
	 * Wire the registrar with the exporter and eraser.
	 *
	 * @since 1.0.0
	 *
	 * @param PersonalDataExporter $exporter The personal-data exporter.
	 * @param PersonalDataEraser   $eraser   The personal-data eraser.
	 */
	public function __construct(
		private readonly PersonalDataExporter $exporter,
		private readonly PersonalDataEraser $eraser
	) {
	}

	/**
	 * Register the exporter, eraser, and privacy-policy content hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'registerExporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'registerEraser' ) );
		add_action( 'admin_init', array( $this, 'registerPolicyContent' ) );
	}

	/**
	 * Add TierMint's exporter to the registered set.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, array<string, mixed>> $exporters The registered exporters.
	 * @return array<string, array<string, mixed>> The exporters including TierMint's.
	 */
	public function registerExporter( array $exporters ): array {
		$exporters[ self::KEY ] = array(
			'exporter_friendly_name' => $this->exporter->friendlyName(),
			'callback'               => array( $this->exporter, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Add TierMint's eraser to the registered set.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, array<string, mixed>> $erasers The registered erasers.
	 * @return array<string, array<string, mixed>> The erasers including TierMint's.
	 */
	public function registerEraser( array $erasers ): array {
		$erasers[ self::KEY ] = array(
			'eraser_friendly_name' => $this->eraser->friendlyName(),
			'callback'             => array( $this->eraser, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Contribute suggested privacy-policy text describing the personal data
	 * TierMint stores, for the WordPress privacy-policy guide.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function registerPolicyContent(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = wp_kses_post(
			wpautop(
				__( 'When you buy from this store, TierMint records your email address, order details, billing address, company name, tax ID, license keys, the sites where you activate a license, and the IP address used to download files. Order records are kept for tax and accounting purposes even after an erasure request; their personal fields are removed or anonymized rather than the record being deleted.', 'tiermint' )
			)
		);

		wp_add_privacy_policy_content( 'TierMint', $content );
	}
}
