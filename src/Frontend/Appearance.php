<?php
/**
 * The curated storefront appearance overrides: validation and token derivation.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Validates stored appearance values and derives their CSS tokens.
 *
 * The settings writer and token emitter share these rules.
 *
 * @since 1.0.0
 */
final class Appearance {

	/**
	 * Stored colour keys and their base token suffixes.
	 *
	 * @var array<string, string>
	 */
	private const COLOR_KEYS = array(
		'primary' => 'color-primary',
		'text'    => 'color-text',
		'surface' => 'color-surface',
		'page'    => 'color-page',
		'border'  => 'color-border',
		'success' => 'color-success',
		'error'   => 'color-error',
	);

	/**
	 * Radius presets and their token values.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const RADIUS_PRESETS = array(
		'small' => array(
			'radius-sm' => '4px',
			'radius'    => '6px',
			'radius-lg' => '8px',
		),
		'large' => array(
			'radius-sm' => '10px',
			'radius'    => '14px',
			'radius-lg' => '20px',
		),
	);

	/**
	 * Maximum accepted font-stack length.
	 *
	 * @var int
	 */
	private const FONT_MAX_LENGTH = 300;

	/**
	 * Validate a hex colour, an unset value, or an invalid value.
	 *
	 * @param mixed $value The submitted or stored value.
	 * @return string|null The canonical hex, the empty string, or null.
	 */
	public function sanitizeColor( $value ): ?string {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		$hex = sanitize_hex_color( $value );

		if ( ! is_string( $hex ) || '' === $hex ) {
			return null;
		}

		return $this->canonicalHex( $hex );
	}

	/**
	 * Validate a whitelisted font stack.
	 *
	 * Each family is a quoted name or bare identifier, which prevents CSS injection.
	 *
	 * @param mixed $value The submitted or stored value.
	 * @return string|null The canonical stack, the empty string, or null.
	 */
	public function sanitizeFontStack( $value ): ?string {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		if ( strlen( $value ) > self::FONT_MAX_LENGTH ) {
			return null;
		}

		$families = array();
		foreach ( explode( ',', $value ) as $family ) {
			$family = trim( $family );
			// A bare system font may have one leading hyphen.
			if ( 1 !== preg_match( '/^(?:"[A-Za-z0-9 _-]+"|\'[A-Za-z0-9 _-]+\'|-?[A-Za-z][A-Za-z0-9 _-]*)$/D', $family ) ) {
				return null;
			}
			$families[] = $family;
		}

		return implode( ', ', $families );
	}

	/**
	 * Build custom properties from valid stored overrides.
	 *
	 * @param array<string, mixed> $overrides The stored appearance overrides.
	 * @return array<string, string> Property name to value.
	 */
	public function declarations( array $overrides ): array {
		$out = array();

		foreach ( self::COLOR_KEYS as $key => $suffix ) {
			if ( ! array_key_exists( $key, $overrides ) ) {
				continue;
			}
			$hex = $this->sanitizeColor( $overrides[ $key ] );
			if ( null === $hex || '' === $hex ) {
				continue;
			}
			$out[ '--tiermint-' . $suffix ] = $hex;
			foreach ( $this->companions( $key, $hex ) as $property => $value ) {
				$out[ $property ] = $value;
			}
		}

		if ( isset( $overrides['radius'] ) && is_string( $overrides['radius'] ) && isset( self::RADIUS_PRESETS[ $overrides['radius'] ] ) ) {
			foreach ( self::RADIUS_PRESETS[ $overrides['radius'] ] as $suffix => $value ) {
				$out[ '--tiermint-' . $suffix ] = $value;
			}
		}

		if ( array_key_exists( 'font', $overrides ) ) {
			$font = $this->sanitizeFontStack( $overrides['font'] );
			if ( null !== $font && '' !== $font ) {
				$out['--tiermint-font-sans'] = $font;
			}
		}

		return $out;
	}

