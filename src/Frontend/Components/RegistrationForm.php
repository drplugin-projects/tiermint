<?php
/**
 * The registration-form front-end component.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend\Components;

use DrPlugin\WP\TierMint\Frontend\AbstractComponent;
use DrPlugin\WP\TierMint\Frontend\Kses;
use DrPlugin\WP\TierMint\Frontend\PageResolver;
use DrPlugin\WP\TierMint\Frontend\RegistrationActionListener;
use DrPlugin\WP\TierMint\Frontend\RenderContext;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the account registration form.
 *
 * The listener validates every submission.
 *
 * @since 1.0.0
 */
class RegistrationForm extends AbstractComponent {

	/**
	 * Error codes shown with generic messages.
	 *
	 * @var array<string, string>
	 */
	private const ERROR_CODES = array( 'invalid', 'failed', 'closed', 'throttled' );

	/**
	 * Resolves the login page for the sign-in link fallback.
	 *
	 * @var PageResolver
	 */
	private PageResolver $pages;

	/**
	 * Wire the page resolver used for the sign-in link fallback.
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
		return 'registration-form';
	}

	/**
	 * Machine-readable attribute definitions.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function schema(): array {
		return array(
			'show_name' => array(
				'type'        => 'boolean',
				'default'     => true,
				'label'       => __( 'Show the name field', 'tiermint' ),
				'description' => __( 'Collect an optional display name alongside the email.', 'tiermint' ),
				'control'     => 'toggle',
			),
			'login_url' => array(
				'type'        => 'string',
				'default'     => '',
				'label'       => __( 'Sign-in URL', 'tiermint' ),
				'description' => __( 'Where the "already have an account" link points. Falls back to the sign-in page.', 'tiermint' ),
				'control'     => 'url',
			),
		);
	}

	/**
	 * Render the form, or the state that replaces it (signed in, closed, or done).
	 *
	 * @param array<string, mixed> $attrs Resolved attribute values.
	 * @param RenderContext        $ctx   Per-request customer/license state.
	 * @return string Escaped HTML.
	 */
	public function render( array $attrs, RenderContext $ctx ): string {
		if ( is_user_logged_in() ) {
			return $this->renderTemplate(
				array(
					'state'       => 'signed_in',
					'account_url' => $this->pageUrl( PageResolver::ACCOUNT ),
				)
			);
		}

		if ( ! (bool) get_option( 'users_can_register' ) ) {
			return $this->renderTemplate( array( 'state' => 'closed' ) );
		}

		if ( $this->hasSuccessMarker() ) {
			return $this->renderTemplate(
				array(
					'state'     => 'success',
					'login_url' => $this->loginUrl( $attrs['login_url'] ?? '' ),
				)
			);
		}

		// Core's hook, so CAPTCHA and anti-spam plugins can add their fields.
		ob_start();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, fired as wp-login.php does.
		do_action( 'register_form' );
		$coreFields = Kses::stripElements( (string) ob_get_clean() );

		return $this->renderTemplate(
			array(
				'state'          => 'form',
				'core_fields'    => $coreFields,
				'error'          => $this->errorNotice(),
				'show_name'      => (bool) $attrs['show_name'],
				'login_url'      => $this->loginUrl( $attrs['login_url'] ?? '' ),
				'nonce_action'   => RegistrationActionListener::NONCE_ACTION,
				'nonce_field'    => RegistrationActionListener::NONCE_FIELD,
				'action_field'   => RegistrationActionListener::ACTION_FIELD,
				'email_field'    => RegistrationActionListener::EMAIL_FIELD,
				'name_field'     => RegistrationActionListener::NAME_FIELD,
				'honeypot_field' => RegistrationActionListener::HONEYPOT_FIELD,
			)
		);
	}

	/**
	 * The explicit sign-in URL, login page, or null.
	 *
	 * @param mixed $loginUrlAttr The login_url attribute, or ''.
	 * @return string|null
	 */
	private function loginUrl( $loginUrlAttr ): ?string {
		$explicit = is_string( $loginUrlAttr ) ? trim( $loginUrlAttr ) : '';

		if ( '' !== $explicit ) {
			$safe = esc_url_raw( $explicit );

			return '' !== $safe ? $safe : null;
		}

		return $this->pageUrl( PageResolver::LOGIN );
	}

	/**
	 * A published page's URL for a key, or null.
	 *
	 * @param string $key One of the PageResolver key constants.
	 * @return string|null
	 */
	private function pageUrl( string $key ): ?string {
		$url = $this->pages->url( $key );

		return ( is_string( $url ) && '' !== $url ) ? esc_url_raw( $url ) : null;
	}

	/**
	 * Whether the request carries the success marker the listener appends.
	 *
	 * @return bool
	 */
	private function hasSuccessMarker(): bool {
		$marker = isset( $_GET[ RegistrationActionListener::SUCCESS_MARKER ] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of a redirect marker; no state change.
			? sanitize_key( wp_unslash( $_GET[ RegistrationActionListener::SUCCESS_MARKER ] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of a redirect marker; no state change.
			: '';

		return '1' === $marker;
	}

	/**
	 * Error notice data for a known marker.
	 *
	 * @return array{show: bool, text: string}
	 */
	private function errorNotice(): array {
		$code = isset( $_GET[ RegistrationActionListener::ERROR_MARKER ] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of a redirect marker; no state change.
			? sanitize_key( wp_unslash( $_GET[ RegistrationActionListener::ERROR_MARKER ] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of a redirect marker; no state change.
			: '';

		if ( ! in_array( $code, self::ERROR_CODES, true ) ) {
			return array(
				'show' => false,
				'text' => '',
			);
		}

		$messages = array(
			'invalid'   => __( 'We could not complete your registration. Please check your details and try again.', 'tiermint' ),
			'failed'    => __( 'Something went wrong. Please try again.', 'tiermint' ),
			'closed'    => __( 'Registration is currently closed.', 'tiermint' ),
			'throttled' => __( 'Too many attempts. Please wait a few minutes and try again.', 'tiermint' ),
		);

		return array(
			'show' => true,
			'text' => $messages[ $code ],
		);
	}
}
