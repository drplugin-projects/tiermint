<?php
/**
 * The password-reset front-end component (request a link and set a new password).
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend\Components;

use DrPlugin\WP\TierMint\Frontend\AbstractComponent;
use DrPlugin\WP\TierMint\Frontend\PageResolver;
use DrPlugin\WP\TierMint\Frontend\PasswordResetActionListener;
use DrPlugin\WP\TierMint\Frontend\PasswordResetCookie;
use DrPlugin\WP\TierMint\Frontend\RenderContext;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the branded lost-password and set-new-password form.
 *
 * The listener validates every submission and drives the state through the query
 * marker and the reset cookie. Field, nonce and action names come from the
 * listener so the two cannot drift.
 *
 * @since 1.0.0
 */
class PasswordResetForm extends AbstractComponent {

	/**
	 * Every error code the component can render, across states.
	 *
	 * @var array<int, string>
	 */
	public const ERROR_CODES = array( 'invalid', 'failed', 'throttled', 'mismatch', 'short', 'policy', 'invalidkey', 'expiredkey' );

	/**
	 * Error codes shown on the request form.
	 *
	 * @var array<int, string>
	 */
	private const REQUEST_ERRORS = array( 'invalid', 'failed', 'throttled', 'invalidkey', 'expiredkey' );

	/**
	 * Error codes shown on the set-new-password form.
	 *
	 * @var array<int, string>
	 */
	private const RESET_ERRORS = array( 'mismatch', 'short', 'policy', 'failed', 'throttled' );

	/**
	 * Resolves the sign-in and account pages.
	 *
	 * @var PageResolver
	 */
	private PageResolver $pages;

	/**
	 * The reset cookie, shared with the listener so the key check runs once.
	 *
	 * @var PasswordResetCookie
	 */
	private PasswordResetCookie $cookie;

	/**
	 * Wire the page resolver and reset cookie.
	 *
	 * @param PageResolver|null        $pages  The page resolver, or null for a default one.
	 * @param PasswordResetCookie|null $cookie The reset cookie, or null for a default one.
	 */
	public function __construct( ?PageResolver $pages = null, ?PasswordResetCookie $cookie = null ) {
		$this->pages  = $pages ?? new PageResolver();
		$this->cookie = $cookie ?? new PasswordResetCookie();
	}

	/**
	 * Unique component identifier.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'password-reset-form';
	}

	/**
	 * Machine-readable attribute definitions.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function schema(): array {
		return array(
			'login_url' => array(
				'type'        => 'string',
				'default'     => '',
				'label'       => __( 'Sign-in URL', 'tiermint' ),
				'description' => __( 'Where the sign-in links point. Falls back to the sign-in page, then the WordPress login screen.', 'tiermint' ),
				'control'     => 'url',
			),
		);
	}

	/**
	 * Render the state the request resolves to.
	 *
	 * @param array<string, mixed> $attrs Resolved attribute values.
	 * @param RenderContext        $ctx   Per-request customer/license state.
	 * @return string Escaped HTML.
	 */
	public function render( array $attrs, RenderContext $ctx ): string {
		switch ( $this->resolveState() ) {
			case 'done':
				return $this->renderTemplate(
					array(
						'state'     => 'done',
						'login_url' => $this->loginUrl( $attrs['login_url'] ?? '' ),
					)
				);
			case 'reset':
				return $this->renderTemplate( $this->resetData() );
			case 'sent':
				return $this->renderTemplate(
					array(
						'state'     => 'sent',
						'login_url' => $this->loginUrl( $attrs['login_url'] ?? '' ),
					)
				);
			case 'signed_in':
				return $this->renderTemplate(
					array(
						'state'       => 'signed_in',
						'account_url' => $this->accountUrl(),
					)
				);
			default:
				return $this->renderTemplate( $this->requestData() );
		}
	}

	/**
	 * Resolve the single state to render.
	 *
	 * A valid cookie wins over the signed-in check (wp-login parity: the cookie's
	 * account is the one being reset, not the session's).
	 *
	 * @return string
	 */
	private function resolveState(): string {
		$status = $this->statusMarker();

		if ( 'done' === $status ) {
			return 'done';
		}

		if ( null !== $this->cookie->user() ) {
			return 'reset';
		}

		if ( 'sent' === $status ) {
			return 'sent';
		}

		if ( is_user_logged_in() ) {
			return 'signed_in';
		}

		return 'request';
	}