	/**
	 * Build the inline stylesheet for valid overrides.
	 *
	 * The doubled `:root` selector overrides token defaults and theme overrides.
	 *
	 * @param array<string, mixed> $overrides The stored appearance overrides.
	 * @return string The `:root:root{...}` block, or the empty string.
	 */
	public function css( array $overrides ): string {
		$declarations = $this->declarations( $overrides );

		if ( array() === $declarations ) {
			return '';
		}

		$body = '';
		foreach ( $declarations as $property => $value ) {
			$body .= $property . ':' . $value . ';';
		}

		return ':root:root{' . $body . '}';
	}

	/**
	 * The derived companion tokens for a base colour, keyed by property name.
	 *
	 * @param string $key The stored override key (primary, surface, ...).
	 * @param string $hex The validated base colour.
	 * @return array<string, string> Companion property names to values.
	 */
	private function companions( string $key, string $hex ): array {
		switch ( $key ) {
			case 'primary':
				return array(
					'--tiermint-color-primary-hover' => $this->mix( $hex, '#000000', 0.15 ),
					'--tiermint-color-primary-soft'  => $this->mix( $hex, '#ffffff', 0.90 ),
					'--tiermint-color-focus'         => $hex,
				);
			case 'surface':
				return array(
					'--tiermint-color-surface-raised' => $hex,
					'--tiermint-color-surface-alt'    => $this->mix( $hex, '#000000', 0.03 ),
				);
			case 'border':
				return array(
					'--tiermint-color-border-strong' => $this->mix( $hex, '#000000', 0.12 ),
				);
			case 'success':
				return array(
					'--tiermint-color-success-bg' => $this->mix( $hex, '#ffffff', 0.94 ),
				);
			case 'error':
				return array(
					'--tiermint-color-error-bg' => $this->mix( $hex, '#ffffff', 0.94 ),
				);
			default:
				return array();
		}
	}

	/**
	 * Mix two hex colours and return hex output.
	 *
	 * Plain hex works where CSS `color-mix()` does not.
	 *
	 * @param string $hex    The source colour, canonical six-digit hex.
	 * @param string $target The colour to mix toward, canonical six-digit hex.
	 * @param float  $ratio  The share of the target, 0 to 1.
	 * @return string The mixed colour as six-digit hex.
	 */
	private function mix( string $hex, string $target, float $ratio ): string {
		$from = $this->channels( $hex );
		$to   = $this->channels( $target );
		$out  = '#';

		for ( $i = 0; $i < 3; $i++ ) {
			$value = (int) round( $from[ $i ] * ( 1 - $ratio ) + $to[ $i ] * $ratio );
			$out  .= str_pad( dechex( max( 0, min( 255, $value ) ) ), 2, '0', STR_PAD_LEFT );
		}

		return $out;
	}

	/**
	 * The three 0-255 channel values of a canonical six-digit hex colour.
	 *
	 * @param string $hex The colour, `#rrggbb`.
	 * @return array<int, int> Red, green, blue.
	 */
	private function channels( string $hex ): array {
		return array(
			hexdec( substr( $hex, 1, 2 ) ),
			hexdec( substr( $hex, 3, 2 ) ),
			hexdec( substr( $hex, 5, 2 ) ),
		);
	}

	/**
	 * Canonicalise a validated hex colour to lowercase six-digit form, expanding
	 * the three-digit shorthand.
	 *
	 * @param string $hex The colour accepted by sanitize_hex_color (`#abc` or `#aabbcc`).
	 * @return string The `#rrggbb` form, lowercased.
	 */
	private function canonicalHex( string $hex ): string {
		$hex = strtolower( $hex );

		if ( 4 === strlen( $hex ) ) {
			$hex = '#' . $hex[1] . $hex[1] . $hex[2] . $hex[2] . $hex[3] . $hex[3];
		}

		return $hex;
	}
}
