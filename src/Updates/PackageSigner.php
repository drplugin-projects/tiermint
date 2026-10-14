<?php
/**
 * Signed download URL utility.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Updates;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Util\Crypto;
use DrPlugin\WP\TierMint\Util\CryptoException;
use InvalidArgumentException;

/**
 * Mints and verifies signed download URLs.
 *
 * Never expose license keys. Package and order grants use separate canonical strings.
 *
 * @since 1.0.0
 */
class PackageSigner {

	/**
	 * Encrypted signing-secret option.
	 *
	 * @var string
	 */
	public const SECRET_OPTION = 'tiermint_secret_download_signing';

	/**
	 * Verdict: signature genuine and within its lifetime.
	 *
	 * @var string
	 */
	public const VERDICT_VALID = 'valid';

	/**
	 * Verdict for a changed signed field.
	 *
	 * @var string
	 */
	public const VERDICT_INVALID_SIGNATURE = 'invalid_signature';

	/**
	 * Verdict for an expired valid signature.
	 *
	 * @var string
	 */
	public const VERDICT_EXPIRED = 'expired';

	/**
	 * Default URL lifetime in seconds.
	 *
	 * @var int
	 */
	private const DEFAULT_TTL = 300;

	/**
	 * Minimum valid URL lifetime.
	 *
	 * @var int
	 */
	private const MIN_TTL = 60;

	/**
	 * Domain-separation tag leading the order-file canonical string.
	 *
	 * @var string
	 */
	private const ORDER_CONTEXT = 'order';

	/**
	 * Generic grant context pattern.
	 *
	 * Prevent a generic grant from matching a package canonical string.
	 *
	 * @var string
	 */
	private const CONTEXT_PATTERN = '/^[a-z][a-z0-9_]*\z/';

	/**
	 * Canonical field separator.
	 *
	 * @var string
	 */
	private const SEPARATOR = '|';

	/**
	 * Query parameters added by grantUrl().
	 *
	 * @var string[]
	 */
	private const APPENDED_PARAMS = array( 'expires', 'signature' );

	/**
	 * Secrets-at-rest codec for the signing secret.
	 *
	 * @var Crypto
	 */
	private Crypto $crypto;

	/**
	 * Per-request decrypted signing secret.
	 *
	 * @var string|null
	 */
	private ?string $secret = null;

	/**
	 * Wire the signer with the secrets codec.
	 *
	 * @since 1.0.0
	 *
	 * @param Crypto $crypto Encrypts and decrypts the stored signing secret.
	 */
	public function __construct( Crypto $crypto ) {
		$this->crypto = $crypto;
	}

	/**
	 * Mint a signed package-download URL.
	 *
	 * @since 1.0.0
	 *
	 * @param int      $productId    Product the release belongs to.
	 * @param int      $releaseId    Release whose package the URL grants.
	 * @param int      $licenseKeyId Numeric id of the granting license row.
	 * @param string   $siteHash     The 64-hex normalized site hash of the activation.
	 * @param int|null $now          Unix time to compute expiry from; null means current time.
	 * @param int|null $ttl          Per-call lifetime override in seconds; null keeps the
	 *                               built-in 300-second default. See {@see ttl()} for the
	 *                               override/filter/floor precedence.
	 * @return string Absolute REST URL carrying exactly the canonical fields plus the signature.
	 */
	public function packageDownloadUrl( int $productId, int $releaseId, int $licenseKeyId, string $siteHash, ?int $now = null, ?int $ttl = null ): string {
		$expires = ( $now ?? time() ) + $this->ttl( $ttl );

		$fields = array(
			'product'        => $productId,
			'release'        => $releaseId,
			'license_key_id' => $licenseKeyId,
			'site_hash'      => $siteHash,
			'expires'        => $expires,
		);

		$fields['signature'] = $this->hmac( $this->packageCanonical( $productId, $releaseId, $licenseKeyId, $siteHash, $expires ) );

		return $this->buildUrl( 'tiermint/v1/package/download', $fields );
	}

