<?php
/**
 * Issuance, verification, and revocation of management API keys.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\REST\Auth;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Issue, verify and revoke management API keys.
 *
 * Secrets are returned once and stored only as hashes.
 *
 * @since 1.0.0
 */
class ApiKeyService {

	/**
	 * Seconds before the next last-used update.
	 *
	 * @var int
	 */
	private const LAST_USED_THROTTLE_SECONDS = 60;

	/**
	 * Dummy hash for unknown public keys.
	 *
	 * @var string
	 */
	private const DUMMY_HASH = '$P$BTierMintDummyCompare0000000000';

	/**
	 * The api-key persistence gateway.
	 *
	 * @var ApiKeyRepository
	 */
	private ApiKeyRepository $repository;

	/**
	 * Wire the service to its repository.
	 *
	 * @since 1.0.0
	 *
	 * @param ApiKeyRepository $repository The api-key persistence gateway.
	 */
	public function __construct( ApiKeyRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Issue a key and return its bearer once.
	 *
	 * @since 1.0.0
	 *
	 * @param int              $userId     The owning WP user id.
	 * @param string           $label      The human label for the key.
	 * @param ApiKeyPermission $permission The read/write permission.
	 * @return array{key: ApiKey, bearer: string} The key and one-time bearer.
	 *
	 * @throws ApiKeyValidationException  When the label breaks a shape rule.
	 * @throws ApiKeyPersistenceException When the insert fails.
	 */
	public function issue( int $userId, string $label, ApiKeyPermission $permission = ApiKeyPermission::Read ): array {
		$publicKey = 'tmk_' . bin2hex( random_bytes( 16 ) );

		// 32 random bytes as unpadded base64url: 43 characters.
		$secret = 'tms_' . rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Credential encoding, not obfuscation.

		$key = new ApiKey(
			null,
			$userId,
			trim( sanitize_text_field( $label ) ),
			$publicKey,
			wp_hash_password( $secret ),
			$permission,
			ApiKeyStatus::Active,
			null,
			new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) )
		);

		$id = $this->repository->create( $key );

		return array(
			'key'    => $key->withId( $id ),
			'bearer' => $publicKey . ':' . $secret,
		);
	}

	/**
	 * Verify a credential with one password check.
	 *
	 * @since 1.0.0
	 *
	 * @param string                 $publicKey The presented tmk_ public key.
	 * @param string                 $secret    The presented tms_ secret.
	 * @param DateTimeImmutable|null $now       The verification instant (tests inject; null = now).
	 * @return ApiKey|null The authenticated key, or null on any failure.
	 *
	 * @throws ApiKeyPersistenceException When the credential read fails.
	 */
	public function verify( string $publicKey, string $secret, ?DateTimeImmutable $now = null ): ?ApiKey {
		$key = $this->repository->findByPublicKey( $publicKey );

		$hash    = null !== $key ? $key->secretHash() : self::DUMMY_HASH;
		$matches = wp_check_password( $secret, $hash );

		if ( null === $key || ! $matches || ! $key->isActive() ) {
			return null;
		}

		$now = $now ?? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );

		$lastUsed = $key->lastUsedAt();
		$isStale  = null === $lastUsed
			|| ( $now->getTimestamp() - $lastUsed->getTimestamp() ) >= self::LAST_USED_THROTTLE_SECONDS;

		if ( $isStale ) {
			$this->repository->stampLastUsed( (int) $key->id(), $now->format( 'Y-m-d H:i:s' ) );
			$key = $key->withLastUsedAt( $now );
		}

		return $key;
	}

	// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber -- The persistence exception propagates from the repository; documenting it here is API-critical.

	/**
	 * Revoke a key and keep its audit row.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The key id.
	 * @return ApiKey The key in its revoked state.
	 *
	 * @throws ApiKeyNotFoundException    When no key carries the id.
	 * @throws ApiKeyPersistenceException When the update fails.
	 */
	public function revoke( int $id ): ApiKey {
		$key = $this->repository->find( $id );

		if ( null === $key ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only message; the controller maps it to a generic 404.
			throw ApiKeyNotFoundException::forId( $id );
		}

		if ( ApiKeyStatus::Revoked === $key->status() ) {
			return $key;
		}

		$this->repository->updateStatus( $id, ApiKeyStatus::Revoked );

		return $key->withStatus( ApiKeyStatus::Revoked );
	}

	// phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber

	/**
	 * Every key, newest first, for the management listing.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, ApiKey> All keys, including revoked audit rows.
	 */
	public function all(): array {
		return $this->repository->all();
	}
}
