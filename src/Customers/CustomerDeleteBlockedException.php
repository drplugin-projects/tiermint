<?php
/**
 * Raised when something still points at a customer a caller asked to delete.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Customers;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Raised when orders or licenses still reference a customer.
 *
 * @since 1.0.0
 */
final class CustomerDeleteBlockedException extends CustomerException {

	/**
	 * Defaults to an empty list so directly created exceptions are safe to read.
	 *
	 * @var array<int, string>
	 */
	private array $reasons = array();

	/**
	 * Build the exception from the collected reasons.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string> $reasons The reasons the delete was refused.
	 * @return self The exception.
	 */
	public static function withReasons( array $reasons ): self {
		$reasons = array_values(
			array_filter(
				array_map( 'strval', $reasons ),
				static fn ( string $reason ): bool => '' !== trim( $reason )
			)
		);

		$exception          = new self( implode( ' ', $reasons ) );
		$exception->reasons = $reasons;

		return $exception;
	}

	/**
	 * The reasons the delete was refused.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, string> The reasons.
	 */
	public function getReasons(): array {
		return $this->reasons;
	}
}
