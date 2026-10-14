<?php
/**
 * Validation failure for management API key input.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\REST\Auth;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Raised for invalid API key input.
 *
 * @since 1.0.0
 */
final class ApiKeyValidationException extends ApiKeyException {

	/**
	 * The individual field-level validation messages.
	 *
	 * @var array<int, string>
	 */
	private array $errors = array();

	/**
	 * Build the exception from the full list of field-level messages.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string> $errors The field-level validation messages.
	 * @return self The exception carrying every message.
	 */
	public static function withErrors( array $errors ): self {
		$exception         = new self( (string) ( $errors[0] ?? '' ) );
		$exception->errors = array_values( $errors );

		return $exception;
	}

	/**
	 * The individual field-level validation messages.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, string> The messages, in input order.
	 */
	public function getErrors(): array {
		return array() !== $this->errors ? $this->errors : array( $this->getMessage() );
	}
}
