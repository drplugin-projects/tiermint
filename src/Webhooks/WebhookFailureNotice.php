<?php
/**
 * Admin notice for outbound webhook endpoints disabled by the failing policy.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Webhooks;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Admin\SpaController;
use DrPlugin\WP\TierMint\Core\Hookable;

/**
 * Shows persistent warnings for endpoints disabled after retries.
 *
 * Failed deliveries need action, so the notice cannot be dismissed.
 */
class WebhookFailureNotice implements Hookable {

	/**
	 * The registered endpoint store.
	 *
	 * @var WebhookEndpoints
	 */
	private WebhookEndpoints $endpoints;

	/**
	 * Wire the endpoint store.
	 *
	 * @param WebhookEndpoints $endpoints Registered endpoint configs.
	 */
	public function __construct( WebhookEndpoints $endpoints ) {
		$this->endpoints = $endpoints;
	}

	/**
	 * Bind the notice renderer.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Show failing endpoints to settings managers.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( \DrPlugin\WP\TierMint\Core\Capabilities::MANAGE_SETTINGS ) ) {
			return;
		}

		$failing = $this->endpoints->failing();

		if ( array() === $failing ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'TierMint has disabled the following webhook endpoints after repeated delivery failures. Fix the receivers, then re-enable the endpoints to resume deliveries:', 'tiermint' );
		echo '</p><ul>';

		foreach ( $failing as $endpoint ) {
			echo '<li><code>' . esc_html( $endpoint['url'] ) . '</code></li>';
		}

		echo '</ul><p><a href="' . esc_url( admin_url( 'admin.php?page=' . SpaController::PAGE_SLUG . '#/settings/webhooks' ) ) . '">';
		echo esc_html__( 'Open the webhook settings', 'tiermint' );
		echo '</a></p></div>';
	}
}
