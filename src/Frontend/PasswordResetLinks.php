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
 * Swaps only the wp-login base of the reset link in password emails.
 *
 * Page-gated: while a published reset page exists, the lost-password mail and the
 * set-password (registration, checkout auto-register, admin-created) mail point at
 * it; otherwise WordPress builds its normal link (self-heal). The query is kept
 * verbatim, so the login, key and wp_lang survive and an admin can still paste the
 * same key and login into wp-login. Rebuilding the message is deliberately avoided:
 * it would drop other plugins' edits and cannot re-mint the key.
 *
 * @since 1.0.0
 */
class PasswordResetLinks implements Hookable {

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
		add_filter( 'retrieve_password_message', array( $this, 'rewriteMessage' ), 10, 4 );
		add_filter( 'wp_new_user_notification_email', array( $this, 'rewriteNewUserEmail' ), 10, 3 );
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
	 * Covers the registration, checkout auto-register, and admin-created user mails.
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
}
