<?php
/**
 * The registry of transactional emails.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Emails;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Registers core and add-on emails.
 * Later entries replace earlier entries with the same ID.
 *
 * @since 1.0.0
 */
class EmailRegistry {

	/**
	 * The core email instances, before the filter.
	 *
	 * @var array<int, Email>
	 */
	private array $defaultEmails;

	/**
	 * Resolved emails keyed by id; null until first access.
	 *
	 * @var array<string, Email>|null
	 */
	private ?array $emails = null;

	/**
	 * Wire the registry with the core email seed list.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, Email> $defaultEmails The emails core ships.
	 */
	public function __construct( array $defaultEmails ) {
		$this->defaultEmails = $defaultEmails;
	}

	/**
	 * All emails, keyed by id.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, Email>
	 */
	public function all(): array {
		return $this->emails();
	}

	/**
	 * The email registered under an id, or null when unknown.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id The email id.
	 * @return Email|null
	 */
	public function get( string $id ): ?Email {
		return $this->emails()[ $id ] ?? null;
	}

	/**
	 * Resolve the filtered email list once per request.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, Email>
	 */
	private function emails(): array {
		if ( null !== $this->emails ) {
			return $this->emails;
		}

		/**
		 * Filters registered transactional emails.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, Email> $emails The registered email instances.
		 */
		$emails = apply_filters( 'tiermint_emails', $this->defaultEmails );

		$this->emails = array();

		foreach ( (array) $emails as $email ) {
			if ( ! $email instanceof Email ) {
				_doing_it_wrong(
					__METHOD__,
					esc_html(
						sprintf(
							/* translators: %s: the Email base class name. */
							__( 'An entry added via tiermint_emails must be an instance of %s.', 'tiermint' ),
							Email::class
						)
					),
					'1.0.0'
				);
				continue;
			}

			// A later entry with the same id deliberately wins, so an add-on can
			// replace a core email wholesale.
			$this->emails[ $email->id() ] = $email;
		}

		return $this->emails;
	}
}
