<?php
/**
 * Base class for a transactional email definition.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Emails;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Defines one transactional email.
 * EmailListener builds its context, and Mailer renders and sends it.
 *
 * @since 1.0.0
 */
abstract class Email {

	/**
	 * Store the settings accessor used by the default {@see enabled()} check.
	 *
	 * @since 1.0.0
	 *
	 * @param EmailSettings $settings The transactional email settings accessor.
	 */
	public function __construct( protected readonly EmailSettings $settings ) {}

	/**
	 * Get the registry ID for this email.
	 *
	 * @since 1.0.0
	 *
	 * @return string The email id.
	 */
	abstract public function id(): string;

	/**
	 * Build the localized subject line from the context.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $ctx The assembled context for this send.
	 * @return string The subject line.
	 */
	abstract public function subject( array $ctx ): string;

	/**
	 * Get the email template key.
	 *
	 * @since 1.0.0
	 *
	 * @return string The template key.
	 */
	abstract public function template(): string;

	/**
	 * Get the recipient address from the send context.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $ctx The assembled context for this send.
	 * @return string The recipient address, or '' when the context carries none.
	 */
	public function recipient( array $ctx ): string {
		$to = $ctx['recipient'] ?? '';

		return is_string( $to ) ? $to : '';
	}

	/**
	 * Get the attached file paths.
	 * Mailer validates each path before it calls wp_mail().
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $ctx The assembled context for this send.
	 * @return array<string|int, string> Absolute paths, optionally keyed by display name.
	 */
	public function attachments( array $ctx ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- The context is the whole input an overriding email picks its files from; the default simply has no files.
		return array();
	}

	/**
	 * Check whether this email is enabled.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when the email should be sent.
	 */
	public function enabled(): bool {
		return $this->settings->isEnabled( $this->id() );
	}
}
