<?php
/**
 * Exception for a failed gateway API request.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Payments;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Raised for an external gateway API failure.
 *
 * The raw message is log-only. Controllers return a generic error.
 *
 * @since 1.0.0
 */
final class GatewayRequestException extends PaymentException {

	/**
	 * Capture the gateway-side failure details alongside the message.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $message    Human-readable failure description for logs.
	 * @param string|null $errorCode  The gateway's machine error code (e.g. Stripe's "card_declined"), when the gateway returned one.
	 * @param int|null    $httpStatus The HTTP status of the gateway response, or null for transport-level failures.
	 */
	public function __construct(
		string $message,
		private readonly ?string $errorCode = null,
		private readonly ?int $httpStatus = null
	) {
		parent::__construct( $message );
	}

	/**
	 * The gateway's machine error code, when one was returned.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public function errorCode(): ?string {
		return $this->errorCode;
	}

	/**
	 * The HTTP status of the gateway response, or null for transport failures.
	 *
	 * @since 1.0.0
	 *
	 * @return int|null
	 */
	public function httpStatus(): ?int {
		return $this->httpStatus;
	}
}
