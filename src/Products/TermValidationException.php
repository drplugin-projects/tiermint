<?php
/**
 * Exception for term input validation failures.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Products;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Raised when term input fails validation. It keeps all field errors for the
 * controller.
 *
 * @since 1.0.0
 */
final class TermValidationException extends TermException {

	/**
	 * The individual validation messages that caused the failure.
	 *
	 * @var array<int, string>
	 */
	private array $errors;

	/**
	 * Build the exception with a summary message and its detailed error list.
	 *
	 * @since 1.0.0
	 *
	 * @param string             $message A human-readable summary.
	 * @param array<int, string> $errors  The individual field-level messages.
	 */
	public function __construct( string $message, array $errors = array() ) {
		parent::__construct( $message );
		$this->errors = $errors;
	}

	/**
	 * Build the exception from a list of messages, joining them for the summary.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string> $errors The individual field-level messages.
	 * @return self
	 */
	public static function withErrors( array $errors ): self {
		return new self( implode( ' ', $errors ), $errors );
	}

	/**
	 * The individual validation messages.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, string> The field-level error messages.
	 */
	public function getErrors(): array {
		return $this->errors;
	}
}
