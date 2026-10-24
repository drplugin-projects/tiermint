<?php
/**
 * Rewrites the wp-login reset link inside WordPress password emails to the branded page.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend;

use DrPlugin\WP\TierMint\Core\Hookable;

defined( 'ABSPATH' ) || exit;

/**
 * Swaps only the wp-login base of the reset link in password emails, keeping the
 * query, so the same key still works on wp-login.
 *
 * TierMint's own sends always get the reset page link. Other WordPress password
 * emails get it only while replace_wp_login is on, and both need a published page.
 *
 * @since 1.0.0
 */
class PasswordResetLinks implements Hookable {

	/**
	 * The settings option carrying the toggle.
	 *
	 * @var string
	 */
	private const SETTINGS_OPTION = 'tiermint_settings';

	/**
	 * Priority of the one-shot filters. It differs from the global filters, so
	 * removing a one-shot filter never removes a global one.
	 *
	 * @var int
	 */
	private const SCOPED_PRIORITY = 11;

	/**
	 * Resolves the reset page.
	 *
	 * @var PageResolver
	 */
	private PageResolver $pages;

	/**
	 * Wire the page resolver.
	 *
	 * @param PageResolver $pages The storefront page resolver.
	 */
	public function __construct( PageResolver $pages ) {
		$this->pages = $pages;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_filter( 'retrieve_password_message', array( $this, 'filterMessage' ), 10, 4 );
		add_filter( 'wp_new_user_notification_email', array( $this, 'filterNewUserEmail' ), 10, 3 );
	}

	/**
	 * Rewrite any lost-password email body while replace_wp_login is on.
	 *
	 * @param string   $message   The email body.
	 * @param string   $key       The reset key (unused).
	 * @param string   $userLogin The account login (unused).
	 * @param \WP_User $userData  The account (unused).
	 * @return string
	 */
	public function filterMessage( $message, $key = '', $userLogin = '', $userData = null ): string {
		return $this->enabled() ? $this->rewriteMessage( $message ) : (string) $message;
	}

	/**
	 * Rewrite any new-user notification email while replace_wp_login is on.
	 *
	 * @param array<string, mixed> $email    The wp_mail arguments.
	 * @param \WP_User             $user     The new user (unused).
	 * @param string               $blogname The site name (unused).
	 * @return array<string, mixed>
	 */
	public function filterNewUserEmail( $email, $user = null, $blogname = '' ): array {
		return $this->enabled() ? $this->rewriteNewUserEmail( $email ) : (array) $email;
	}

	/**
	 * Run one of TierMint's own mail sends with the reset link rewritten.
	 *
	 * The toggle does not apply here.
	 *
	 * @param callable(): mixed $send The mail send.
	 * @return mixed What the send returned.
	 */
	public function withBrandedLinks( callable $send ) {
		add_filter( 'retrieve_password_message', array( $this, 'rewriteMessage' ), self::SCOPED_PRIORITY, 4 );
		add_filter( 'wp_new_user_notification_email', array( $this, 'rewriteNewUserEmail' ), self::SCOPED_PRIORITY, 3 );

		try {
			return $send();
		} finally {
			remove_filter( 'retrieve_password_message', array( $this, 'rewriteMessage' ), self::SCOPED_PRIORITY );
			remove_filter( 'wp_new_user_notification_email', array( $this, 'rewriteNewUserEmail' ), self::SCOPED_PRIORITY );
		}
	}

	/**
	 * Rewrite the lost-password email body.
	 *
	 * @param string   $message   The email body.
	 * @param string   $key       The reset key (unused; the URL already carries it).
	 * @param string   $userLogin The account login (unused).
	 * @param \WP_User $userData  The account (unused).
	 * @return string
	 */
	public function rewriteMessage( $message, $key = '', $userLogin = '', $userData = null ): string {
		return $this->rewrite( (string) $message );
	}

	/**
	 * Rewrite the set-password link inside a new-user notification email.
	 *
	 * @param array<string, mixed> $email    The wp_mail arguments (to, subject, message, headers).
	 * @param \WP_User             $user     The new user (unused).
	 * @param string               $blogname The site name (unused).
	 * @return array<string, mixed>
	 */
	public function rewriteNewUserEmail( $email, $user = null, $blogname = '' ): array {
		$email = (array) $email;

		if ( isset( $email['message'] ) ) {
			$email['message'] = $this->rewrite( (string) $email['message'] );
		}

		return $email;
	}

	/**
	 * Swap the wp-login base of any reset link in a body, keeping its query verbatim.
	 *
	 * @param string $body The email body.
	 * @return string
	 */
	public function rewrite( string $body ): string {
		$pageUrl = $this->url();

		if ( null === $pageUrl ) {
			return $body;
		}

		$joiner = false === strpos( $pageUrl, '?' ) ? '?' : '&';

		$rewritten = preg_replace_callback(
			'~https?://[^\s<>"\']+?/wp-login\.php\?([^\s<>"\']+)~',
			static function ( array $matches ) use ( $pageUrl, $joiner ): string {
				$query = $matches[1];

				// Only the reset link, matched in either query order; leave anything else.
				if ( false === strpos( $query, 'action=rp' ) || false === strpos( $query, 'key=' ) || false === strpos( $query, 'login=' ) ) {
					return $matches[0];
				}

				return $pageUrl . $joiner . $query;
			},
			$body
		);

		return is_string( $rewritten ) ? $rewritten : $body;
	}

	/**
	 * The published reset page URL (per-blog permalink), or null.
	 *
	 * @return string|null
	 */
	private function url(): ?string {
		return $this->pages->url( PageResolver::PASSWORD_RESET );
	}

	/**
	 * Whether the replace_wp_login toggle is on.
	 *
	 * @return bool
	 */
	private function enabled(): bool {
		$settings = get_option( self::SETTINGS_OPTION );

		return is_array( $settings ) && ! empty( $settings['frontend']['replace_wp_login'] );
	}
}
