<?php
/**
 * The "TierMint Pro" teaser tab on the Settings screen.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Upsell;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Core\Hookable;

/**
 * Adds a settings section holding one render-only field.
 *
 * The section carries no savable field, so the tab shows no Save button and
 * stores nothing. Present only while Pro is absent. The field type is a
 * built-in SPA renderer, so no extension can hijack it.
 *
 * @since 1.0.0
 */
final class SettingsTeaser implements Hookable {

	/**
	 * The settings section id.
	 *
	 * @var string
	 */
	public const SECTION_ID = 'pro_upsell';

	/**
	 * The render-only field type the SPA draws the teaser for.
	 *
	 * @var string
	 */
	public const FIELD_TYPE = 'pro_upsell';

	/**
	 * The Pro-presence seam.
	 *
	 * @var ProStatus
	 */
	private ProStatus $proStatus;

	/**
	 * Wire the teaser with the Pro-presence seam.
	 *
	 * @since 1.0.0
	 *
	 * @param ProStatus $proStatus The Pro-presence seam.
	 */
	public function __construct( ProStatus $proStatus ) {
		$this->proStatus = $proStatus;
	}

	/**
	 * Filter the settings sections and fields.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_filter( 'tiermint_settings_sections', array( $this, 'addSection' ) );
		add_filter( 'tiermint_settings_fields', array( $this, 'addField' ) );
	}

	/**
	 * Add the teaser section when Pro is absent.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, array<string, string>> $sections The sections built so far.
	 * @return array<int, array<string, string>> The sections, with the teaser when Pro is absent.
	 */
	public function addSection( array $sections ): array {
		if ( $this->proStatus->isActive() ) {
			return $sections;
		}

		$sections[] = array(
			'id'    => self::SECTION_ID,
			'title' => __( 'TierMint Pro', 'tiermint' ),
		);

		return $sections;
	}

	/**
	 * Add the one render-only field when Pro is absent.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, array<string, mixed>> $fields The fields built so far.
	 * @return array<int, array<string, mixed>> The fields, with the teaser when Pro is absent.
	 */
	public function addField( array $fields ): array {
		if ( $this->proStatus->isActive() ) {
			return $fields;
		}

		$fields[] = array(
			'id'          => self::FIELD_TYPE,
			'section'     => self::SECTION_ID,
			'type'        => self::FIELD_TYPE,
			'storage'     => 'none',
			'label'       => __( 'TierMint Pro', 'tiermint' ),
			'description' => '',
		);

		return $fields;
	}
}
