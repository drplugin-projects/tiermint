<?php
/**
 * Order status transition guard.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Orders;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Guards order status transitions.
 *
 * Repeat partial refunds are allowed. Refunded and abandoned orders are final.
 *
 * @since 1.0.0
 */
final class OrderStateMachine {

	/**
	 * Allowed transitions by source status.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const TRANSITIONS = array(
		'pending'            => array( 'completed', 'failed', 'abandoned' ),
		'completed'          => array( 'refunded', 'partially_refunded' ),
		'partially_refunded' => array( 'partially_refunded', 'refunded' ),
		'failed'             => array( 'completed' ),
		'refunded'           => array(),
		'abandoned'          => array(),
	);

	/**
	 * Check whether a status transition is allowed.
	 *
	 * @since 1.0.0
	 *
	 * @param OrderStatus $from Current status.
	 * @param OrderStatus $to   Target status.
	 * @return bool Whether the transition is allowed.
	 */
	public function canTransition( OrderStatus $from, OrderStatus $to ): bool {
		return in_array( $to->value, self::TRANSITIONS[ $from->value ] ?? array(), true );
	}

	/**
	 * Get statuses that can transition to a target status.
	 *
	 * @since 1.0.0
	 *
	 * @param OrderStatus $to Target status.
	 * @return array<int, OrderStatus> Source statuses.
	 */
	public function sourcesFor( OrderStatus $to ): array {
		$sources = array();

		foreach ( self::TRANSITIONS as $from => $targets ) {
			if ( in_array( $to->value, $targets, true ) ) {
				$sources[] = OrderStatus::from( $from );
			}
		}

		return $sources;
	}

	/**
	 * Require an allowed status transition.
	 *
	 * @since 1.0.0
	 *
	 * @param OrderStatus $from Current status.
	 * @param OrderStatus $to   Target status.
	 * @return void
	 *
	 * @throws OrderTransitionException When the move is not allowed.
	 */
	public function assertCanTransition( OrderStatus $from, OrderStatus $to ): void {
		if ( ! $this->canTransition( $from, $to ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output here; escaped where printed.
			throw OrderTransitionException::between( $from, $to );
		}
	}
}
