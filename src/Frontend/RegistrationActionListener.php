<?php
/**
 * Server-side handler for the no-JS registration form.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend;

use DrPlugin\WP\TierMint\Core\Hookable;
use DrPlugin\WP\TierMint\Customers\AccountRole;
use DrPlugin\WP\TierMint\Customers\CustomerService;
use DrPlugin\WP\TierMint\Util\RateLimiter;
use DrPlugin\WP\TierMint\Util\RequestUrl;

defined( 'ABSPATH' ) || exit;

/**
 * Handles registration form submissions.
 *
 * Returns generic responses to protect account privacy.
 *
 * @since 1.0.0
 */
class RegistrationActionListener implements Hookable {

	/**
	 * Nonce action for the registration form.
	 *
	 * @var string
	 */
	public const NONCE_ACTION = 'tiermint_register';

	/**
	 * Nonce field name for the registration form.
	 *
	 * @var string
	 */
	public const NONCE_FIELD = '_tiermint_register_nonce';

	/**
	 * The hidden field whose presence marks a registration POST.
	 *
	 * @var string
	 */
	public const ACTION_FIELD = 'tiermint_register';

	/**
	 * The email field name.
	 *
	 * @var string
	 */
	public const EMAIL_FIELD = 'tiermint_email';

	/**
	 * The optional name field.
	 *
	 * @var string
	 */
	public const NAME_FIELD = 'tiermint_name';

	/**
	 * Honeypot field for bot detection.
	 *
	 * @var string
	 */
	public const HONEYPOT_FIELD = 'tiermint_website';

	/**
	 * The query marker a successful registration appends.
	 *
	 * @var string
	 */
	public const SUCCESS_MARKER = 'tiermint_registered';

	/**
	 * The query marker a failed registration appends, carrying a whitelisted code.
	 *
	 * @var string
	 */
	public const ERROR_MARKER = 'tiermint_register_error';

	/**
	 * The rate-limit scope for registration submissions.
	 *
	 * @var string
	 */
	private const RATE_SCOPE = 'register';

	/**
	 * The transient-backed rate limiter throttling submissions by IP.
	 *
	 * @var RateLimiter
	 */
	private RateLimiter $limiter;

	/**
	 * Request terminator used after redirects.
	 *
	 * @var callable(): void
	 */
	private $terminator;

	/**
	 * Customer service for first and last name sync.
	 *
	 * @var CustomerService|null
	 */
	private ?CustomerService $customers;

	/**
	 * Puts the reset page link in the set-password email, or null.
	 *
	 * @var PasswordResetLinks|null
	 */
	private ?PasswordResetLinks $links;

	/**
	 * Wire registration services.
	 *
	 * @param RateLimiter             $limiter    The transient-backed rate limiter.
	 * @param callable|null           $terminator Replaces exit in tests.
	 * @param CustomerService|null    $customers  The customer service, or null to skip the split-name sync.
	 * @param PasswordResetLinks|null $links      The email link rewriter, or null to send WordPress's own link.
	 */
	public function __construct( RateLimiter $limiter, ?callable $terminator = null, ?CustomerService $customers = null, ?PasswordResetLinks $links = null ) {
		$this->limiter    = $limiter;
		$this->terminator = $terminator ?? static function (): void {
			exit;
		};
		$this->customers  = $customers;
		$this->links      = $links;
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
	 * Handle a registration POST, if this request is one.
	 *
	 * @return void
	 */
	public function maybeHandle(): void {
		if ( ! isset( $_POST[ self::ACTION_FIELD ] ) ) {
			return;
		}

		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			// Return a generic error for stale nonces.
			$this->redirect( 'failed' );

			return;
		}

		$limited = $this->limiter->check( self::RATE_SCOPE, $this->clientIp(), $this->rateLimit(), $this->rateWindow() );
		if ( null !== $limited ) {
			$this->redirect( 'throttled' );

			return;
		}

		// Mimic success for bot submissions.
		$honeypot = isset( $_POST[ self::HONEYPOT_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::HONEYPOT_FIELD ] ) ) : '';
		if ( '' !== $honeypot ) {
			$this->redirect( null );

			return;
		}

		// Check registration on the server.
		if ( ! (bool) get_option( 'users_can_register' ) ) {
			$this->redirect( 'closed' );

			return;
		}

		$email = isset( $_POST[ self::EMAIL_FIELD ] ) && is_string( $_POST[ self::EMAIL_FIELD ] ) ? sanitize_email( wp_unslash( $_POST[ self::EMAIL_FIELD ] ) ) : '';
		if ( '' === $email || ! is_email( $email ) ) {
			$this->redirect( 'invalid' );

			return;
		}

