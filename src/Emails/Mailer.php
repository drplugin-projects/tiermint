<?php
/**
 * Sends transactional emails through wp_mail with an HTML body and plaintext alt.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Emails;

use DrPlugin\WP\TierMint\Database\Logger;
use Throwable;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Send transactional email through wp_mail with an HTML body and plain-text alternative.
 *
 * Log and contain failures so email cannot fail an order or webhook.
 *
 * @since 1.0.0
 */
class Mailer {

	/**
	 * Renders template keys to HTML bodies.
	 *
	 * @var EmailRenderer
	 */
	private EmailRenderer $renderer;

	/**
	 * Supplies the from name and address.
	 *
	 * @var EmailSettings
	 */
	private EmailSettings $settings;

	/**
	 * The audit log every send is written to.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Wire the mailer with its collaborators.
	 *
	 * @since 1.0.0
	 *
	 * @param EmailRenderer $renderer The template renderer.
	 * @param EmailSettings $settings The from name/address accessor.
	 * @param Logger        $logger   The `_logs` audit writer.
	 */
	public function __construct( EmailRenderer $renderer, EmailSettings $settings, Logger $logger ) {
		$this->renderer = $renderer;
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * Render and send an email.
	 *
	 * Disabled emails do nothing. Successful sends fire the email hook.
	 *
	 * @since 1.0.0
	 *
	 * @param Email                $email The email to send.
	 * @param array<string, mixed> $ctx   The assembled context (recipient, view data).
	 * @return bool True when the message was sent, false otherwise.
	 */
	public function send( Email $email, array $ctx ): bool {
		if ( ! $email->enabled() ) {
			return false;
		}

		$emailId = $email->id();
		$to      = $email->recipient( $ctx );

		try {
			$html = $this->renderer->render( $email->template(), $ctx );

			if ( '' === $html || '' === trim( $to ) ) {
				// A missing template or an unaddressed context cannot produce a
				// deliverable message; contain it as an error rather than calling
				// wp_mail() with junk.
				$this->logFailure( $emailId, $to, 'template missing or recipient empty', $ctx );

				return false;
			}

			$subject = $email->subject( $ctx );
			$headers = $this->headers();
			$altBody = $this->plainText( $html );

			// Do not build attachments for an email that cannot be sent.
			$attachments = $this->attachments( $email, $ctx );
			$attached    = $this->attachAltBody( $altBody );

			try {
				$sent = wp_mail( $to, $subject, $html, $headers, $attachments );
			} finally {
				// Do not leave the AltBody listener attached to later mail.
				remove_action( 'phpmailer_init', $attached );
			}

			if ( false === $sent ) {
				$this->logFailure( $emailId, $to, 'wp_mail returned false', $ctx );

				return false;
			}

			$this->logSuccess( $emailId, $to, $ctx );

			/**
			 * Fires after a transactional email was successfully dispatched.
			 *
			 * @since 1.0.0
			 *
			 * @param string               $emailId The email id, e.g. 'purchase_receipt'.
			 * @param string               $to      The recipient address.
			 * @param array<string, mixed> $context The context the email was sent with.
			 */
			do_action( 'tiermint_email_sent', $emailId, $to, $ctx );

			return true;
		} catch ( Throwable $failure ) {
			$this->logFailure( $emailId, $to, $failure->getMessage(), $ctx );

			return false;
		}
	}

	/**
	 * Build valid attachments from the email and attachment filter.
	 *
	 * Contain filter failures so an attachment problem cannot lose the email.
	 *
	 * @since 1.0.0
	 *
	 * @param Email                $email The email being sent.
	 * @param array<string, mixed> $ctx   The assembled context for this send.
	 * @return array<string|int, string> Attachable paths, optionally keyed by display name.
	 */
	private function attachments( Email $email, array $ctx ): array {
		$emailId = $email->id();

		try {
			$declared = $email->attachments( $ctx );

			/**
 * Filter transactional email attachments.
 *
 * Values must be readable absolute paths. String keys set display names.
			 *
			 * @since 1.0.0
			 *
			 * @param array<string|int, string> $attachments The attachments so far.
			 * @param string                    $emailId     The email id, e.g. 'purchase_receipt'.
			 * @param array<string, mixed>      $context     The context the email is being sent with.
			 */
			$declared = apply_filters( 'tiermint_email_attachments', $declared, $emailId, $ctx );

			if ( ! is_array( $declared ) ) {
				$this->logAttachmentLoss( $emailId, $ctx, 'a filter callback returned something that is not a list of attachments' );

				return array();
			}

			// Sanitising display names invokes filters that can throw.
			return $this->attachable( $emailId, $ctx, $declared );
		} catch ( Throwable $failure ) {
			$this->logAttachmentLoss( $emailId, $ctx, sprintf( 'assembling the attachments failed: %s', $failure->getMessage() ) );

			return array();
		}
	}

	/**
	 * Keep only safe attachments for wp_mail().
	 *
	 * Invalid paths are logged so a malformed attachment cannot lose the email.
	 *
	 * @since 1.0.0
	 *
	 * @param string                   $emailId  The email id.
	 * @param array<string, mixed>     $ctx      The send context (for the log's object ref).
	 * @param array<string|int, mixed> $declared The contributed attachments.
	 * @return array<string|int, string> The attachable subset.
	 */
	private function attachable( string $emailId, array $ctx, array $declared ): array {
		$attachable = array();

		foreach ( $declared as $name => $path ) {
			if ( ! is_string( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
				$label = is_string( $name ) ? $name : '(unnamed)';

				$this->logAttachmentLoss(
					$emailId,
					$ctx,
					sprintf( 'the file offered as "%s" is not a readable file', $label ),
					is_string( $path ) ? $path : ''
				);
				continue;
			}

			// Do not attach the same file twice.
			if ( in_array( $path, $attachable, true ) ) {
				continue;
			}

			$display = is_string( $name ) ? sanitize_file_name( $name ) : '';

			// Let wp_mail derive a name when the display name is empty or duplicated.
			if ( '' === $display || array_key_exists( $display, $attachable ) ) {
				$attachable[] = $path;
				continue;
			}

			$attachable[ $display ] = $path;
		}

		return $attachable;
	}

	/**
	 * Log an attachment that could not be sent.
	 *
	 * Record only its directory because file names can contain secrets.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $emailId The email id.
	 * @param array<string, mixed> $ctx     The send context.
	 * @param string               $reason  Why the attachment was dropped.
	 * @param string               $path    The offered path, when there was one.
	 * @return void
	 */
	private function logAttachmentLoss( string $emailId, array $ctx, string $reason, string $path = '' ): void {
		$this->logger->log(
			'error',
			sprintf( 'The %s email was sent without an attachment: %s.', $emailId, $reason ),
			array(
				'email_id'  => $emailId,
				'order_id'  => $this->orderId( $ctx ),
				'directory' => '' !== $path ? dirname( $path ) : '',
			),
			'order',
			$this->orderId( $ctx )
		);
	}

	/**
	 * Return HTML and configured sender headers.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, string> The wp_mail() header lines.
	 */
	private function headers(): array {
		return array(
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', $this->settings->fromName(), $this->settings->fromAddress() ),
		);
	}

	/**
	 * Attach a one-shot listener that sets the plain-text body.
	 *
	 * @since 1.0.0
	 *
	 * @param string $altBody The plaintext alternative body.
	 * @return callable The attached listener, for removal after the send.
	 */
	private function attachAltBody( string $altBody ): callable {
		$listener = static function ( $phpmailer ) use ( $altBody ): void {
			// AltBody makes a readable multipart message for text-only clients.
			$phpmailer->AltBody = $altBody;
		};

		add_action( 'phpmailer_init', $listener );

		return $listener;
	}

	/**
	 * Make a plain-text body and retain link targets.
	 *
	 * @since 1.0.0
	 *
	 * @param string $html The rendered HTML body.
	 * @return string The plaintext alternative.
	 */
	private function plainText( string $html ): string {
		// Preserve link targets before removing markup.
		$withLinks = preg_replace_callback(
			'/<a\b[^>]*\bhref=(["\'])(.*?)\1[^>]*>(.*?)<\/a>/is',
			static function ( array $anchor ): string {
				$url   = $anchor[2];
				$label = trim( wp_strip_all_tags( $anchor[3] ) );

				return '' === $label ? $url : $label . ': ' . $url;
			},
			$html
		);

		if ( null === $withLinks ) {
			// A regex error must not lose the email.
			$withLinks = $html;
		}

		$text = wp_strip_all_tags( $withLinks );

		// Normalise whitespace left after removing markup.
		$text = (string) preg_replace( '/[ \t]+/', ' ', $text );
		$text = (string) preg_replace( "/(\r?\n)[ \t]*(\r?\n)+/", "\n\n", $text );

		return trim( $text );
	}

	/**
	 * Record a successful send in the audit log under type 'email'.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $emailId The email id.
	 * @param string               $to      The recipient address.
	 * @param array<string, mixed> $ctx     The send context (for object refs).
	 * @return void
	 */
	private function logSuccess( string $emailId, string $to, array $ctx ): void {
		$this->logger->log(
			'email',
			sprintf( 'Sent %s email to %s.', $emailId, $to ),
			$this->logContext( $emailId, $to, $ctx, true ),
			'order',
			$this->orderId( $ctx )
		);
	}

	/**
	 * Record a contained send failure in the audit log under type 'error'.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $emailId The email id.
	 * @param string               $to      The recipient address (may be empty).
	 * @param string               $reason  Why the send did not happen.
	 * @param array<string, mixed> $ctx     The send context (for object refs).
	 * @return void
	 */
	private function logFailure( string $emailId, string $to, string $reason, array $ctx ): void {
		$this->logger->log(
			'error',
			sprintf( 'Failed to send %s email to %s: %s', $emailId, '' === $to ? '(no recipient)' : $to, $reason ),
			$this->logContext( $emailId, $to, $ctx, false ),
			'order',
			$this->orderId( $ctx )
		);
	}

	/**
	 * Build structured email log context.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $emailId The email id.
	 * @param string               $to      The recipient address.
	 * @param array<string, mixed> $ctx     The send context.
	 * @param bool                 $success Whether the send succeeded.
	 * @return array<string, mixed> The log context.
	 */
	private function logContext( string $emailId, string $to, array $ctx, bool $success ): array {
		return array(
			'email_id'   => $emailId,
			'to'         => $to,
			'success'    => $success,
			'order_id'   => $this->orderId( $ctx ),
			'license_id' => isset( $ctx['license_id'] ) ? (int) $ctx['license_id'] : null,
		);
	}

	/**
	 * The order id carried in a send context, or null when there is none.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $ctx The send context.
	 * @return int|null The order id, or null.
	 */
	private function orderId( array $ctx ): ?int {
		$id = $ctx['order']['id'] ?? null;

		return null === $id ? null : (int) $id;
	}
}
