<?php
/**
 * The role a new TierMint account gets.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Customers;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Keeps a new account's role to one that can only read.
 *
 * Both account paths use it, before and after their user args filter.
 *
 * @since 1.0.0
 */
final class AccountRole {

	/**
	 * The role used when the asked-for role can do more than read.
	 *
	 * @var string
	 */
	public const FALLBACK = 'subscriber';

	/**
	 * Capabilities a new account may have.
	 *
	 * @var array<int, string>
	 */
	private const ALLOWED_CAPS = array( 'read', 'level_0' );

	/**
	 * The given role when it grants only read access, else subscriber.
	 *
	 * A role name that no longer exists also gives subscriber. Without that,
	 * wp_insert_user() would make an account with no role at all.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $role The role name, usually the `default_role` option.
	 * @return string The role to give the new account.
	 */
	public static function resolve( $role ): string {
		// get_role() uses the value as an array key, so an array would throw.
		if ( ! is_string( $role ) || '' === $role || self::FALLBACK === $role ) {
			return self::FALLBACK;
		}

		$object = get_role( $role );

		if ( ! $object instanceof \WP_Role ) {
			return self::FALLBACK;
		}

		foreach ( (array) $object->capabilities as $cap => $granted ) {
			// An empty value denies the capability, as in WP_Role::has_cap().
			if ( ! empty( $granted ) && ! in_array( (string) $cap, self::ALLOWED_CAPS, true ) ) {
				return self::FALLBACK;
			}
		}

		return $role;
	}
}
