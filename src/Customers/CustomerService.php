<?php
/**
 * Customer domain service: all customer business logic and validation.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Customers;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Customer identity rules for checkout, admin, CLI and adapters.
 *
 * Match user ID before normalised email. Creates and changes fire customer hooks.
 *
 * @since 1.0.0
 */
class CustomerService {

	/**
	 * The repository this service reads and writes customers through.
	 *
	 * @var CustomerRepository
	 */
	private CustomerRepository $repository;

	/**
	 * Wire the service with its repository.
	 *
	 * @since 1.0.0
	 *
	 * @param CustomerRepository $repository The customers repository.
	 */
	public function __construct( CustomerRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Resolve or create a purchase customer.
	 *
	 * Link matching guests. Reject another user's email.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $data The buyer input.
	 * @return Customer The resolved or newly created customer.
	 *
	 * @throws CustomerValidationException  When the email is missing or malformed, or it
	 *                                      belongs to a different WordPress account.
	 * @throws CustomerPersistenceException When a database write fails and is not a
	 *                                      recoverable race with a concurrent create.
	 */
	public function findOrCreate( array $data ): Customer {
		$email    = $this->requireEmail( $data );
		$userId   = $this->resolveUserId( $data['user_id'] ?? null );
		$identity = $this->resolveIdentity( $data );

		// A logged-in buyer is matched by their account link first, so a returning
		// member always resolves to the same record even if they change email casing.
		if ( null !== $userId ) {
			$linked = $this->repository->findByUserId( $userId );

			if ( null !== $linked ) {
				return $this->applyIdentity( $linked, null, $identity );
			}
		}

		$existing = $this->repository->findByEmail( $email );

		if ( null !== $existing ) {
			return $this->resolveExisting( $existing, $userId, $email, $identity );
		}

		return $this->insertOrResolveRace( $userId, $email, $identity );
	}

	/**
	 * Insert a customer and recover from a concurrent create.
	 *
	 * A unique-key loss resolves to the row created by the other request.
	 *
	 * @since 1.0.0
	 *
	 * @param int|null                                                      $userId   The buyer's WordPress user id, or null for a guest.
	 * @param string                                                        $email    The normalized email.
	 * @param array{name: ?string, first_name: ?string, last_name: ?string} $identity The resolved name fields.
	 * @return Customer The created, or concurrently created, customer.
	 *
	 * @throws CustomerPersistenceException When the write fails for a reason other than a
	 *                          recoverable race with a concurrent create.
	 */
	private function insertOrResolveRace( ?int $userId, string $email, array $identity ): Customer {
		try {
			return $this->insert(
				new Customer( null, $userId, $email, $identity['name'], 0, 0, null, null, $identity['first_name'], $identity['last_name'] )
			);
		} catch ( CustomerPersistenceException $e ) {
			$raced = ( null !== $userId ? $this->repository->findByUserId( $userId ) : null )
				?? $this->repository->findByEmail( $email );

			if ( null === $raced ) {
				throw $e;
			}

			return $raced;
		}
	}

	/**
	 * Resolve an email match for the buyer's account state.
	 *
	 * A logged-in user can claim a matching guest, never another user’s customer.
	 *
	 * @since 1.0.0
	 *
	 * @param Customer                                                      $existing The customer found by email.
	 * @param int|null                                                      $userId   The buyer's WordPress user id, or null for a guest.
	 * @param string                                                        $email    The normalized email (for the conflict message).
	 * @param array{name: ?string, first_name: ?string, last_name: ?string} $identity The resolved name fields to apply.
	 * @return Customer The resolved customer.
	 *
	 * @throws CustomerValidationException When the email belongs to another account.
	 */
	private function resolveExisting( Customer $existing, ?int $userId, string $email, array $identity ): Customer {
		if ( null === $userId ) {
			return $this->applyIdentity( $existing, null, $identity );
		}

		if ( $existing->isGuest() ) {
			// The guest is both linked to the account and given any incoming names in a
			// single update, so a first purchase never fires the updated hook twice.
			return $this->applyIdentity( $existing, $userId, $identity );
		}

		// We only reach here when the buyer has no customer of their own (checked in
		// findOrCreate), so a linked match is owned by a different account.
		/* translators: %s: the submitted email address. */
		$message = sprintf( __( 'The email "%s" is already associated with another account.', 'tiermint' ), $email );

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer/validation message, surfaced as a structured API error, never echoed as HTML.
		throw CustomerValidationException::withErrors( array( $message ) );
	}

	/**
	 * Create a customer from the management POST path.
	 *
	 * A duplicate email or user link is an error.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $data The customer input (`email` required).
	 * @return Customer The created, reloaded customer.
	 *
	 * @throws CustomerValidationException When the email is invalid or already taken, or
	 *                          the user link is already in use. A failed database write
	 *                          surfaces as the {@see CustomerPersistenceException} raised
	 *                          by the repository.
	 */
	public function create( array $data ): Customer {
		$errors = array();

		$email  = $this->validateEmail( $data['email'] ?? null, null, $errors );
		$userId = $this->resolveUserId( $data['user_id'] ?? null );

		if ( null !== $userId && $this->repository->userIdExists( $userId, null ) ) {
			/* translators: %d: the WordPress user ID. */
			$errors[] = sprintf( __( 'WordPress user %d is already linked to another customer.', 'tiermint' ), $userId );
		}

		if ( array() !== $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer/validation messages, surfaced as structured API errors, never echoed as HTML.
			throw CustomerValidationException::withErrors( $errors );
		}

		$name = $this->resolveName( $data['name'] ?? null );

		return $this->insert( new Customer( null, $userId, $email, $name, 0, 0, null, null ) );
	}

	/**
	 * Validate and apply management updates to a customer.
	 *
	 * Missing fields keep their values. The order layer owns purchase counters.
	 *
	 * @since 1.0.0
	 *
	 * @param int                  $id   The customer id to update.
	 * @param array<string, mixed> $data The partial customer input.
	 * @return Customer The reloaded, persisted customer.
	 *
	 * @throws CustomerNotFoundException   When no customer has the given id.
	 * @throws CustomerValidationException When any field is invalid or already taken. A
	 *                          failed database write surfaces as the
	 *                          {@see CustomerPersistenceException} raised by the repository.
	 */
	public function update( int $id, array $data ): Customer {
		$existing = $this->repository->find( $id );

		if ( null === $existing ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer/validation message, surfaced as a structured 404, never echoed as HTML.
			throw CustomerNotFoundException::forId( $id );
		}

		$errors = array();

		$email = array_key_exists( 'email', $data )
			? $this->validateEmail( $data['email'], $id, $errors )
			: $existing->email();

		$userId = $this->resolveUpdateUserId( $data, $existing, $id, $errors );
		$name   = array_key_exists( 'name', $data ) ? $this->resolveName( $data['name'] ) : $existing->name();

		if ( array() !== $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer/validation messages, surfaced as structured API errors, never echoed as HTML.
			throw CustomerValidationException::withErrors( $errors );
		}

		$customer = new Customer(
			$id,
			$userId,
			$email,
			$name,
			$existing->purchaseCount(),
			$existing->purchaseTotal(),
			$existing->createdAt(),
			$existing->updatedAt(),
			// The management PUT does not edit the split name fields, so carry them
			// over; omitting them would null a buyer's collected first/last name.
			$existing->firstName(),
			$existing->lastName()
		);

		$this->repository->update( $customer );
		$saved = $this->requireCustomer( $id );

		$this->announceUpdated( $saved );

		return $saved;
	}

	/**
	 * Delete a customer only when no record refers to it.
	 *
	 * Privacy erasure anonymises customers with orders or licences.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The customer id to delete.
	 * @return void
	 *
	 * @throws CustomerNotFoundException      When no customer has the given id.
	 * @throws CustomerDeleteBlockedException When a registered blocker refuses the delete. A
	 *                                        failed write surfaces as the
	 *                                        {@see CustomerPersistenceException} raised by
	 *                                        the repository.
	 */
	public function delete( int $id ): void {
		if ( null === $this->repository->find( $id ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer/validation message, surfaced as a structured 404, never echoed as HTML.
			throw CustomerNotFoundException::forId( $id );
		}

		$reasons = $this->deleteBlockersFor( $id );

		if ( array() !== $reasons ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured, translated messages surfaced as an API error, never echoed as HTML.
			throw CustomerDeleteBlockedException::withReasons( $reasons );
		}

		$this->repository->delete( $id );
	}

	/**
	 * Collect reasons that block a customer deletion.
	 *
	 * Invalid or empty filter results fail closed.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The customer id about to be deleted.
	 * @return array<int, string> The reasons, empty when nothing blocks.
	 */
	private function deleteBlockersFor( int $id ): array {
		/**
		 * Filters the reasons a customer may not be deleted, each a translated sentence
		 * naming what still references it. Return the list unchanged to allow the delete.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, string> $reasons The reasons collected so far.
		 * @param int                $id      The customer id about to be deleted.
		 */
		$filtered = apply_filters( 'tiermint_customer_delete_blockers', array(), $id );

		$unnamed = array( __( 'A check on this customer could not be completed, so the delete was stopped.', 'tiermint' ) );

		if ( ! is_array( $filtered ) ) {
			return $unnamed;
		}

		if ( array() === $filtered ) {
			return array();
		}

		$reasons = array_values(
			array_filter(
				array_map( 'strval', $filtered ),
				static fn ( string $reason ): bool => '' !== trim( $reason )
			)
		);

		return array() !== $reasons ? $reasons : $unnamed;
	}

	/**
	 * Link a matching guest when a WordPress user registers or logs in.
	 *
	 * Never create or transfer a customer. Repeated calls do nothing.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $userId The WordPress user id that just registered or logged in.
	 * @param string $email  That user's email address.
	 * @return Customer|null The newly linked customer, or null when there was nothing to
	 *                       link (already linked, no matching record, or a record owned
	 *                       by another user).
	 */
	public function linkWpUser( int $userId, string $email ): ?Customer {
		if ( $userId <= 0 ) {
			return null;
		}

		// Already has a customer record: nothing to do.
		if ( null !== $this->repository->findByUserId( $userId ) ) {
			return null;
		}

		$normalized = Customer::normalizeEmail( $email );

		if ( '' === $normalized ) {
			return null;
		}

		$match = $this->repository->findByEmail( $normalized );

		// Only an existing guest is upgraded; a record already owned by another user is
		// left alone.
		if ( null === $match || ! $match->isGuest() ) {
			return null;
		}

		return $this->applyIdentity( $match, $userId, $this->emptyIdentity() );
	}

	/**
	 * Load a customer by id, or null when it does not exist.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The customer id.
	 * @return Customer|null The customer, or null.
	 */
	public function get( int $id ): ?Customer {
		return $this->repository->find( $id );
	}

	/**
	 * Load several customers in one read, keyed by ID.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, mixed> $ids The customer ids to load.
	 * @return array<int, Customer> Customer id => customer.
	 */
	public function getMany( array $ids ): array {
		return $this->repository->findMany( $ids );
	}

	/**
	 * Load a customer by email, or null when none matches.
	 *
	 * @since 1.0.0
	 *
	 * @param string $email The email to look up.
	 * @return Customer|null The customer, or null.
	 */
	public function getByEmail( string $email ): ?Customer {
		return $this->repository->findByEmail( $email );
	}

	/**
	 * Load the customer linked to a WordPress user.
	 *
	 * A non-positive ID avoids a database read.
	 *
	 * @since 1.0.0
	 *
	 * @param int $userId The WordPress user id.
	 * @return Customer|null The linked customer, or null when none exists.
	 */
	public function getByUserId( int $userId ): ?Customer {
		if ( $userId <= 0 ) {
			return null;
		}

		return $this->repository->findByUserId( $userId );
	}

	/**
	 * Record a completed purchase against a customer.
	 *
	 * Invalid ids avoid stray writes. Order hooks report completed purchases.
	 *
	 * @since 1.0.0
	 *
	 * @param int $customerId The customer to credit.
	 * @param int $amount     The purchase total in minor units.
	 * @return void
	 */
	public function recordCompletedPurchase( int $customerId, int $amount ): void {
		if ( $customerId <= 0 ) {
			return;
		}

		$this->repository->incrementPurchaseStats( $customerId, $amount );
	}

	/**
	 * List customers for a collection view (delegates to the repository query).
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $args Query args (search, paging, ordering).
	 * @return array<int, Customer> The matching customers.
	 */
	public function list( array $args ): array {
		return $this->repository->query( $args );
	}

	/**
	 * Count customers matching a collection query (for pagination).
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $args Query args (search).
	 * @return int The total matching customer count.
	 */
	public function count( array $args ): int {
		return $this->repository->countQuery( $args );
	}

	/**
	 * Persist a new customer, reload it, and announce its creation.
	 *
	 * @since 1.0.0
	 *
	 * @param Customer $customer The customer to persist.
	 * @return Customer The reloaded, persisted customer.
	 *
	 * @throws CustomerPersistenceException When the write or reload fails.
	 */
	private function insert( Customer $customer ): Customer {
		$saved = $this->requireCustomer( $this->repository->create( $customer ) );

		/**
		 * Fires after a customer record is created (guest checkout, a logged-in first
		 * purchase, or a manual admin create).
		 *
		 * @since 1.0.0
		 *
		 * @param Customer $customer The newly created customer, reloaded from storage.
		 */
		do_action( 'tiermint_customer_created', $saved );

		return $saved;
	}

	/**
	 * Apply a user link and non-empty name fields in one write.
	 *
	 * Unchanged records do not fire the update hook.
	 *
	 * @since 1.0.0
	 *
	 * @param Customer                                                      $existing  The existing customer.
	 * @param int|null                                                      $newUserId A user id to link, or null to keep the current link.
	 * @param array{name: ?string, first_name: ?string, last_name: ?string} $identity  The resolved incoming name fields.
	 * @return Customer The reloaded customer (or the unchanged one when nothing moved).
	 *
	 * @throws CustomerPersistenceException When the write or reload fails.
	 */
	private function applyIdentity( Customer $existing, ?int $newUserId, array $identity ): Customer {
		$userId = null !== $newUserId ? $newUserId : $existing->userId();
		$name   = $this->preferNonEmpty( $identity['name'], $existing->name() );
		$first  = $this->preferNonEmpty( $identity['first_name'], $existing->firstName() );
		$last   = $this->preferNonEmpty( $identity['last_name'], $existing->lastName() );

		if ( $userId === $existing->userId()
			&& $name === $existing->name()
			&& $first === $existing->firstName()
			&& $last === $existing->lastName()
		) {
			return $existing;
		}

		$updated = new Customer(
			$existing->id(),
			$userId,
			$existing->email(),
			$name,
			$existing->purchaseCount(),
			$existing->purchaseTotal(),
			$existing->createdAt(),
			$existing->updatedAt(),
			$first,
			$last
		);

		$this->repository->update( $updated );
		$saved = $this->requireCustomer( (int) $existing->id() );

		$this->announceUpdated( $saved );

		return $saved;
	}

	/**
	 * Read trimmed identity fields from input.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $data The buyer input.
	 * @return array{name: ?string, first_name: ?string, last_name: ?string} The resolved fields.
	 */
	private function resolveIdentity( array $data ): array {
		return array(
			'name'       => $this->resolveName( $data['name'] ?? null ),
			'first_name' => $this->resolveName( $data['first_name'] ?? null ),
			'last_name'  => $this->resolveName( $data['last_name'] ?? null ),
		);
	}

	/**
	 * An identity with no fields to apply, used by the link-only paths.
	 *
	 * @since 1.0.0
	 *
	 * @return array{name: ?string, first_name: ?string, last_name: ?string} The empty identity.
	 */
	private function emptyIdentity(): array {
		return array(
			'name'       => null,
			'first_name' => null,
			'last_name'  => null,
		);
	}

	/**
	 * Keep the saved value when the input is empty.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $incoming The resolved incoming value (null when blank).
	 * @param string|null $current  The value currently stored.
	 * @return string|null The value to keep.
	 */
	private function preferNonEmpty( ?string $incoming, ?string $current ): ?string {
		return null !== $incoming ? $incoming : $current;
	}

	/**
	 * Fire the customer-updated hook for a mutated record.
	 *
	 * @since 1.0.0
	 *
	 * @param Customer $customer The updated customer, reloaded from storage.
	 * @return void
	 */
	private function announceUpdated( Customer $customer ): void {
		/**
		 * Fires after a customer record is updated (a manual edit, or a guest being
		 * linked to a WordPress user account).
		 *
		 * @since 1.0.0
		 *
		 * @param Customer $customer The updated customer, reloaded from storage.
		 */
		do_action( 'tiermint_customer_updated', $customer );
	}

	/**
	 * Read a required, valid email for find-or-create.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $data The input carrying the email.
	 * @return string The normalized, validated email.
	 *
	 * @throws CustomerValidationException When the email is missing or malformed.
	 */
	private function requireEmail( array $data ): string {
		$errors = array();
		$email  = $this->validateEmailFormat( $data['email'] ?? null, $errors );

		if ( array() !== $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer/validation messages, surfaced as structured API errors, never echoed as HTML.
			throw CustomerValidationException::withErrors( $errors );
		}

		return $email;
	}

	/**
	 * Validate an email format without checking uniqueness.
	 *
	 * Find-or-create accepts an existing email.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed              $value  The raw email value (or null).
	 * @param array<int, string> $errors Error accumulator, appended to by reference.
	 * @return string The normalized email, or '' when invalid.
	 */
	private function validateEmailFormat( $value, array &$errors ): string {
		$email = Customer::normalizeEmail( (string) ( $value ?? '' ) );

		if ( '' === $email ) {
			$errors[] = __( 'A customer email address is required.', 'tiermint' );

			return '';
		}

		if ( false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			/* translators: %s: the submitted email address. */
			$errors[] = sprintf( __( '"%s" is not a valid email address.', 'tiermint' ), $email );

			return '';
		}

		return $email;
	}

	/**
	 * Validate an email for a management write.
	 *
	 * Check uniqueness except for the record being updated.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed              $value    The raw email value (or null).
	 * @param int|null           $exceptId A customer id to exclude from the uniqueness
	 *                                     check (the record being updated), or null.
	 * @param array<int, string> $errors   Error accumulator, appended to by reference.
	 * @return string The normalized email, or '' when invalid.
	 */
	private function validateEmail( $value, ?int $exceptId, array &$errors ): string {
		$email = $this->validateEmailFormat( $value, $errors );

		if ( '' === $email ) {
			return '';
		}

		if ( $this->repository->emailExists( $email, $exceptId ) ) {
			/* translators: %s: the customer email address. */
			$errors[] = sprintf( __( 'A customer with the email "%s" already exists.', 'tiermint' ), $email );
		}

		return $email;
	}

	/**
	 * Return a positive WordPress user ID, or null for a guest.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value The raw user_id value.
	 * @return int|null The positive user id, or null for a guest.
	 */
	private function resolveUserId( $value ): ?int {
		$userId = (int) ( $value ?? 0 );

		return $userId > 0 ? $userId : null;
	}

	/**
	 * Resolve an update user link.
	 *
	 * A supplied zero unlinks. New positive links must be unique.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $data     The update input.
	 * @param Customer             $existing  The current customer.
	 * @param int                  $id       The customer id being updated.
	 * @param array<int, string>   $errors   Error accumulator, appended to by reference.
	 * @return int|null The resolved user link (null for a guest).
	 */
	private function resolveUpdateUserId( array $data, Customer $existing, int $id, array &$errors ): ?int {
		if ( ! array_key_exists( 'user_id', $data ) ) {
			return $existing->userId();
		}

		$userId = $this->resolveUserId( $data['user_id'] );

		// Only a real, changed link needs a uniqueness check; keeping the same link or
		// unlinking never collides.
		if ( null !== $userId && $userId !== $existing->userId() && $this->repository->userIdExists( $userId, $id ) ) {
			/* translators: %d: the WordPress user ID. */
			$errors[] = sprintf( __( 'WordPress user %d is already linked to another customer.', 'tiermint' ), $userId );
		}

		return $userId;
	}

	/**
	 * Resolve a name from raw input: a trimmed string, or null when blank or absent.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value The raw name value.
	 * @return string|null The resolved name, or null.
	 */
	private function resolveName( $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		$name = trim( (string) $value );

		return '' === $name ? null : $name;
	}

	/**
	 * Reload a customer by id after a write, failing loudly if it vanished.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The customer id.
	 * @return Customer The reloaded customer.
	 *
	 * @throws CustomerNotFoundException When the customer cannot be reloaded.
	 */
	private function requireCustomer( int $id ): Customer {
		$customer = $this->repository->find( $id );

		if ( null === $customer ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer/validation message, surfaced as a structured error, never echoed as HTML.
			throw CustomerNotFoundException::forId( $id );
		}

		return $customer;
	}
}
