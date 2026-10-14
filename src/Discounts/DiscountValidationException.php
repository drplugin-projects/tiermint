<?php
/**
 * Exception for discount input and application validation failures.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Discounts;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Thrown when discount data or use fails validation.
 * Carries translated field messages for REST and checkout responses.
 *
 * @since 1.0.0
 */
class DiscountValidationException extends DiscountException {

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
	 * Build this exception from field messages.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string> $errors The individual field-level messages.
	 * @return static
	 */
	public static function withErrors( array $errors ): self {
		return new static( implode( ' ', $errors ), $errors );
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