	/**
	 * Verifies a presented package-download grant.
	 *
	 * Check the signature before expiry.
	 *
	 * @since 1.0.0
	 *
	 * @param int      $productId    Presented product id.
	 * @param int      $releaseId    Presented release id.
	 * @param int      $licenseKeyId Presented license row id.
	 * @param string   $siteHash     Presented site hash.
	 * @param int      $expires      Presented Unix expiry.
	 * @param string   $signature    Presented hex signature.
	 * @param int|null $now          Unix time to judge expiry against; null means current time.
	 * @return string One of the VERDICT_* constants.
	 */
	public function verifyPackage( int $productId, int $releaseId, int $licenseKeyId, string $siteHash, int $expires, string $signature, ?int $now = null ): string {
		return $this->verdict(
			$this->packageCanonical( $productId, $releaseId, $licenseKeyId, $siteHash, $expires ),
			$expires,
			$signature,
			$now
		);
	}

	/**
	 * Mint a signed order-file URL.
	 *
	 * @since 1.0.0
	 *
	 * @param int      $orderId    Order the grant belongs to.
	 * @param int      $itemId     Order item the file hangs off.
	 * @param int      $customerId Owning customer, binding the grant to the buyer.
	 * @param string   $fileToken  Opaque token identifying the file within the item.
	 * @param int|null $now        Unix time to compute expiry from; null means current time.
	 * @param int|null $ttl        Per-call lifetime override in seconds; null keeps the
	 *                             built-in 300-second default. See {@see ttl()} for the
	 *                             override/filter/floor precedence.
	 * @return string Absolute REST URL carrying exactly the canonical fields plus the signature.
	 */
	public function orderFileDownloadUrl( int $orderId, int $itemId, int $customerId, string $fileToken, ?int $now = null, ?int $ttl = null ): string {
		$expires = ( $now ?? time() ) + $this->ttl( $ttl );

		$fields = array(
			'order'    => $orderId,
			'item'     => $itemId,
			'customer' => $customerId,
			'token'    => $fileToken,
			'expires'  => $expires,
		);

		$fields['signature'] = $this->signOrderFile( $orderId, $itemId, $customerId, $fileToken, $expires );

		return $this->buildUrl( 'tiermint/v1/order/download', $fields );
	}

