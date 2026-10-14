<?php
/**
 * Raised when an organization license is at its seat limit.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Licensing;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Thrown when an invite exceeds the organization seat limit.
 *
 * @since 1.0.0
 */
final class SeatLimitException extends SeatException {

	/**
	 * The seat limit that was reached.
	 *
	 * @var int
	 */
	private int $limit;

	/**
	 * Build the exception with the reached limit.
	 *
	 * @since 1.0.0
	 *
	 * @param int $limit The organization's seat limit.
	 */
	public function __construct( int $limit ) {
		$this->limit = $limit;

		parent::__construct( sprintf( 'The organization license has reached its seat limit of %d.', $limit ) );
	}

	/**
	 * The seat limit that was reached.
	 *
	 * @since 1.0.0
	 *
	 * @return int The limit.
	 */
	public function getLimit(): int {
		return $this->limit;
	}
}
