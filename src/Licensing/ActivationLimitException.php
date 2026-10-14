<?php
/**
 * Exception for exceeding a license's activation limit.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Licensing;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Thrown when a site exceeds its license activation limit.
 *
 * The REST layer returns the reached limit with `activation_limit_reached`.
 *
 * @since 1.0.0
 */
final class ActivationLimitException extends LicenseException {

	/**
	 * The activation limit that was reached.
	 *
	 * @var int
	 */
	private int $limit;

	/**
	 * Build an activation-limit error.
	 *
	 * @since 1.0.0
	 *
	 * @param int $limit The activation limit the license reached.
	 */
	public function __construct( int $limit ) {
		/* translators: %d: the license's maximum number of activations. */
		parent::__construct( sprintf( __( 'Activation limit of %d reached for this license.', 'tiermint' ), $limit ) );
		$this->limit = $limit;
	}

	/**
	 * Get the reached activation limit.
	 *
	 * @since 1.0.0
	 *
	 * @return int The reached activation limit.
	 */
	public function getLimit(): int {
		return $this->limit;
	}
}