	/**
	 * Add signed fields to a REST URL.
	 *
	 * @param string               $route  The route below the REST root.
	 * @param array<string, mixed> $fields The ordered grant fields plus signature.
	 * @return string The absolute signed URL.
	 */
	private function buildUrl( string $route, array $fields ): string {
		$base = rest_url( $route );

		return $base
			. ( str_contains( $base, '?' ) ? '&' : '?' )
			. http_build_query( $fields, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Sign an order-file grant.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $orderId    Order the grant belongs to.
	 * @param int    $itemId     Order item the file hangs off.
	 * @param int    $customerId Owning customer, binding the grant to the buyer.
	 * @param string $fileToken  Opaque token identifying the file within the item.
	 * @param int    $expires    Unix expiry to bind into the signature.
	 * @return string Lowercase hex HMAC-SHA256 signature.
	 */
	public function signOrderFile( int $orderId, int $itemId, int $customerId, string $fileToken, int $expires ): string {
		return $this->hmac( $this->orderCanonical( $orderId, $itemId, $customerId, $fileToken, $expires ) );
	}

	/**
	 * Verify an order-file grant.
	 *
	 * @since 1.0.0
	 *
	 * @param int      $orderId    Presented order id.
	 * @param int      $itemId     Presented order item id.
	 * @param int      $customerId Presented customer id.
	 * @param string   $fileToken  Presented file token.
	 * @param int      $expires    Presented Unix expiry.
	 * @param string   $signature  Presented hex signature.
	 * @param int|null $now        Unix time to judge expiry against; null means current time.
	 * @return string One of the VERDICT_* constants.
	 */
	public function verifyOrderFile( int $orderId, int $itemId, int $customerId, string $fileToken, int $expires, string $signature, ?int $now = null ): string {
		return $this->verdict(
			$this->orderCanonical( $orderId, $itemId, $customerId, $fileToken, $expires ),
			$expires,
			$signature,
			$now
		);
	}

	/**
	 * Mint a signed URL for an add-on grant.
	 *
	 * Preserve field order for verification.
	 *
	 * @since 1.0.0
	 *
	 * @param string                    $route   REST route below the REST root, e.g. `tiermint/v1/pro/invoices/download`.
	 * @param string                    $context Domain-separation tag; see {@see signGrant()} for what is allowed.
	 * @param array<string, int|string> $fields Ordered grant fields, keyed by query-parameter name.
	 * @param int|null                  $now     Unix time to compute expiry from; null means current time.
	 * @param int|null                  $ttl     Per-call lifetime override in seconds; null keeps the default.
	 * @return string Absolute signed URL.
	 *
	 * @throws InvalidArgumentException When a field is named after a parameter this
	 *                                  method appends, or the context or a field
	 *                                  value cannot be signed (see {@see signGrant()}).
	 */
	public function grantUrl( string $route, string $context, array $fields, ?int $now = null, ?int $ttl = null ): string {
		foreach ( array_keys( $fields ) as $name ) {
			if ( in_array( (string) $name, self::APPENDED_PARAMS, true ) ) {
				$message = sprintf( 'A signed grant field may not be named %s: this method appends that parameter itself.', (string) $name );

				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only English; the name is the caller's own literal.
				throw new InvalidArgumentException( $message );
			}
		}

		$expires = ( $now ?? time() ) + $this->ttl( $ttl );

		// Validate signed fields before building the URL.
		$signature = $this->signGrant( $context, $fields, $expires );

		$fields['expires']   = $expires;
		$fields['signature'] = $signature;

		return $this->buildUrl( $route, $fields );
	}

	/**
	 * Sign a generic grant.
	 *
	 * Context separates grant families and must be route-specific.
	 *
	 * @since 1.0.0
	 *
	 * @param string                    $context Domain-separation tag.
	 * @param array<string, int|string> $fields  Ordered grant fields; only the values are signed.
	 * @param int                       $expires Unix expiry to bind into the signature.
	 * @return string Lowercase hex HMAC-SHA256 signature.
	 *
	 * @throws InvalidArgumentException When the context is reserved or malformed,
	 *                                  or a field value is not an int or string or
	 *                                  contains the canonical separator.
	 */
	public function signGrant( string $context, array $fields, int $expires ): string {
		return $this->hmac( $this->grantCanonical( $context, $fields, $expires ) );
	}

	/**
	 * Verify a generic grant. Invalid fields return an invalid signature.
	 *
	 * @since 1.0.0
	 *
	 * @param string                    $context   Domain-separation tag the grant was minted under.
	 * @param array<string, int|string> $fields    Presented fields, in the order they were signed.
	 * @param int                       $expires   Presented Unix expiry.
	 * @param string                    $signature Presented hex signature.
	 * @param int|null                  $now       Unix time to judge expiry against; null means current time.
	 * @return string One of the VERDICT_* constants.
	 */
	public function verifyGrant( string $context, array $fields, int $expires, string $signature, ?int $now = null ): string {
		try {
			$canonical = $this->grantCanonical( $context, $fields, $expires );
		} catch ( InvalidArgumentException $e ) {
			return self::VERDICT_INVALID_SIGNATURE;
		}

		return $this->verdict( $canonical, $expires, $signature, $now );
	}

	/**
	 * Build and validate a generic grant string.
	 *
	 * @param string                    $context Domain-separation tag.
	 * @param array<string, int|string> $fields  Ordered grant fields.
	 * @param int                       $expires Unix expiry.
	 * @return string The pipe-joined canonical string.
	 *
	 * @throws InvalidArgumentException When the context or any field value cannot be signed.
	 */
	private function grantCanonical( string $context, array $fields, int $expires ): string {
		if ( 1 !== preg_match( self::CONTEXT_PATTERN, $context ) || self::ORDER_CONTEXT === $context ) {
			$message = sprintf(
				'"%s" is not a usable signed-grant context: it must be a lowercase identifier starting with a letter, and "%s" is reserved.',
				$context,
				self::ORDER_CONTEXT
			);

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only English; the context is the caller's own literal.
			throw new InvalidArgumentException( $message );
		}

		$parts = array( $context );

		foreach ( $fields as $name => $value ) {
			if ( ! is_int( $value ) && ! is_string( $value ) ) {
				$message = sprintf( 'The signed-grant field "%s" must be an int or a string.', (string) $name );

				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only English; the value is deliberately not repeated into the message.
				throw new InvalidArgumentException( $message );
			}

			$value = (string) $value;

			if ( str_contains( $value, self::SEPARATOR ) ) {
				$message = sprintf( 'The signed-grant field "%s" may not contain the canonical separator.', (string) $name );

				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only English; the value is deliberately not repeated into the message.
				throw new InvalidArgumentException( $message );
			}

			$parts[] = $value;
		}

		$parts[] = (string) $expires;

		return implode( self::SEPARATOR, $parts );
	}

	/**
	 * Check signature before expiry.
	 *
	 * @param string   $canonical The canonical string the signature must cover.
	 * @param int      $expires   Presented Unix expiry.
	 * @param string   $signature Presented hex signature.
	 * @param int|null $now       Unix time to judge expiry against.
	 * @return string One of the VERDICT_* constants.
	 */
	private function verdict( string $canonical, int $expires, string $signature, ?int $now ): string {
		if ( ! hash_equals( $this->hmac( $canonical ), $signature ) ) {
			return self::VERDICT_INVALID_SIGNATURE;
		}

		if ( ( $now ?? time() ) > $expires ) {
			return self::VERDICT_EXPIRED;
		}

		return self::VERDICT_VALID;
	}

	/**
	 * The package canonical string.
	 *
	 * @param int    $productId    Product id.
	 * @param int    $releaseId    Release id.
	 * @param int    $licenseKeyId License row id.
	 * @param string $siteHash     Site hash.
	 * @param int    $expires      Unix expiry.
	 * @return string The pipe-joined canonical string.
	 */
	private function packageCanonical( int $productId, int $releaseId, int $licenseKeyId, string $siteHash, int $expires ): string {
		return implode( '|', array( $productId, $releaseId, $licenseKeyId, $siteHash, $expires ) );
	}

	/**
	 * The order-file canonical string, domain-separated by its leading tag.
	 *
	 * @param int    $orderId    Order id.
	 * @param int    $itemId     Order item id.
	 * @param int    $customerId Customer id.
	 * @param string $fileToken  File token.
	 * @param int    $expires    Unix expiry.
	 * @return string The pipe-joined canonical string.
	 */
	private function orderCanonical( int $orderId, int $itemId, int $customerId, string $fileToken, int $expires ): string {
		return implode( '|', array( self::ORDER_CONTEXT, $orderId, $itemId, $customerId, $fileToken, $expires ) );
	}

	/**
	 * HMAC-SHA256 over a canonical string with the signing secret.
	 *
	 * @param string $canonical The canonical string to sign.
	 * @return string Lowercase hex digest.
	 */
	private function hmac( string $canonical ): string {
		return hash_hmac( 'sha256', $canonical, $this->signingSecret() );
	}

	/**
	 * Return the filtered URL lifetime, at least MIN_TTL.
	 *
	 * @param int|null $override Per-call base lifetime in seconds, or null for the default.
	 * @return int TTL in seconds, at least MIN_TTL.
	 */
	private function ttl( ?int $override = null ): int {
		$base = null === $override ? self::DEFAULT_TTL : $override;

		/**
		 * Filter the signed download URL lifetime in seconds.
		 *
		 * @since 1.0.0
		 *
		 * @param int $ttl URL lifetime in seconds. Default 300, or the per-call override when one was passed.
		 */
		$ttl = apply_filters( 'tiermint_download_url_ttl', $base );

		return max( self::MIN_TTL, (int) $ttl );
	}

	/**
	 * Load or create the raw signing secret.
	 *
	 * Read after creation to use the shared value.
	 *
	 * @return string 32 raw bytes of signing secret.
	 */
	private function signingSecret(): string {
		if ( null !== $this->secret ) {
			return $this->secret;
		}

		$stored = get_option( self::SECRET_OPTION, false );

		if ( is_string( $stored ) && '' !== $stored ) {
			try {
				$this->secret = $this->crypto->decrypt( $stored );

				return $this->secret;
			} catch ( CryptoException $e ) {
				// Regenerate an unreadable secret.
				$fresh = random_bytes( 32 );
				update_option( self::SECRET_OPTION, $this->crypto->encrypt( $fresh ), false );
				$this->secret = $fresh;

				return $this->secret;
			}
		}

		$fresh = random_bytes( 32 );
		add_option( self::SECRET_OPTION, $this->crypto->encrypt( $fresh ), '', false );

		// Use the stored secret after concurrent creation.
		$stored = get_option( self::SECRET_OPTION, false );

		if ( is_string( $stored ) && '' !== $stored ) {
			try {
				$this->secret = $this->crypto->decrypt( $stored );

				return $this->secret;
			} catch ( CryptoException $e ) {
				// The stored row is unreadable; restore service with ours.
				update_option( self::SECRET_OPTION, $this->crypto->encrypt( $fresh ), false );
			}
		}

		$this->secret = $fresh;

		return $this->secret;
	}
}
