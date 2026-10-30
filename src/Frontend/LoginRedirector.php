<?php
/**
 * Optional redirect of front-end sign-in requests to the custom login page.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend;

use DrPlugin\WP\TierMint\Core\Hookable;
use DrPlugin\WP\TierMint\Frontend\Components\LoginForm;
use DrPlugin\WP\TierMint\Frontend\PasswordResetActionListener;

defined( 'ABSPATH' ) || exit;

/**
 * Sends safe sign-in requests to the configured custom page.
 *
 * POST, interim/reauth logins and the admin escape-hatch stay on wp-login.php.
 * With a published reset page, the lost-password and keyed reset GETs (and the
 * checkemail=confirm screen) also divert to the branded page; a bare cookie-based
 * reset load, and the checkemail=registered/newpass screens, stay on wp-login.
 *
 * @since 1.0.0
 */
class LoginRedirector implements Hookable {

	/**
	 * The settings option carrying the toggle.
	 *
	 * @var string
	 */
	private const SETTINGS_OPTION = 'tiermint_settings';

	/**
	 * The query argument that bypasses the redirect.
	 *
	 * @var string
	 */
	public const BYPASS_ARG = 'tiermint_wp_login';

	/**
	 * Resolves the sign-in and registration pages.
	 *
	 * @var PageResolver
	 */
	private PageResolver $pages;

	/**
	 * Redirect terminator, injected for tests.
	 *
	 * @var callable(): void
	 */
	private $terminator;

