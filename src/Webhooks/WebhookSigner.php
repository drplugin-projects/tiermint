<?php
/**
 * Outbound webhook payload signer.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Webhooks;

defined( 'ABSPATH' ) || exit;

/**
 * Signs outbound webhook payloads as `t={unix},v1={hmac}`.
 *
 * The timestamp lets receivers reject replayed requests. Callers provide a
 * new timestamp for each retry.
 */
final class WebhookSigner {

	/**
	 * Sign a payload body for delivery at a given moment.
	 *
	 * @param string $body      The exact JSON body bytes that will be POSTed.
	 * @param string $secret    The endpoint's signing secret (plaintext).
	 * @param int    $timestamp Unix send time bound into the signature.
	 * @return string Header value in the form `t={unix},v1={hex digest}`.
	 */
	public function sign( string $body, string $secret, int $timestamp ): string {
		$digest = hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );

		return 't=' . $timestamp . ',v1=' . $digest;
	}
}
