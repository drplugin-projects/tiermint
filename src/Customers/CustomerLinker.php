<?php
/**
 * Wires WordPress account events to guest-customer linking.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Customers;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Core\Hookable;

/**
 * Links matching guest customers when an account registers or logs in.
 * The service decides whether a link exists.
 *
 * @since 1.0.0
 */
final class CustomerLinker implements Hookable {

	/**
	 * The customer service the account events are forwarded to.
	 *
	 * @var CustomerService
	 */
	private CustomerService $customers;

	/**
	 * Wire the adapter with the customer service.
	 *
	 * @since 1.0.0
	 *
	 * @param CustomerService $customers The customer domain service.
	 */
	public function __construct( CustomerService $customers ) {
		$this->customers = $customers;
	}

	/**
	 * Register the registration and login listeners.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'user_register', array( $this, 'onUserRegister' ), 10, 1 );
		add_action( 'wp_login', array( $this, 'onWpLogin' ), 10, 2 );
	}

	/**
	 * Link a matching guest customer after registration.
	 *
	 * @since 1.0.0
	 *
	 * @param int $userId The id of the user that just registered.
	 * @return void
	 */
	public function onUserRegister( int $userId ): void {
		$user = get_userdata( $userId );

		if ( ! $user || ! isset( $user->user_email ) ) {
			return;
		}

		$this->customers->linkWpUser( $userId, (string) $user->user_email );
	}

	/**
	 * Link a matching guest customer after login.
	 *
	 * @since 1.0.0
	 *
	 * @param string $userLogin The login name (unused; part of the hook signature).
	 * @param mixed  $user      The logged-in user object (a `WP_User` at runtime).
	 * @return void
	 */
	public function onWpLogin( string $userLogin, $user ): void {
		if ( ! is_object( $user ) || ! isset( $user->ID, $user->user_email ) ) {
			return;
		}

		$this->customers->linkWpUser( (int) $user->ID, (string) $user->user_email );
	}
}
