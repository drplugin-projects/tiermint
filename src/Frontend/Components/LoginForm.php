<?php
/**
 * The login-form front-end component.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend\Components;

use DrPlugin\WP\TierMint\Frontend\AbstractComponent;
use DrPlugin\WP\TierMint\Frontend\Kses;
use DrPlugin\WP\TierMint\Frontend\PageResolver;
use DrPlugin\WP\TierMint\Frontend\RenderContext;
use DrPlugin\WP\TierMint\Util\RequestUrl;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the WordPress login form or a logged-in greeting.
 *
 * Use WordPress login state because a user can have no customer record.
 *
 * @since 1.0.0
 */
class LoginForm extends AbstractComponent {

	/**
	 * Query marker for a failed login.
	 *
	 * @var string
	 */
	public const FAILED_MARKER = 'tiermint_login';

	/**
	 * Resolves the account page for the default redirect target.
	 *
	 * @var PageResolver
	 */
	private PageResolver $pages;

	/**
	 * Wire the page resolver used for the account-page redirect default.
	 *
	 * @param PageResolver|null $pages The page resolver, or null for a default one.
	 */
	public function __construct( ?PageResolver $pages = null ) {
		$this->pages = $pages ?? new PageResolver();
	}

	/**
	 * Unique component identifier.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'login-form';
	}

	/**
	 * Machine-readable attribute definitions.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function schema(): array {
		return array(
			'redirect'      => array(
				'type'        => 'string',
				'default'     => '',
				'label'       => __( 'Redirect URL', 'tiermint' ),
				'description' => __( 'Where to send the visitor after logging in or out. Leave empty to stay on the current page.', 'tiermint' ),
				'control'     => 'url',
			),
			'show_register' => array(
				'type'        => 'boolean',
				'default'     => true,
				'label'       => __( 'Show registration link', 'tiermint' ),
				'description' => __( 'Show a "Create an account" link when site registration is open.', 'tiermint' ),
				'control'     => 'toggle',
			),
			'register_url'  => array(
				'type'        => 'string',
				'default'     => '',
				'label'       => __( 'Registration URL', 'tiermint' ),
				'description' => __( 'A specific registration page. When set, the create-account link points here and shows even if open registration is off.', 'tiermint' ),
				'control'     => 'url',
			),
		);
	}

	/**
	 * Render the login form, or the logged-in greeting.
	 *
	 * @param array<string, mixed> $attrs Resolved attribute values.
	 * @param RenderContext        $ctx   Per-request customer/license state.
	 * @return string Escaped HTML.
	 */
	public function render( array $attrs, RenderContext $ctx ): string {
		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();

			return $this->renderTemplate(
				array(
					'logged_in'    => true,
					'display_name' => (string) $user->display_name,
					'account_url'  => $this->accountUrl(),
					'logout_url'   => wp_logout_url( $this->resolveLogoutRedirect( (string) $attrs['redirect'] ) ),
				)
			);
		}

		$loginForm = (string) wp_login_form(
			array(
				'echo'     => false,
				'redirect' => $this->resolveRedirect( (string) $attrs['redirect'] ),
			)
		);

		$loginForm = Kses::stripElements( $loginForm );

		return $this->renderTemplate(
			array(
				'logged_in'        => false,
				'login_error'      => $this->hasFailedLoginMarker(),
				'login_form'       => $loginForm,
				'lostpassword_url' => $this->lostPasswordUrl(),
				'registration_url' => $this->registrationUrl( (bool) $attrs['show_register'], $attrs['register_url'] ?? '' ),
			)
		);
	}

	/**
	 * Resolve the registration link when it is enabled.
	 *
	 * @param bool  $showRegister Whether the registration link is enabled.
	 * @param mixed $registerUrl  An explicit registration URL, or ''.
	 * @return string|null The URL, or null when hidden.
	 */
	private function registrationUrl( bool $showRegister, $registerUrl ): ?string {
		if ( ! $showRegister ) {
			return null;
		}

		$explicit = is_string( $registerUrl ) ? trim( $registerUrl ) : '';

		if ( '' !== $explicit ) {
			$safe = esc_url_raw( $explicit );

			return '' !== $safe ? $safe : null;
		}

		return (bool) get_option( 'users_can_register' ) ? wp_registration_url() : null;
	}

	/**
	 * Resolve a safe post-login redirect.
	 *
	 * @param string $redirectAttr The `redirect` attribute value.
	 * @return string An absolute, sanitized URL.
	 */
	private function resolveRedirect( string $redirectAttr ): string {
		if ( '' !== $redirectAttr ) {
			return esc_url_raw( $redirectAttr );
		}

		$requested = isset( $_GET['redirect_to'] ) && is_string( $_GET['redirect_to'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only seed of the login redirect, validated below against the site's own hosts.
			? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- wp_validate_redirect rejects foreign hosts.
			: '';

		if ( '' !== $requested ) {
			return esc_url_raw( wp_validate_redirect( $requested, $this->accountOrCurrent() ) );
		}

		return $this->accountOrCurrent();
	}

	/**
	 * Resolve the account page or the current page.
	 *
	 * @return string An absolute, sanitized URL.
	 */
	private function accountOrCurrent(): string {
		$account = $this->accountUrl();

		if ( null !== $account ) {
			return $account;
		}

		return esc_url_raw( RequestUrl::current() );
	}

	/**
	 * Resolve the post-logout redirect.
	 *
	 * @param string $redirectAttr The `redirect` attribute value.
	 * @return string An absolute, sanitized URL.
	 */
	private function resolveLogoutRedirect( string $redirectAttr ): string {
		if ( '' !== $redirectAttr ) {
			return esc_url_raw( $redirectAttr );
		}

		return esc_url_raw( RequestUrl::current() );
	}

	/**
	 * The lost-password URL: the branded reset page when published, else wp-login.
	 *
	 * It ignores the replace_wp_login toggle.
	 *
	 * @return string
	 */
	private function lostPasswordUrl(): string {
		$page = $this->pages->url( PageResolver::PASSWORD_RESET );

		return esc_url_raw( ( is_string( $page ) && '' !== $page ) ? $page : wp_lostpassword_url() );
	}

	/**
	 * The account-page URL, or null when no account page is published.
	 *
	 * @return string|null
	 */
	private function accountUrl(): ?string {
		$url = $this->pages->url( PageResolver::ACCOUNT );

		return ( is_string( $url ) && '' !== $url ) ? esc_url_raw( $url ) : null;
	}

	/**
	 * Whether the request has the failed-login marker.
	 *
	 * @return bool
	 */
	private function hasFailedLoginMarker(): bool {
		$marker = isset( $_GET[ self::FAILED_MARKER ] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of a redirect marker; no state change.
			? sanitize_key( wp_unslash( $_GET[ self::FAILED_MARKER ] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of a redirect marker; no state change.
			: '';

		return 'failed' === $marker;
	}
}