		$name = isset( $_POST[ self::NAME_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NAME_FIELD ] ) ) : '';

		/**
 * Filters extra registration validation errors.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, string> $errors Accumulated error codes (empty to allow).
		 * @param string             $email  The submitted email.
		 * @param string             $name   The submitted name, or ''.
		 */
		$errors = (array) apply_filters( 'tiermint_registration_errors', array(), $email, $name );
		if ( array() !== $errors ) {
			$this->redirect( 'invalid' );

			return;
		}

		$login = $this->uniqueLogin( $email );

		// Fire core's registration checks, as register_new_user() does, so CAPTCHA
		// and anti-spam plugins apply. They run for every address, before the
		// existing-email check.
		$coreErrors = new \WP_Error();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, fired as register_new_user() does.
		do_action( 'register_post', $login, $email, $coreErrors );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, fired as register_new_user() does.
		$coreErrors = apply_filters( 'registration_errors', $coreErrors, $login, $email );
		if ( ! $coreErrors instanceof \WP_Error || $coreErrors->has_errors() ) {
			$this->redirect( 'invalid' );

			return;
		}

		// Do not reveal whether the address exists.
		if ( false !== email_exists( $email ) ) {
			$this->redirect( null );

			return;
		}

		$this->redirect( $this->createUser( $email, $name, $login ) > 0 ? null : 'failed' );
	}

	/**
	 * Create the WordPress account and send its setup link.
	 *
	 * @param string $email The validated email.
	 * @param string $name  The submitted name, or ''.
	 * @param string $login The login the core registration hooks saw.
	 * @return int The new user id, or 0 on failure.
	 */
	private function createUser( string $email, string $name, string $login ): int {
		$args = array(
			'user_login' => $login,
			'user_email' => $email,
			'user_pass'  => wp_generate_password( 24 ),
			'role'       => AccountRole::resolve( get_option( 'default_role' ) ),
		);

		if ( '' !== $name ) {
			$args['display_name'] = $name;
		}

		/**
		 * Filters the arguments passed to wp_insert_user when the registration form
		 * mints an account.
		 *
		 * The role cannot go beyond read. A role that can do more becomes subscriber.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $args  The wp_insert_user arguments.
		 * @param string               $email The submitted email.
		 */
		$args = apply_filters( 'tiermint_registration_user_args', $args, $email );

		if ( ! is_array( $args ) ) {
			return 0;
		}

		$args['role'] = AccountRole::resolve( $args['role'] ?? '' );

		$userId = wp_insert_user( $args );

		if ( is_wp_error( $userId ) ) {
			return 0;
		}

		$userId = (int) $userId;

		// The 'user' notification sends only the set-password link; no plaintext
		// password is ever emailed.
		$send = static function () use ( $userId ): void {
			wp_send_new_user_notifications( $userId, 'user' );
		};
		if ( null !== $this->links ) {
			$this->links->withBrandedLinks( $send );
		} else {
			$send();
		}

		$this->syncSplitNames( $userId, $email );

		return $userId;
	}

	/**
	 * Copy saved customer names to the WordPress user.
	 *
	 * @param int    $userId The new WordPress user id.
	 * @param string $email  The buyer email.
	 * @return void
	 */
	private function syncSplitNames( int $userId, string $email ): void {
		if ( null === $this->customers ) {
			return;
		}

		$customer = $this->customers->getByEmail( $email );

		if ( null === $customer ) {
			return;
		}

		if ( null !== $customer->firstName() ) {
			update_user_meta( $userId, 'first_name', $customer->firstName() );
		}

		if ( null !== $customer->lastName() ) {
			update_user_meta( $userId, 'last_name', $customer->lastName() );
		}
	}

	/**
	 * Build a unique login from an email address.
	 *
	 * @param string $email The buyer email.
	 * @return string A login not currently taken.
	 */
	private function uniqueLogin( string $email ): string {
		$parts = explode( '@', $email );
		$base  = sanitize_user( (string) ( $parts[0] ?? '' ), true );

		if ( '' === $base ) {
			$base = 'user';
		}

		$login  = $base;
		$suffix = 2;

		while ( username_exists( $login ) ) {
			$login = $base . $suffix;
			++$suffix;
		}

		return $login;
	}

	/**
	 * The per-IP submission limit for the window.
	 *
	 * @return int
	 */
	private function rateLimit(): int {
		return max( 1, (int) ( $this->rateConfig()['limit'] ?? 10 ) );
	}

	/**
	 * The rate window length in seconds.
	 *
	 * @return int
	 */
	private function rateWindow(): int {
		return max( 1, (int) ( $this->rateConfig()['window'] ?? 3600 ) );
	}

	/**
	 * The filterable rate-limit configuration.
	 *
	 * @return array<string, int>
	 */
	private function rateConfig(): array {
		/**
		 * Filters the registration rate limit: `limit` submissions per `window`
		 * seconds, per client IP.
		 *
		 * @since 1.0.0
		 *
		 * @param array{limit: int, window: int} $config The default limit and window.
		 */
		$config = apply_filters(
			'tiermint_registration_rate_limit',
			array(
				'limit'  => 10,
				'window' => 3600,
			)
		);

		return is_array( $config ) ? $config : array();
	}

	/**
	 * Get the client IP for rate limiting.
	 *
	 * @return string The client IP, or 'unknown' when unavailable.
	 */
	private function clientIp(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';

		return '' !== $ip ? $ip : 'unknown';
	}

	/**
	 * Redirect after a form submission.
	 *
	 * @param string|null $error A whitelisted error code, or null on success.
	 * @return void
	 */
	private function redirect( ?string $error ): void {
		$target = esc_url_raw( RequestUrl::current() );
		$target = remove_query_arg( array( self::ERROR_MARKER, self::SUCCESS_MARKER ), $target );

		$target = null !== $error
			? add_query_arg( self::ERROR_MARKER, $error, $target )
			: add_query_arg( self::SUCCESS_MARKER, '1', $target );

		wp_safe_redirect( $target );
		( $this->terminator )();
	}
}
