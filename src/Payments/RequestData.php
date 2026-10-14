<?php
/**
 * Immutable DTO wrapping an inbound webhook HTTP request.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Payments;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Raw webhook data for {@see GatewayInterface::parseWebhook()}.
 * The body stays unchanged for signature checks.
 *
 * @since 1.0.0
 */
final class RequestData {

	/**
	 * Headers re-keyed to canonical form (lowercase, dashes), first value only.
	 *
	 * @var array<string, string>
	 */
	private array $headers = array();

	/**
	 * Normalize the header map once at construction.
	 *
	 * @since 1.0.0
	 *
	 * @param string                                   $body    The exact raw request body bytes.
	 * @param array<string, string|array<int, string>> $headers Header map; values may be strings or the value arrays WP_REST_Request produces.
	 */
	public function __construct( private readonly string $body, array $headers = array() ) {
		foreach ( $headers as $name => $value ) {
			if ( is_array( $value ) ) {
				$value = reset( $value );
			}

			if ( ! is_string( $value ) ) {
				continue;
			}

			$this->headers[ self::canonicalize( (string) $name ) ] = $value;
		}
	}

	/**
	 * The exact raw request body bytes.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function body(): string {
		return $this->body;
	}

	/**
	 * Look up a header without regard to case or separator.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name The header name in any casing/separator style.
	 * @return string|null The first header value, or null when absent.
	 */
	public function header( string $name ): ?string {
		return $this->headers[ self::canonicalize( $name ) ] ?? null;
	}

	/**
	 * Normalize a header name for lookup.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name The header name as supplied.
	 * @return string
	 */
	private static function canonicalize( string $name ): string {
		return str_replace( '_', '-', strtolower( $name ) );
	}
}
