<?php
/**
 * The cookie that carries the reset credential between the link click and the set POST.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Stores and reads the password-reset exchange credential in one cookie.
 *
 * The value is key:login (key first: the key is alphanumeric and a login may
 * contain a colon, so the split is unambiguous). SameSite is Lax, because a
 * Strict cookie is dropped on the mail-client to page to redirect chain. The key
 * check runs at most once per request, shared by the listener and the component.
 *
 * @since 1.0.0
 */
class PasswordResetCookie {

	/**
	 * The cookie-name prefix, joined to COOKIEHASH.
	 *
	 * @var string
	 */
	public const NAME_PREFIX = 'tiermint_rp_';

	/**
	 * Writes the cookie, taking ( name, value, options ). Injected for tests.
	 *
	 * @var callable(string, string, array<string, mixed>): void
	 */
	private $setcookie;

	/**
	 * Whether the key check has run this request.
	 *
	 * @var bool
	 */
	private bool $checked = false;

	/**
	 * The resolved user for a valid key, or null.
	 *
	 * @var \WP_User|null
	 */
	private ?\WP_User $user = null;

	/**
	 * The raw WordPress error code when the key is invalid or expired, or null.
	 *
	 * @var string|null
	 */
	private ?string $keyError = null;

	/**
	 * Wire the cookie writer.
	 *
	 * @param callable|null $setcookie Replaces PHP setcookie in tests.
	 */
	public function __construct( ?callable $setcookie = null ) {
		$this->setcookie = $setcookie ?? static function ( string $name, string $value, array $options ): void {
			if ( headers_sent() ) {
				return;
			}

			setcookie( $name, $value, $options );
		};
	}

	/**
	 * The cookie name for this install.
	 *
	 * @return string
	 */
	public function name(): string {
		return self::NAME_PREFIX . ( defined( 'COOKIEHASH' ) ? COOKIEHASH : '' );
	}

	/**
	 * Store the exchange credential for the set POST.
	 *
	 * @param string $key   The validated reset key.
	 * @param string $login The account login.
	 * @return void
	 */
	public function set( string $key, string $login ): void {
		( $this->setcookie )(
			$this->name(),
			$key . ':' . $login,
			array(
				'expires'  => 0,
				'path'     => $this->path(),
				'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Delete the cookie, reusing the same attributes so the browser drops it.
	 *
	 * @return void
	 */
	public function clear(): void {
		( $this->setcookie )(
			$this->name(),
			'',
			array(
				'expires'  => time() - 3600,
				'path'     => $this->path(),
				'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Read the stored credential, or null when absent or malformed.
	 *
	 * @return array{key: string, login: string}|null
	 */
	public function read(): ?array {
		$name = $this->name();

		if ( ! isset( $_COOKIE[ $name ] ) ) {
			return null;
		}

		$raw = sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) );

		if ( false === strpos( $raw, ':' ) ) {
			return null;
		}

		$parts = explode( ':', $raw, 2 );

		return array(
			'key'   => self::cleanKey( $parts[0] ),
			'login' => $parts[1],
		);
	}

	/**
	 * Strip a reset key to letters and digits, as check_password_reset_key() does.
	 *
	 * @param string $key The key as received.
	 * @return string
	 */
	public static function cleanKey( string $key ): string {
		return (string) preg_replace( '/[^A-Za-z0-9]/', '', $key );
	}

	/**
	 * The user for a valid reset key, memoised for the request.
	 *
	 * @return \WP_User|null
	 */
	public function user(): ?\WP_User {
		$this->check();

		return $this->user;
	}

	/**
	 * The raw WordPress key-check error code, or null when valid or no cookie.
	 *
	 * @return string|null One of 'invalid_key', 'expired_key', or null.
	 */
	public function keyError(): ?string {
		$this->check();

		return $this->keyError;
	}

	/**
	 * Run check_password_reset_key once and cache its result.
	 *
	 * @return void
	 */
	private function check(): void {
		if ( $this->checked ) {
			return;
		}

		$this->checked = true;

		$creds = $this->read();
		if ( null === $creds ) {
			return;
		}

		$result = check_password_reset_key( $creds['key'], $creds['login'] );

		if ( is_wp_error( $result ) ) {
			$this->keyError = (string) $result->get_error_code();

			return;
		}

		$this->user = $result;
	}

	/**
	 * The request path, so the cookie scopes to its own URL.
	 *
	 * @return string
	 */
	private function path(): string {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		return '' !== $path ? $path : '/';
	}
}