	/**
	 * Wire the page resolver and (optionally) a test terminator.
	 *
	 * @param PageResolver  $pages      The storefront page resolver.
	 * @param callable|null $terminator Replaces exit in tests.
	 */
	public function __construct( PageResolver $pages, ?callable $terminator = null ) {
		$this->pages      = $pages;
		$this->terminator = $terminator ?? static function (): void {
			exit;
		};
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'login_init', array( $this, 'maybeRedirect' ) );
		// Last, since it redirects and exits: plugins that count failed logins run first.
		add_action( 'wp_login_failed', array( $this, 'onLoginFailed' ), PHP_INT_MAX );
		add_filter( 'login_url', array( $this, 'filterLoginUrl' ), 10, 3 );
		add_filter( 'logout_redirect', array( $this, 'filterLogoutRedirect' ), 10, 2 );
		add_filter( 'register_url', array( $this, 'filterRegisterUrl' ) );
		add_filter( 'lostpassword_url', array( $this, 'filterLostPasswordUrl' ), 10, 2 );
	}

	/**
	 * Redirect a safe sign-in GET when enabled.
	 *
	 * @return void
	 */
	public function maybeRedirect(): void {
		if ( ! $this->enabled() ) {
			return;
		}

		if ( isset( $_GET[ self::BYPASS_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing decision, no state change.
			return;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'GET' !== $method ) {
			return;
		}

		// Interim/reauth logins must complete on wp-login; guard them before the
		// reset diversions, which sit before the non-login action bail.
		if ( '' !== $this->requestValue( 'interim-login' ) || '' !== $this->requestValue( 'reauth' ) ) {
			return;
		}

		if ( $this->maybeDivertReset() ) {
			return;
		}

		$action = $this->requestValue( 'action' );
		if ( '' !== $action && 'login' !== $action ) {
			return;
		}

		// Other lost-password confirmations (registered, newpass) stay on wp-login.
		if ( '' !== $this->requestValue( 'checkemail' ) ) {
			return;
		}

		$loginUrl = $this->pages->url( PageResolver::LOGIN );
		if ( null === $loginUrl ) {
			return;
		}

		$this->send( $this->withRedirectTo( $loginUrl ) );
	}

	/**
	 * Divert the lost-password, keyed reset, or checkemail=confirm GET to the
	 * branded reset page. Returns true when it sent a redirect.
	 *
	 * Resolving the reset page only inside the matching branch keeps a self-heal
	 * (no page) and every other request from touching it.
	 *
	 * @return bool
	 */
	private function maybeDivertReset(): bool {
		$action = $this->requestValue( 'action' );

		if ( 'lostpassword' === $action || 'retrievepassword' === $action ) {
			$page = $this->pages->url( PageResolver::PASSWORD_RESET );
			if ( null === $page ) {
				return false;
			}

			$this->send( $this->withRedirectTo( $this->withResetError( $page ) ) );

			return true;
		}

		if ( 'rp' === $action || 'resetpass' === $action ) {
			$key   = $this->resetKey();
			$login = $this->resetLogin();

			// A bare cookie-based reset load (no key/login) stays on wp-login.
			if ( '' === $key || '' === $login ) {
				return false;
			}

			$page = $this->pages->url( PageResolver::PASSWORD_RESET );
			if ( null === $page ) {
				return false;
			}

			$page = add_query_arg( 'key', rawurlencode( $key ), $page );
			$page = add_query_arg( 'login', rawurlencode( $login ), $page );
			$this->send( $page );

			return true;
		}

		if ( 'confirm' === $this->requestValue( 'checkemail' ) ) {
			$page = $this->pages->url( PageResolver::PASSWORD_RESET );
			if ( null === $page ) {
				return false;
			}

			$this->send( add_query_arg( PasswordResetActionListener::STATUS_MARKER, 'sent', $page ) );

			return true;
		}

		return false;
	}

	/**
	 * Carry a dead-link error (expiredkey, invalidkey) to the branded page's marker.
	 *
	 * @param string $url The reset page URL.
	 * @return string
	 */
	private function withResetError( string $url ): string {
		$error = $this->requestValue( 'error' );

		if ( 'expiredkey' === $error || 'invalidkey' === $error ) {
			return add_query_arg( PasswordResetActionListener::ERROR_MARKER, $error, $url );
		}

		return $url;
	}

	/**
	 * Point login links to the custom page, except reauthentication links.
	 *
	 * @param string $loginUrl    The default login URL.
	 * @param string $redirect    The redirect target the caller requested, or ''.
	 * @param bool   $forceReauth Whether a reauthentication was forced.
	 * @return string
	 */
	public function filterLoginUrl( string $loginUrl, string $redirect = '', bool $forceReauth = false ): string {
		if ( ! $this->enabled() || $forceReauth ) {
			return $loginUrl;
		}

		$page = $this->pages->url( PageResolver::LOGIN );
		if ( null === $page ) {
			return $loginUrl;
		}

		if ( '' !== $redirect ) {
			$page = add_query_arg( 'redirect_to', rawurlencode( $redirect ), $page );
		}

		return $page;
	}

	/**
	 * Send default logout redirects to the custom sign-in page.
	 *
	 * @param string $redirectTo          The resolved logout redirect.
	 * @param string $requestedRedirectTo The target the caller requested, or ''.
	 * @return string
	 */
	public function filterLogoutRedirect( string $redirectTo, string $requestedRedirectTo = '' ): string {
		if ( ! $this->enabled() || '' !== $requestedRedirectTo ) {
			return $redirectTo;
		}

		$page = $this->pages->url( PageResolver::LOGIN );

		return null !== $page ? $page : $redirectTo;
	}

	/**
	 * Point registration links to the custom page when registration is open.
	 *
	 * @param string $registerUrl The default registration URL.
	 * @return string
	 */
	public function filterRegisterUrl( string $registerUrl ): string {
		if ( ! $this->enabled() || ! (bool) get_option( 'users_can_register' ) ) {
			return $registerUrl;
		}

		$page = $this->pages->url( PageResolver::REGISTER );

		return null !== $page ? $page : $registerUrl;
	}

	/**
	 * Point lost-password links to the reset page when enabled and one is published.
	 *
	 * TierMint's own sign-in form links to the reset page directly, so it keeps
	 * that link with the toggle off.
	 *
	 * @param string $url      The default lost-password URL.
	 * @param string $redirect The redirect target the caller requested, or ''.
	 * @return string
	 */
	public function filterLostPasswordUrl( string $url, string $redirect = '' ): string {
		if ( ! $this->enabled() ) {
			return $url;
		}

		$page = $this->pages->url( PageResolver::PASSWORD_RESET );
		if ( null === $page ) {
			return $url;
		}

		if ( '' !== $redirect ) {
			$page = add_query_arg( 'redirect_to', rawurlencode( $redirect ), $page );
		}

		return $page;
	}

	/**
	 * Send failed browser sign-ins to the custom page with a generic marker.
	 *
	 * @param string $username The submitted username (unused; the notice is generic).
	 * @return void
	 */
	public function onLoginFailed( $username ): void {
		unset( $username );

		if ( ! $this->enabled() || ! $this->isBrowserContext() ) {
			return;
		}

		if ( '' !== $this->requestValue( 'interim-login' ) || '' !== $this->requestValue( 'reauth' ) ) {
			return;
		}

		$page = $this->pages->url( PageResolver::LOGIN );
		if ( null === $page ) {
			return;
		}

		$target = add_query_arg( LoginForm::FAILED_MARKER, 'failed', $page );

		$this->send( $this->withRedirectTo( $target ) );
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

	/**
	 * A sanitized request value, or '' when absent.
	 *
	 * @param string $key The request key.
	 * @return string
	 */
	private function requestValue( string $key ): string {
		return isset( $_REQUEST[ $key ] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing decisions; wp-login itself reads these unauthenticated.
			? sanitize_key( wp_unslash( $_REQUEST[ $key ] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing decision; no state change.
			: '';
	}

	/**
	 * The reset key from the request, stripped to letters and digits.
	 *
	 * @return string
	 */
	private function resetKey(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing; the reset page validates the key.
		$key = isset( $_REQUEST['key'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['key'] ) ) : '';

		return PasswordResetCookie::cleanKey( $key );
	}

	/**
	 * The account login from the request, sanitized.
	 *
	 * @return string
	 */
	private function resetLogin(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing; the reset page validates the login.
		return isset( $_REQUEST['login'] ) && is_string( $_REQUEST['login'] ) ? sanitize_user( wp_unslash( $_REQUEST['login'] ) ) : '';
	}

	/**
	 * Keep the requested return URL for the custom login form to validate.
	 *
	 * @param string $url The base target URL.
	 * @return string
	 */
	private function withRedirectTo( string $url ): string {
		$requested = isset( $_REQUEST['redirect_to'] ) && is_string( $_REQUEST['redirect_to'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing decision; no state change.
			? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The target page re-validates the host.
			: '';

		if ( '' === $requested ) {
			return $url;
		}

		return add_query_arg( 'redirect_to', rawurlencode( $requested ), $url );
	}

	/**
	 * Whether this request can receive a browser redirect.
	 *
	 * @return bool
	 */
	private function isBrowserContext(): bool {
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return false;
		}

		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return false;
		}

		return true;
	}

	/**
	 * Queue a host-safe redirect and run the request terminator.
	 *
	 * @param string $target The redirect URL.
	 * @return void
	 */
	private function send( string $target ): void {
		wp_safe_redirect( $target );
		( $this->terminator )();
	}
}
