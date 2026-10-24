<?php
/**
 * Server-side handler for the branded lost-password and set-new-password forms.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend;

use DrPlugin\WP\TierMint\Core\Hookable;
use DrPlugin\WP\TierMint\Database\Logger;
use DrPlugin\WP\TierMint\Util\RateLimiter;
use DrPlugin\WP\TierMint\Util\RequestUrl;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the three branded reset steps: request a link, exchange the link for a
 * cookie, and set the new password.
 *
 * The request POST is enumeration-safe: every outcome ends in one byte-identical
 * "sent" redirect. On the set POST the CSRF boundary is the cookie/rp_key binding
 * plus check_password_reset_key, never the (shared, logged-out) nonce, and both
 * are hard requirements. Passwords go to reset_password raw and slashed, the same
 * string wp_signon later checks, so a password with a backslash or quote can sign
 * in afterwards. Unlike core, validate_password_reset runs only on the set POST,
 * not on the link GET. A reset never signs the user in.
 *
 * @since 1.0.0
 */
class PasswordResetActionListener implements Hookable {

	/**
	 * The hidden field marking a request-a-link POST.
	 *
	 * @var string
	 */
	public const REQUEST_ACTION_FIELD = 'tiermint_reset_request';

	/**
	 * The hidden field marking a set-new-password POST.
	 *
	 * @var string
	 */
	public const RESET_ACTION_FIELD = 'tiermint_reset_password';

	/**
	 * Nonce action for the request form.
	 *
	 * @var string
	 */
	public const NONCE_REQUEST_ACTION = 'tiermint_reset_request';

	/**
	 * Nonce action for the set form.
	 *
	 * @var string
	 */
	public const NONCE_SET_ACTION = 'tiermint_reset_password';

	/**
	 * Nonce field for the request form.
	 *
	 * @var string
	 */
	public const NONCE_REQUEST_FIELD = '_tiermint_reset_request_nonce';

	/**
	 * Nonce field for the set form.
	 *
	 * @var string
	 */
	public const NONCE_SET_FIELD = '_tiermint_reset_password_nonce';

	/**
	 * The email-or-username field on the request form.
	 *
	 * @var string
	 */
	public const LOGIN_FIELD = 'tiermint_login';

	/**
	 * The new-password field on the set form.
	 *
	 * @var string
	 */
	public const PASS1_FIELD = 'tiermint_pass1';

	/**
	 * The confirm-password field on the set form.
	 *
	 * @var string
	 */
	public const PASS2_FIELD = 'tiermint_pass2';

	/**
	 * The hidden reset-key field on the set form (the double-submit CSRF token).
	 *
	 * @var string
	 */
	public const KEY_FIELD = 'tiermint_rp_key';

	/**
	 * Honeypot field for bot detection on the request form.
	 *
	 * @var string
	 */
	public const HONEYPOT_FIELD = 'tiermint_website';

	/**
	 * The query marker carrying a state (sent, set, done).
	 *
	 * @var string
	 */
	public const STATUS_MARKER = 'tiermint_reset';

	/**
	 * The query marker carrying a whitelisted error code.
	 *
	 * @var string
	 */
	public const ERROR_MARKER = 'tiermint_reset_error';

	/**
	 * Rate scope for link requests, per client IP.
	 *
	 * @var string
	 */
	public const SCOPE_REQUEST = 'password_reset_request';

	/**
	 * Rate scope for link requests, per submitted login (anti mail-bomb).
	 *
	 * @var string
	 */
	public const SCOPE_TARGET = 'password_reset_target';

	/**
	 * Rate scope for set POSTs, per client IP (its own scope so a request flood
	 * behind one NAT cannot block a real user finishing the set step).
	 *
	 * @var string
	 */
	public const SCOPE_SET = 'password_reset_set';

	/**
	 * The transient-backed rate limiter.
	 *
	 * @var RateLimiter
	 */
	private RateLimiter $limiter;

