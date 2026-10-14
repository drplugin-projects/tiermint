<?php
/**
 * Turns a completed guest order into a WordPress account when auto-registration
 * is on.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Customers;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Core\Hookable;
use DrPlugin\WP\TierMint\Database\Logger;
use DrPlugin\WP\TierMint\Frontend\CheckoutFieldSettings;
use DrPlugin\WP\TierMint\Orders\Order;
use RuntimeException;
use Throwable;

/**
 * Create a WordPress account for a completed guest order when enabled.
 *
 * Failures are logged and never roll back payment completion. CustomerLinker adds the link.
 *
 * @since 1.0.0
 */
final class UserProvisioner implements Hookable {

	/**
	 * The checkout settings reader (the `auto_register` toggle).
	 *
	 * @var CheckoutFieldSettings
	 */
	private CheckoutFieldSettings $settings;

	/**
	 * The customer service used to resolve and link the order's customer.
	 *
	 * @var CustomerService
	 */
	private CustomerService $customers;

	/**
	 * The audit writer failures are recorded through.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Wire the provisioner with its collaborators.
	 *
	 * @since 1.0.0
	 *
	 * @param CheckoutFieldSettings $settings  The checkout settings reader.
	 * @param CustomerService       $customers The customer domain service.
	 * @param Logger                $logger    The audit writer.
	 */
	public function __construct( CheckoutFieldSettings $settings, CustomerService $customers, Logger $logger ) {
		$this->settings  = $settings;
		$this->customers = $customers;
		$this->logger    = $logger;
	}

	/**
	 * Register the order-completed listener.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'tiermint_order_completed', array( $this, 'onOrderCompleted' ), 10, 1 );
	}

	/**
	 * Provision or link an account without failing the order.
	 *
	 * @since 1.0.0
	 *
	 * @param Order $order The completed order.
	 * @return void
	 */
	public function onOrderCompleted( Order $order ): void {
		try {
			$this->provision( $order );
		} catch ( Throwable $failure ) {
			$this->logger->log(
				'error',
				sprintf( 'Auto-registration failed for order %d: %s', (int) $order->id(), $failure->getMessage() ),
				array( 'order_id' => (int) $order->id() ),
				'order',
				(int) $order->id()
			);
		}
	}

	/**
	 * Skip linked customers, link an existing account or create a new one.
	 *
	 * @since 1.0.0
	 *
	 * @param Order $order The completed order.
	 * @return void
	 */
	private function provision( Order $order ): void {
		if ( ! $this->settings->autoRegister() ) {
			return;
		}

		$customer = $this->orderCustomer( $order );

		// Already linked to a WordPress user (a logged-in buyer, or a prior
		// provisioning): nothing to do, and nothing to double-provision.
		if ( null !== $customer && null !== $customer->userId() ) {
			return;
		}

		$email = $order->email();

		// A WordPress account already uses this email: link the customer record to
		// it and never touch the account itself. A guest who typed someone else's
		// address only links their own order to the mailbox owner's account, never
		// the reverse.
		$existing = email_exists( $email );

		if ( false !== $existing ) {
			$this->customers->linkWpUser( (int) $existing, $email );

			return;
		}

		$this->createUser( $order, $email, $customer );
	}

	/**
	 * The order's linked customer, or null when it carries none.
	 *
	 * @since 1.0.0
	 *
	 * @param Order $order The order.
	 * @return Customer|null The customer, or null.
	 */
	private function orderCustomer( Order $order ): ?Customer {
		$customerId = $order->customerId();

		return null !== $customerId ? $this->customers->get( $customerId ) : null;
	}

	/**
	 * Create an account with a unique login and generated password.
	 *
	 * WordPress can send a set-password link. Never email a password.
	 *
	 * @since 1.0.0
	 *
	 * @param Order         $order    The completed order.
	 * @param string        $email    The buyer email.
	 * @param Customer|null $customer The order's customer, or null.
	 * @return void
	 *
	 * @throws RuntimeException When wp_insert_user returns an error.
	 */
	private function createUser( Order $order, string $email, ?Customer $customer ): void {
		$args = array(
			'user_login' => $this->uniqueLogin( $email ),
			'user_email' => $email,
			'user_pass'  => wp_generate_password( 24 ),
			'role'       => (string) get_option( 'default_role' ),
		);

		$name  = null !== $customer ? (string) $customer->name() : '';
		$first = null !== $customer ? (string) $customer->firstName() : '';
		$last  = null !== $customer ? (string) $customer->lastName() : '';

		if ( '' !== $name ) {
			$args['display_name'] = $name;
		}

		if ( '' !== $first ) {
			$args['first_name'] = $first;
		}

		if ( '' !== $last ) {
			$args['last_name'] = $last;
		}

		/**
 * Filter wp_insert_user arguments for a completed guest order.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $args  The wp_insert_user arguments.
		 * @param Order                $order The completed order the account is for.
		 */
		$args = apply_filters( 'tiermint_auto_register_user_args', $args, $order );

		if ( ! is_array( $args ) ) {
			return;
		}

		$userId = wp_insert_user( $args );

		if ( is_wp_error( $userId ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only message, never echoed; caught by onOrderCompleted.
			throw new RuntimeException( 'wp_insert_user failed: ' . $userId->get_error_message() );
		}

		$userId = (int) $userId;

		/**
 * Filter whether to email a new account holder a set-password link.
		 *
		 * @since 1.0.0
		 *
		 * @param bool  $notify Whether to send the set-password email.
		 * @param int   $userId The new user id.
		 * @param Order $order  The completed order the account is for.
		 */
		if ( (bool) apply_filters( 'tiermint_auto_register_notify', true, $userId, $order ) ) {
			// The 'user' notification sends the set-password link only; no plaintext
			// password is ever emailed.
			wp_send_new_user_notifications( $userId, 'user' );
		}
	}

	/**
	 * Build a unique login from the email local part.
	 *
	 * Use `user` if sanitisation leaves no usable text.
	 *
	 * @since 1.0.0
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
}
