<?php
/**
 * The extensible product field registry.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Products\Fields;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Validates core and extension product fields.
 *
 * Invalid fields are dropped. The first field id wins. Values use settings by default.
 *
 * @since 1.0.0
 */
final class ProductFieldRegistry {

	/**
	 * The field types the registry knows how to render and sanitize.
	 *
	 * @var array<int, string>
	 */
	private const TYPES = array( 'text', 'textarea', 'rich_text', 'media', 'media_gallery' );

	/**
	 * Where a field's value is persisted: `settings` in the product settings JSON
	 * (the default), or `column` in its own top-level product column.
	 *
	 * @var array<int, string>
	 */
	private const STORAGE = array( 'settings', 'column' );

	/**
	 * The storage a field falls back to when it declares none or an unknown one.
	 *
	 * @var string
	 */
	private const DEFAULT_STORAGE = 'settings';

	/**
	 * The built-in field and panel definitions.
	 *
	 * @var CoreProductFields
	 */
	private CoreProductFields $core;

	/**
	 * Wire the registry with the core definitions.
	 *
	 * @since 1.0.0
	 *
	 * @param CoreProductFields $core The built-in field and panel definitions.
	 */
	public function __construct( CoreProductFields $core ) {
		$this->core = $core;
	}

	/**
	 * Every field definition, after the extension filter, keyed by id, with
	 * malformed entries dropped and the first occurrence of an id kept.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>> Field definitions keyed by field id.
	 */
	public function fields(): array {
		/**
		 * Filters the product field definitions.
		 *
		 * Add product-editor fields here.
		 *
		 * Fields need id, panel, type and label. The first id wins.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, array<string, mixed>> $fields The field definitions.
		 */
		$fields = apply_filters( 'tiermint_product_fields', $this->core->fields() );

		$byId = array();
		foreach ( (array) $fields as $field ) {
			if ( ! is_array( $field ) || ! isset( $field['id'], $field['panel'], $field['type'], $field['label'] ) ) {
				continue;
			}
			// Numeric ids become integer keys and bypass type sanitization.
			if ( ! is_string( $field['id'] ) || '' === $field['id'] || is_numeric( $field['id'] ) ) {
				continue;
			}
			if ( ! is_string( $field['panel'] ) || '' === $field['panel'] ) {
				continue;
			}
			if ( ! is_string( $field['type'] ) || ! in_array( $field['type'], self::TYPES, true ) ) {
				continue;
			}
			if ( ! is_string( $field['label'] ) || '' === $field['label'] ) {
				continue;
			}
			// Drop a field with an invalid custom sanitizer.
			if ( isset( $field['sanitize'] ) && ! is_callable( $field['sanitize'] ) ) {
				continue;
			}
			if ( isset( $byId[ $field['id'] ] ) ) {
				continue;
			}
			// Unknown storage falls back to settings.
			$field['storage']     = ( isset( $field['storage'] ) && in_array( $field['storage'], self::STORAGE, true ) )
				? $field['storage']
				: self::DEFAULT_STORAGE;
			$byId[ $field['id'] ] = $field;
		}

		return $byId;
	}

	/**
	 * One field definition by id.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id The field id.
	 * @return array<string, mixed>|null The definition, or null when unregistered.
	 */
	public function field( string $id ): ?array {
		return $this->fields()[ $id ] ?? null;
	}

	/**
	 * Sanitize a registered field value.
	 *
	 * Use its callback or the type default. Unregistered values pass through.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id    The field id.
	 * @param mixed  $value The raw value.
	 * @return mixed The sanitized value.
	 */
	public function sanitize( string $id, $value ) {
		$field = $this->field( $id );

		if ( null === $field ) {
			return $value;
		}

		if ( isset( $field['sanitize'] ) && is_callable( $field['sanitize'] ) ) {
			return ( $field['sanitize'] )( $value );
		}

		return $this->sanitizeByType( (string) $field['type'], $value );
	}

	/**
	 * The panels the fields group under, after the extension filter, validated
	 * with the first occurrence of an id kept.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array<string, string>> Panels as `{id, label}` rows in display order.
	 */
	public function panels(): array {
		/**
		 * Filters the product field panels.
		 *
		 * Add product-editor panels here.
		 *
		 * Panels need id and label. The first id wins.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, array<string, string>> $panels The panel rows.
		 */
		$panels = apply_filters( 'tiermint_product_field_panels', $this->core->panels() );

		$byId = array();
		foreach ( (array) $panels as $panel ) {
			if ( ! is_array( $panel ) || ! isset( $panel['id'], $panel['label'] ) ) {
				continue;
			}
			if ( ! is_string( $panel['id'] ) || '' === $panel['id'] || ! is_string( $panel['label'] ) || '' === $panel['label'] ) {
				continue;
			}
			if ( isset( $byId[ $panel['id'] ] ) ) {
				continue;
			}
			$byId[ $panel['id'] ] = array(
				'id'    => $panel['id'],
				'label' => $panel['label'],
			);
		}

		return array_values( $byId );
	}

	/**
	 * Return fields for the client without sanitizer callbacks.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array<string, mixed>> Field rows for the SPA.
	 */
	public function forClient(): array {
		$rows = array();

		foreach ( $this->fields() as $field ) {
			$row = array(
				'id'      => $field['id'],
				'panel'   => $field['panel'],
				'type'    => $field['type'],
				'label'   => $field['label'],
				'storage' => $field['storage'],
			);
			if ( isset( $field['help'] ) && is_string( $field['help'] ) ) {
				$row['help'] = $field['help'];
			}
			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Apply the default sanitizer for a field type.
	 *
	 * @since 1.0.0
	 *
	 * @param string $type  The field type.
	 * @param mixed  $value The raw value.
	 * @return mixed The sanitized value.
	 */
	private function sanitizeByType( string $type, $value ) {
		switch ( $type ) {
			case 'rich_text':
				return wp_kses_post( is_string( $value ) ? $value : '' );
			case 'media':
				return is_scalar( $value ) ? absint( $value ) : 0;
			case 'media_gallery':
				return $this->sanitizeGallery( $value );
			case 'textarea':
				return sanitize_textarea_field( is_scalar( $value ) ? (string) $value : '' );
			case 'text':
			default:
				return sanitize_text_field( is_scalar( $value ) ? (string) $value : '' );
		}
	}

	/**
	 * Return a reindexed list of positive attachment ids.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value The raw gallery value.
	 * @return array<int, int> The attachment ids.
	 */
	private function sanitizeGallery( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$ids = array();
		foreach ( $value as $item ) {
			$id = is_scalar( $item ) ? absint( $item ) : 0;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}
}