	/**
	 * View data for the set-new-password form.
	 *
	 * @return array<string, mixed>
	 */
	private function resetData(): array {
		$creds = $this->cookie->read() ?? array(
			'key'   => '',
			'login' => '',
		);

		return array(
			'state'        => 'reset',
			'error'        => $this->errorNotice( self::RESET_ERRORS ),
			'login'        => (string) $creds['login'],
			'rp_key'       => (string) $creds['key'],
			'min_length'   => $this->minLength(),
			'nonce_action' => PasswordResetActionListener::NONCE_SET_ACTION,
			'nonce_field'  => PasswordResetActionListener::NONCE_SET_FIELD,
			'action_field' => PasswordResetActionListener::RESET_ACTION_FIELD,
			'key_field'    => PasswordResetActionListener::KEY_FIELD,
			'pass1_field'  => PasswordResetActionListener::PASS1_FIELD,
			'pass2_field'  => PasswordResetActionListener::PASS2_FIELD,
		);
	}

	/**
	 * View data for the request-a-link form.
	 *
	 * @return array<string, mixed>
	 */
	private function requestData(): array {
		return array(
			'state'          => 'request',
			'error'          => $this->errorNotice( self::REQUEST_ERRORS ),
			'nonce_action'   => PasswordResetActionListener::NONCE_REQUEST_ACTION,
			'nonce_field'    => PasswordResetActionListener::NONCE_REQUEST_FIELD,
			'action_field'   => PasswordResetActionListener::REQUEST_ACTION_FIELD,
			'login_field'    => PasswordResetActionListener::LOGIN_FIELD,
			'honeypot_field' => PasswordResetActionListener::HONEYPOT_FIELD,
		);
	}

	/**
	 * The state marker carried in the query string.
	 *
	 * @return string
	 */
	private function statusMarker(): string {
		return isset( $_GET[ PasswordResetActionListener::STATUS_MARKER ] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of a redirect marker; no state change.
			? sanitize_key( wp_unslash( $_GET[ PasswordResetActionListener::STATUS_MARKER ] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of a redirect marker; no state change.
			: '';
	}

	/**
	 * Error notice data for a code that belongs to the current state.
	 *
	 * @param array<int, string> $allowed The codes valid for this state.
	 * @return array{show: bool, text: string}
	 */
	private function errorNotice( array $allowed ): array {
		$code = isset( $_GET[ PasswordResetActionListener::ERROR_MARKER ] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of a redirect marker; no state change.
			? sanitize_key( wp_unslash( $_GET[ PasswordResetActionListener::ERROR_MARKER ] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of a redirect marker; no state change.
			: '';

		if ( ! in_array( $code, $allowed, true ) ) {
			return array(
				'show' => false,
				'text' => '',
			);
		}

		return array(
			'show' => true,
			'text' => $this->message( $code ),
		);
	}

	/**
	 * The generic notice text for an error code.
	 *
	 * @param string $code A whitelisted error code.
	 * @return string
	 */
	private function message( string $code ): string {
		$messages = array(
			'invalid'    => __( 'We could not process that request. Please check the address and try again.', 'tiermint' ),
			'failed'     => __( 'Something went wrong. Please try again.', 'tiermint' ),
			'throttled'  => __( 'Too many attempts. Please wait a few minutes and try again.', 'tiermint' ),
			'mismatch'   => __( 'The two passwords do not match.', 'tiermint' ),
			'policy'     => __( "That password does not meet this site's password rules.", 'tiermint' ),
			'invalidkey' => __( 'This password reset link is invalid or has already been used. Please request a new one.', 'tiermint' ),
			'expiredkey' => __( 'This password reset link has expired. Please request a new one.', 'tiermint' ),
		);

		if ( 'short' === $code ) {
			return sprintf(
				/* translators: %d: the minimum password length. */
				__( 'Please choose a password of at least %d characters (and no more than 4096).', 'tiermint' ),
				$this->minLength()
			);
		}

		return $messages[ $code ] ?? '';
	}

	/**
	 * The filtered minimum password length.
	 *
	 * @return int
	 */
	private function minLength(): int {
		/** This filter is documented in src/Frontend/PasswordResetActionListener.php */
		return max( 1, (int) apply_filters( 'tiermint_password_reset_min_length', 8 ) );
	}

	/**
	 * The sign-in URL: an explicit attribute, then the login page, then wp-login.
	 *
	 * @param mixed $loginUrlAttr The login_url attribute, or ''.
	 * @return string
	 */
	private function loginUrl( $loginUrlAttr ): string {
		$explicit = is_string( $loginUrlAttr ) ? trim( $loginUrlAttr ) : '';

		if ( '' !== $explicit ) {
			$safe = esc_url_raw( $explicit );

			if ( '' !== $safe ) {
				return $safe;
			}
		}

		$page = $this->pages->url( PageResolver::LOGIN );

		return ( is_string( $page ) && '' !== $page ) ? esc_url_raw( $page ) : esc_url_raw( wp_login_url() );
	}

	/**
	 * The account-page URL, or null when none is published.
	 *
	 * @return string|null
	 */
	private function accountUrl(): ?string {
		$url = $this->pages->url( PageResolver::ACCOUNT );

		return ( is_string( $url ) && '' !== $url ) ? esc_url_raw( $url ) : null;
	}
}