	/**
	 * Resolves the reset page for routing and self-redirects.
	 *
	 * @var PageResolver
	 */
	private PageResolver $pages;

	/**
	 * The exchange-credential cookie.
	 *
	 * @var PasswordResetCookie
	 */
	private PasswordResetCookie $cookie;

	/**
	 * Request terminator, injected for tests.
	 *
	 * @var callable(): void
	 */
	private $terminator;

	/**
	 * Audit logger, or null.
	 *
	 * @var Logger|null
	 */
	private ?Logger $logger;

	/**
	 * Puts the reset page link in the reset email.
	 *
	 * @var PasswordResetLinks
	 */
	private PasswordResetLinks $links;

	/**
	 * Wire the handler.
	 *
	 * @param RateLimiter             $limiter    The transient-backed rate limiter.
	 * @param PageResolver            $pages      The storefront page resolver.
	 * @param PasswordResetCookie     $cookie     The exchange-credential cookie.
	 * @param callable|null           $terminator Replaces exit in tests.
	 * @param Logger|null             $logger     Audit logger, or null to skip logging.
	 * @param PasswordResetLinks|null $links      The email link rewriter, or null for a default one.
	 */
	public function __construct( RateLimiter $limiter, PageResolver $pages, PasswordResetCookie $cookie, ?callable $terminator = null, ?Logger $logger = null, ?PasswordResetLinks $links = null ) {
		$this->limiter    = $limiter;
		$this->pages      = $pages;
		$this->cookie     = $cookie;
		$this->terminator = $terminator ?? static function (): void {
			exit;
		};
		$this->logger     = $logger;
		$this->links      = $links ?? new PasswordResetLinks( $pages );
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'template_redirect', array( $this, 'maybeHandle' ) );
	}

	/**
	 * Dispatch the request to the matching branch, if any.
	 *
	 * @return void
	 */
	public function maybeHandle(): void {
		$this->maybeSendNoCacheHeaders();

		if ( isset( $_POST[ self::REQUEST_ACTION_FIELD ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Routing only; the nonce is verified in handleRequest().
			$this->handleRequest();

			return;
		}

		if ( isset( $_POST[ self::RESET_ACTION_FIELD ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Routing only; the nonce is verified in handleSet().
			$this->handleSet();

			return;
		}

		if ( $this->isResetLinkGet() ) {
			$this->handleResetLink();
		}
	}

	/**
	 * Send no-cache headers on the reset flow before any output.
	 *
	 * @return void
	 */
	private function maybeSendNoCacheHeaders(): void {
		if ( ! $this->isResetContext() ) {
			return;
		}

		nocache_headers();

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- The well-known page-cache opt-out constant, not ours to prefix.
		}
	}

	/**
	 * Whether this is the reset page, or the reset cookie holds a valid key.
	 *
	 * A valid cookie makes any page that shows the form render the live key, so
	 * that page must not be cached. Markers alone carry no secret.
	 *
	 * @return bool
	 */
	private function isResetContext(): bool {
		if ( $this->pages->isPage( PageResolver::PASSWORD_RESET, (int) get_queried_object_id() ) ) {
			return true;
		}

		return null !== $this->cookie->user();
	}

	/**
	 * Handle a request-a-link POST, ending in the enumeration-safe sent state.
	 *
	 * @return void
	 */
	private function handleRequest(): void {
		if ( ! $this->verifyNonce( self::NONCE_REQUEST_FIELD, self::NONCE_REQUEST_ACTION ) ) {
			// Keep the cookie: a genuine retry should still work.
			$this->redirect( 'failed', null );

			return;
		}

		if ( $this->throttled( self::SCOPE_REQUEST, $this->clientIp() ) ) {
			$this->redirect( 'throttled', null );

			return;
		}

		// Every path from here ends in the sent state, so drop any stale cookie.
		$this->cookie->clear();

		// Mimic success for bot submissions, with no lookup.
		if ( '' !== $this->honeypotValue() ) {
			$this->redirect( null, 'sent' );

			return;
		}

		$login = $this->requestLogin();
		if ( '' === $login ) {
			$this->redirect( 'invalid', null );

			return;
		}

		// A spent per-target window still fakes success: 'throttled' would leak the
		// address, and the byte-identical sent redirect must not vary.
		if ( $this->throttled( self::SCOPE_TARGET, strtolower( $login ) ) ) {
			$this->redirect( null, 'sent' );

			return;
		}

		$result = $this->links->withBrandedLinks( static fn () => retrieve_password( $login ) );

		if ( is_wp_error( $result ) ) {
			$this->logRetrieveError( $result );
		}

		$this->redirect( null, 'sent' );
	}

	/**
	 * Validate the reset link on arrival, store the credential, strip it from the URL.
	 *
	 * @return void
	 */
	private function handleResetLink(): void {
		$key   = $this->linkKey();
		$login = $this->linkLogin();

		$result = check_password_reset_key( $key, $login );

		if ( is_wp_error( $result ) ) {
			$this->cookie->clear();
			$this->redirect( $this->keyErrorMarker( (string) $result->get_error_code() ), null );

			return;
		}

		$this->cookie->set( $key, $login );
		$this->redirect( null, 'set' );
	}

	/**
	 * Handle a set-new-password POST.
	 *
	 * @return void
	 */
	private function handleSet(): void {
		if ( ! $this->verifyNonce( self::NONCE_SET_FIELD, self::NONCE_SET_ACTION ) ) {
			// Keep the cookie: the user is mid-flow and may resubmit.
			$this->redirect( 'failed', null );

			return;
		}

		if ( $this->throttled( self::SCOPE_SET, $this->clientIp() ) ) {
			$this->redirect( 'throttled', null );

			return;
		}

		$creds = $this->cookie->read();
		if ( null === $creds ) {
			$this->cookie->clear();
			$this->redirect( 'invalidkey', null );

			return;
		}

		$user = $this->cookie->user();
		if ( null === $user ) {
			$marker = $this->keyErrorMarker( (string) $this->cookie->keyError() );
			$this->cookie->clear();
			$this->redirect( $marker, null );

			return;
		}

		// CSRF boundary: the posted rp_key must match the cookie key. Never skipped.
		if ( ! hash_equals( $creds['key'], $this->postedKey() ) ) {
			$this->redirect( 'invalidkey', null );

			return;
		}

		$pass1 = $this->postedPass( self::PASS1_FIELD );
		$pass2 = $this->postedPass( self::PASS2_FIELD );

		if ( $pass1 !== $pass2 ) {
			$this->redirect( 'mismatch', null );

			return;
		}

		if ( ! $this->lengthAllowed( $pass1 ) ) {
			$this->redirect( 'short', null );

			return;
		}

		if ( $this->policyBlocks( $user ) ) {
			$this->redirect( 'policy', null );

			return;
		}

		// Pass the raw slashed value: the same string wp_signon() later checks from
		// $_POST['pwd'], so a password with a backslash or quote can sign in.
		reset_password( $user, $pass1 );
		$this->cookie->clear();
		$this->redirect( null, 'done' );
	}

	/**
	 * Whether the request is a reset-link GET on the reset page with key and login.
	 *
	 * @return bool
	 */
	private function isResetLinkGet(): bool {
		if ( 'GET' !== $this->method() ) {
			return false;
		}

		if ( '' === $this->linkKey() || '' === $this->linkLogin() ) {
			return false;
		}

		return $this->pages->isPage( PageResolver::PASSWORD_RESET, (int) get_queried_object_id() );
	}

	/**
	 * Map a WordPress key-check error code to our marker.
	 *
	 * @param string $code The WordPress error code.
	 * @return string 'expiredkey' or 'invalidkey'.
	 */
	private function keyErrorMarker( string $code ): string {
		return 'expired_key' === $code ? 'expiredkey' : 'invalidkey';
	}

	/**
	 * Log a retrieve_password failure without leaking the login.
	 *
	 * @param \WP_Error $error The WordPress error.
	 * @return void
	 */
	private function logRetrieveError( \WP_Error $error ): void {
		if ( null === $this->logger ) {
			return;
		}

		$code = (string) $error->get_error_code();
		// A transport failure is the merchant's to fix; other codes (unknown account,
		// disallowed reset, or a code a security plugin adds) are benign here.
		$stream = 'retrieve_password_email_failure' === $code ? 'error' : 'auth';

		$this->logger->log( $stream, 'Password reset request could not be completed.', array( 'code' => $code ) );
	}

	/**
	 * Verify a form nonce.
	 *
	 * @param string $field  The nonce field name.
	 * @param string $action The nonce action.
	 * @return bool
	 */
	private function verifyNonce( string $field, string $action ): bool {
		$nonce = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';

		return (bool) wp_verify_nonce( $nonce, $action );
	}

	/**
	 * Whether the scope is over its limit for this identifier.
	 *
	 * @param string $scope      The rate scope.
	 * @param string $identifier The caller identifier.
	 * @return bool
	 */
	private function throttled( string $scope, string $identifier ): bool {
		$config = $this->rateConfig( $scope );

		return null !== $this->limiter->check( $scope, $identifier, $config['limit'], $config['window'] );
	}

	/**
	 * The filterable limit and window for a scope.
	 *
	 * @param string $scope The rate scope.
	 * @return array{limit: int, window: int}
	 */
	private function rateConfig( string $scope ): array {
		$defaults = array(
			self::SCOPE_REQUEST => array(
				'limit'  => 5,
				'window' => 3600,
			),
			self::SCOPE_TARGET  => array(
				'limit'  => 3,
				'window' => 3600,
			),
			self::SCOPE_SET     => array(
				'limit'  => 10,
				'window' => 3600,
			),
		);

		/**
		 * Filters the password-reset rate limits, keyed by scope, each `limit` per
		 * `window` seconds.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, array{limit: int, window: int}> $limits The default limits.
		 */
		$config = apply_filters( 'tiermint_password_reset_rate_limits', $defaults );
		$row    = is_array( $config ) && isset( $config[ $scope ] ) && is_array( $config[ $scope ] ) ? $config[ $scope ] : $defaults[ $scope ];

		return array(
			'limit'  => max( 1, (int) ( $row['limit'] ?? $defaults[ $scope ]['limit'] ) ),
			'window' => max( 1, (int) ( $row['window'] ?? $defaults[ $scope ]['window'] ) ),
		);
	}

	/**
	 * The submitted email or username, sanitized and capped.
	 *
	 * @return string
	 */
	private function requestLogin(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handleRequest.
		$login = isset( $_POST[ self::LOGIN_FIELD ] ) && is_string( $_POST[ self::LOGIN_FIELD ] ) ? sanitize_user( wp_unslash( $_POST[ self::LOGIN_FIELD ] ) ) : '';

		return strlen( $login ) > 254 ? substr( $login, 0, 254 ) : $login;
	}

	/**
	 * A posted password: trimmed, otherwise raw and slashed (never unslashed, for
	 * parity with what wp_signon later checks).
	 *
	 * @param string $field The field name.
	 * @return string
	 */
	private function postedPass( string $field ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Passwords stay raw, as in WordPress core: sanitizing would change valid passwords.
		return isset( $_POST[ $field ] ) && is_string( $_POST[ $field ] ) ? trim( $_POST[ $field ] ) : '';
	}

	/**
	 * The posted rp_key, stripped to letters and digits.
	 *
	 * @return string
	 */
	private function postedKey(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handleSet.
		$key = isset( $_POST[ self::KEY_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::KEY_FIELD ] ) ) : '';

		return PasswordResetCookie::cleanKey( $key );
	}

	/**
	 * Whether the password meets the minimum length (typed characters) and the
	 * 4096-byte cap (what core hashes).
	 *
	 * @param string $pass1 The trimmed, slashed password.
	 * @return bool
	 */
	private function lengthAllowed( string $pass1 ): bool {
		/**
		 * Filters the minimum password length for the branded reset form.
		 *
		 * @since 1.0.0
		 *
		 * @param int $length The minimum number of characters.
		 */
		$min   = max( 1, (int) apply_filters( 'tiermint_password_reset_min_length', 8 ) );
		$typed = mb_strlen( (string) wp_unslash( $pass1 ) );

		return $typed >= $min && strlen( $pass1 ) <= 4096;
	}

	/**
	 * Whether a password-policy plugin blocks the new password.
	 *
	 * Unlike core, this runs only on the set POST, never on the link GET.
	 *
	 * @param \WP_User $user The account being reset.
	 * @return bool
	 */
	private function policyBlocks( \WP_User $user ): bool {
		$errors = new \WP_Error();

		// Fire WordPress core's own validation hook so password-policy plugins apply.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, deliberately re-fired on the set POST.
		do_action( 'validate_password_reset', $errors, $user );

		return $errors->has_errors();
	}

	/**
	 * The honeypot field's sanitized value, or '' when absent.
	 *
	 * @return string
	 */
	private function honeypotValue(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handleRequest.
		return isset( $_POST[ self::HONEYPOT_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::HONEYPOT_FIELD ] ) ) : '';
	}

	/**
	 * The reset key from the emailed link, stripped to letters and digits.
	 *
	 * @return string
	 */
	private function linkKey(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The key is the credential; check_password_reset_key validates it.
		$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';

		return PasswordResetCookie::cleanKey( $key );
	}

	/**
	 * The account login from the emailed link, sanitized.
	 *
	 * @return string
	 */
	private function linkLogin(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked together with the key by check_password_reset_key.
		return isset( $_GET['login'] ) && is_string( $_GET['login'] ) ? sanitize_user( wp_unslash( $_GET['login'] ) ) : '';
	}

	/**
	 * The request method in upper case.
	 *
	 * @return string
	 */
	private function method(): string {
		return isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: 'GET';
	}

	/**
	 * The client IP for rate limiting, or 'unknown'.
	 *
	 * @return string
	 */
	private function clientIp(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';

		return '' !== $ip ? $ip : 'unknown';
	}

	/**
	 * Redirect back to the current page with a state or error marker.
	 *
	 * @param string|null $error  A whitelisted error code, or null.
	 * @param string|null $status A state marker (sent, set, done), or null.
	 * @return void
	 */
	private function redirect( ?string $error, ?string $status ): void {
		$target = remove_query_arg(
			array( 'key', 'login', 'wp_lang', 'action', self::STATUS_MARKER, self::ERROR_MARKER ),
			$this->selfUrl()
		);

		if ( null !== $error ) {
			$target = add_query_arg( self::ERROR_MARKER, $error, $target );
		} elseif ( null !== $status ) {
			$target = add_query_arg( self::STATUS_MARKER, $status, $target );
		}

		wp_safe_redirect( esc_url_raw( $target ) );
		( $this->terminator )();
	}

	/**
	 * The current page's own URL, from the queried permalink on a singular request
	 * so a subdirectory install is not double-pathed, else the current request URL.
	 *
	 * @return string
	 */
	private function selfUrl(): string {
		if ( is_singular() ) {
			$id = (int) get_queried_object_id();

			if ( $id > 0 ) {
				$permalink = get_permalink( $id );

				if ( is_string( $permalink ) && '' !== $permalink ) {
					return $permalink;
				}
			}
		}

		return RequestUrl::current();
	}
}
