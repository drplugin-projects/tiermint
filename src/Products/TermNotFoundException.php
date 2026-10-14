<?php
/**
 * Exception for a missing term.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Products;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Missing product term.
 *
 * @since 1.0.0
 */
final class TermNotFoundException extends TermException {

	/**
	 * Build the exception for a specific missing term id.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The term id that could not be found.
	 * @return self
	 */
	public static function forId( int $id ): self {
		return new self( sprintf( 'Term %d was not found.', $id ) );
	}
}
