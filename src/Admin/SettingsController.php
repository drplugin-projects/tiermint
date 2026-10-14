<?php
/**
 * The settings REST surface for the admin SPA.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Admin;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Frontend\Appearance;
use DrPlugin\WP\TierMint\Payments\PaymentException;
use DrPlugin\WP\TierMint\Payments\Stripe\StripeSettings;
use DrPlugin\WP\TierMint\REST\AbstractRestController;
use DrPlugin\WP\TierMint\Util\CryptoException;
use DrPlugin\WP\TierMint\Webhooks\WebhookEndpoints;
use DrPlugin\WP\TierMint\Webhooks\WebhookNotFoundException;
use DrPlugin\WP\TierMint\Webhooks\WebhookValidationException;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Serves settings and webhook REST routes.
 *
 * Writes validate as a batch. Secret values are set, cleared or left unchanged.
 *
 * @since 1.0.0
 */
final class SettingsController extends AbstractRestController {

	/**
	 * The option holding all non-secret settings.
	 *
	 * @var string
	 */
	private const OPTION = 'tiermint_settings';

	/**
	 * The prefix of a served secret mask.
	 *
	 * @var string
	 */
	private const MASK_PREFIX = "\u{2022}\u{2022}\u{2022}";

	/**
	 * The settings field schema.
	 *
	 * @var SettingsSchema
	 */
	private SettingsSchema $schema;

	/**
	 * The Stripe credential store.
	 *
	 * @var StripeSettings
	 */
	private StripeSettings $stripe;

	/**
	 * The outbound webhook endpoint registry.
	 *
	 * @var WebhookEndpoints
	 */
	private WebhookEndpoints $webhooks;

	/**
	 * Appearance validator shared with the front-end token emitter.
	 *
	 * @var Appearance
	 */
	private Appearance $appearance;

	/**
	 * Wire the controller with its collaborators.
	 *
	 * @since 1.0.0
	 *
	 * @param SettingsSchema   $schema     The settings field schema.
	 * @param StripeSettings   $stripe     The Stripe credential store.
	 * @param WebhookEndpoints $webhooks   The outbound webhook endpoint registry.
	 * @param Appearance       $appearance The appearance override validator.
	 */
	public function __construct( SettingsSchema $schema, StripeSettings $stripe, WebhookEndpoints $webhooks, Appearance $appearance ) {
		$this->schema     = $schema;
		$this->stripe     = $stripe;
		$this->webhooks   = $webhooks;
		$this->appearance = $appearance;
	}

	/**
	 * Register the settings routes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'readSettings' ),
					'permission_callback' => array( $this, 'settingsPermission' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'writeSettings' ),
					'permission_callback' => array( $this, 'settingsPermission' ),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/settings/webhooks',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'listWebhooks' ),
					'permission_callback' => array( $this, 'settingsPermission' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'createWebhook' ),
					'permission_callback' => array( $this, 'settingsPermission' ),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/settings/webhooks/(?P<id>[A-Za-z0-9_\-]+)',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'updateWebhook' ),
					'permission_callback' => array( $this, 'settingsPermission' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'deleteWebhook' ),
					'permission_callback' => array( $this, 'settingsPermission' ),
				),
			)
		);
	}

	/**
	 * Get the settings schema and current values.
	 *
	 * @since 1.0.0
	 *
	 * @return WP_REST_Response The settings payload.
	 */
	public function readSettings(): WP_REST_Response {
		return new WP_REST_Response( $this->settingsPayload(), 200 );
	}

	/**
	 * Validate and save all submitted settings.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request carrying `values`.
	 * @return WP_REST_Response|\WP_Error The fresh payload, or the validation envelope.
	 */
	public function writeSettings( WP_REST_Request $request ) {
		$values = $request->get_json_params()['values'] ?? null;

		if ( ! is_array( $values ) || array() === $values ) {
			return $this->invalidParams( array( __( 'No settings values were submitted.', 'tiermint' ) ) );
		}

		$errors       = array();
		$optionWrites = array();
		$stripeWrites = array(
			'sets'   => array(),
			'clears' => array(),
		);

		foreach ( $values as $fieldId => $value ) {
			$field = $this->schema->field( (string) $fieldId );

			if ( null === $field ) {
				/* translators: %s: the unknown settings field id. */
				$errors[] = sprintf( __( 'Unknown setting: %s.', 'tiermint' ), (string) $fieldId );
				continue;
			}

			if ( 'stripe' === $field['storage'] ) {
				$this->stageStripeWrite( $field, $value, $stripeWrites, $errors );
				continue;
			}

			// Only option-path fields are writable here. Render-only fields
			// (`none`) persist through their owning extension's endpoints, and
			// an unrecognized discriminator must never fall through to an
			// empty-path option write.
			if ( 'option' !== $field['storage'] ) {
				/* translators: %s: the settings field label. */
				$errors[] = sprintf( __( '%s is not writable through this endpoint.', 'tiermint' ), (string) $field['label'] );
				continue;
			}

			$before     = count( $errors );
			$normalized = $this->normalize( $field, $value, $errors );
			if ( count( $errors ) === $before ) {
				$optionWrites[ (string) $field['path'] ] = $normalized;
			}
		}

		if ( array() !== $errors ) {
			return $this->invalidParams( $errors );
		}

		try {
			// All fields validated: apply the staged writes. Throw-capable
			// secret sets run first, then no-throw clears, then the option, so
			// an encryption failure aborts before anything at all persisted.
			foreach ( $stripeWrites['sets'] as $write ) {
				$write();
			}
			foreach ( $stripeWrites['clears'] as $write ) {
				$write();
			}

			if ( array() !== $optionWrites ) {
				$settings = get_option( self::OPTION );
				$settings = is_array( $settings ) ? $settings : array();
				foreach ( $optionWrites as $path => $value ) {
					$this->setPath( $settings, $path, $value );
				}
				update_option( self::OPTION, $settings );
			}
		} catch ( PaymentException $e ) {
			return $this->invalidParams( array( $e->getMessage() ) );
		} catch ( CryptoException $e ) {
			return $this->serverError();
		} catch ( Throwable $e ) {
			return $this->serverError();
		}

		return new WP_REST_Response( $this->settingsPayload(), 200 );
	}

	/**
	 * Get webhook events and endpoints without secrets.
	 *
	 * @since 1.0.0
	 *
	 * @return WP_REST_Response The catalog and endpoint list.
	 */
	public function listWebhooks(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'catalog'   => $this->webhooks->eventCatalog(),
				'endpoints' => array_values( $this->webhooks->all() ),
			),
			200
		);
	}

	/**
	 * Create a webhook endpoint with its one-time signing secret.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request carrying `url` and `events`.
	 * @return WP_REST_Response|\WP_Error The created endpoint with its one-time secret.
	 */
	public function createWebhook( WP_REST_Request $request ) {
		$body   = $request->get_json_params();
		$url    = isset( $body['url'] ) ? (string) $body['url'] : '';
		$events = isset( $body['events'] ) && is_array( $body['events'] ) ? array_map( 'strval', $body['events'] ) : array();

		try {
			$created = $this->webhooks->create( $url, $events );
		} catch ( WebhookValidationException $e ) {
			return $this->invalidParams( array( $e->getMessage() ) );
		} catch ( Throwable $e ) {
			return $this->serverError();
		}

		return new WP_REST_Response( $created, 201 );
	}

	/**
	 * Update a webhook endpoint.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request carrying the changes.
	 * @return WP_REST_Response|\WP_Error The refreshed endpoint record.
	 */
	public function updateWebhook( WP_REST_Request $request ) {
		$body    = $request->get_json_params();
		$changes = array_intersect_key( is_array( $body ) ? $body : array(), array_flip( array( 'url', 'events', 'active' ) ) );

		try {
			$endpoint = $this->webhooks->update( (string) $request['id'], $changes );
		} catch ( WebhookNotFoundException $e ) {
			return $this->notFound( __( 'No such webhook endpoint.', 'tiermint' ) );
		} catch ( WebhookValidationException $e ) {
			return $this->invalidParams( array( $e->getMessage() ) );
		} catch ( Throwable $e ) {
			return $this->serverError();
		}

		return new WP_REST_Response( $endpoint, 200 );
	}

	/**
	 * DELETE /settings/webhooks/{id}: remove an endpoint and its stored secret.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request naming the endpoint.
	 * @return WP_REST_Response|\WP_Error The deletion confirmation.
	 */
	public function deleteWebhook( WP_REST_Request $request ) {
		try {
			$this->webhooks->delete( (string) $request['id'] );
		} catch ( WebhookNotFoundException $e ) {
			return $this->notFound( __( 'No such webhook endpoint.', 'tiermint' ) );
		} catch ( Throwable $e ) {
			return $this->serverError();
		}

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * The full GET payload: sections, client-safe fields, and current values.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed> The settings payload.
	 */
	private function settingsPayload(): array {
		$values = array();

		foreach ( $this->schema->fields() as $id => $field ) {
			$values[ $id ] = $this->valueFor( $field );
		}

		return array(
			'sections' => $this->schema->sections(),
			'fields'   => $this->schema->forClient(),
			'values'   => $values,
		);
	}

	/**
	 * Return a client-safe value for one settings field.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $field The field definition.
	 * @return mixed The client-safe value.
	 */
	private function valueFor( array $field ) {
		if ( 'stripe' === $field['storage'] ) {
			$plain = $this->readStripeSecret( $field );

			// Short values are masked entirely: a last-four suffix on a four
			// character secret would reveal the whole thing.
			$mask = null;
			if ( null !== $plain ) {
				$mask = strlen( $plain ) > 8
					? self::MASK_PREFIX . substr( $plain, -4 )
					: self::MASK_PREFIX;
			}

			return array(
				'set'  => null !== $plain,
				'mask' => $mask,
			);
		}

		// Render-only and unrecognized storages serve no value: the owning
		// extension supplies its own state through its own endpoints.
		if ( 'option' !== $field['storage'] ) {
			return null;
		}

		$settings = get_option( self::OPTION );
		$value    = $this->getPath( is_array( $settings ) ? $settings : array(), (string) $field['path'] );

		if ( null === $value ) {
			$value = $field['default'] ?? null;
		}

		switch ( $field['type'] ) {
			case 'toggle':
				return (bool) $value;
			case 'page':
				return (int) $value;
			default:
				return $value;
		}
	}

	/**
	 * Read the stored plaintext for a stripe-backed secret field.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $field The field definition.
	 * @return string|null The plaintext, or null when unset.
	 */
	private function readStripeSecret( array $field ): ?string {
		$mode = (string) $field['stripe']['mode'];

		return 'webhook_secret' === $field['stripe']['kind']
			? $this->stripe->webhookSecretFor( $mode )
			: $this->stripe->secretKeyFor( $mode );
	}

	/**
	 * Stage a Stripe secret write after validating its value.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed>                $field  The field definition.
	 * @param mixed                               $value  The submitted value.
	 * @param array<string, array<int, callable>> $writes The staged writes grouped into sets and clears, by reference.
	 * @param array<int, string>                  $errors The validation error list, by reference.
	 * @return void
	 */
	private function stageStripeWrite( array $field, $value, array &$writes, array &$errors ): void {
		if ( null !== $value && ! is_string( $value ) ) {
			/* translators: %s: the settings field label. */
			$errors[] = sprintf( __( '%s must be a text value (or null to clear it).', 'tiermint' ), (string) $field['label'] );

			return;
		}

		$mode = (string) ( $field['stripe']['mode'] ?? '' );

		// Validate the mode at staging time so a malformed filter-added field
		// is a 400 before any staged write has executed, not a mid-batch throw.
		if ( ! in_array( $mode, array( 'test', 'live' ), true ) ) {
			/* translators: %s: the settings field label. */
			$errors[] = sprintf( __( '%s has an invalid credential mode.', 'tiermint' ), (string) $field['label'] );

			return;
		}

		$plaintext = null === $value ? '' : trim( $value );
		$kind      = (string) $field['stripe']['kind'];
		$stripe    = $this->stripe;

		$write = static function () use ( $stripe, $kind, $mode, $plaintext ): void {
			if ( 'webhook_secret' === $kind ) {
				$stripe->setWebhookSecret( $mode, $plaintext );
			} else {
				$stripe->setSecretKey( $mode, $plaintext );
			}
		};

		// Sets can throw (encryption); clears cannot (a delete). Group them so
		// the execution loop can run every throw-capable write before any
		// irreversible-but-quiet one: a crypto failure then persists nothing.
		$writes[ '' === $plaintext ? 'clears' : 'sets' ][] = $write;
	}

	/**
	 * Validate and normalize an option-backed field value.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $field  The field definition.
	 * @param mixed                $value  The submitted value.
	 * @param array<int, string>   $errors The validation error list, by reference.
	 * @return mixed The normalized value, or null when rejected (or genuinely null).
	 */
	private function normalize( array $field, $value, array &$errors ) {
		$label = (string) $field['label'];

		// Arrays and objects are never a valid field value; rejecting them up
		// front stops the default case from coercing them to the literal
		// string "Array". Null stays allowed so typed cases coerce it.
		if ( null !== $value && ! is_scalar( $value ) ) {
			/* translators: %s: the settings field label. */
			$errors[] = sprintf( __( '%s received an invalid value type.', 'tiermint' ), $label );

			return null;
		}

		switch ( $field['type'] ) {
			case 'currency':
				$code = strtoupper( trim( (string) $value ) );
				if ( 1 !== preg_match( '/^[A-Z]{3}$/', $code ) ) {
					/* translators: %s: the settings field label. */
					$errors[] = sprintf( __( '%s must be a three-letter ISO currency code.', 'tiermint' ), $label );

					return null;
				}

				return $code;

			case 'toggle':
				return rest_sanitize_boolean( $value );

			case 'page':
				return absint( $value );

			case 'select':
				$allowed = array_column( $field['options'] ?? array(), 'value' );
				$choice  = (string) $value;
				if ( ! in_array( $choice, $allowed, true ) ) {
					/* translators: %s: the settings field label. */
					$errors[] = sprintf( __( '%s received a value outside its allowed choices.', 'tiermint' ), $label );

					return null;
				}

				return $choice;

			case 'email':
				$address = trim( (string) $value );
				if ( '' !== $address && ! is_email( $address ) ) {
					/* translators: %s: the settings field label. */
					$errors[] = sprintf( __( '%s must be a valid email address.', 'tiermint' ), $label );

					return null;
				}

				return $address;

			case 'color':
				$color = $this->appearance->sanitizeColor( $value );
				if ( null === $color ) {
					/* translators: %s: the settings field label. */
					$errors[] = sprintf( __( '%s must be a hex colour such as #2563eb.', 'tiermint' ), $label );

					return null;
				}

				return $color;

			case 'font_stack':
				$font = $this->appearance->sanitizeFontStack( $value );
				if ( null === $font ) {
					/* translators: %s: the settings field label. */
					$errors[] = sprintf( __( '%s must be a list of font names, for example Inter, Arial, sans-serif.', 'tiermint' ), $label );

					return null;
				}

				return $font;

			default:
				return sanitize_text_field( (string) $value );
		}
	}

	/**
	 * Read a dot-path value out of a nested array.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $settings The settings array.
	 * @param string               $path     The dot path, e.g. `pages.checkout`.
	 * @return mixed The value, or null when any segment is absent.
	 */
	private function getPath( array $settings, string $path ) {
		$cursor = $settings;

		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $cursor ) || ! array_key_exists( $segment, $cursor ) ) {
				return null;
			}
			$cursor = $cursor[ $segment ];
		}

		return $cursor;
	}

	/**
	 * Write a dot-path value into a nested array, creating segments as needed.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $settings The settings array, by reference.
	 * @param string               $path     The dot path, e.g. `pages.checkout`.
	 * @param mixed                $value    The value to store.
	 * @return void
	 */
	private function setPath( array &$settings, string $path, $value ): void {
		$segments = explode( '.', $path );
		$leaf     = array_pop( $segments );
		$cursor   = &$settings;

		foreach ( $segments as $segment ) {
			if ( ! isset( $cursor[ $segment ] ) || ! is_array( $cursor[ $segment ] ) ) {
				$cursor[ $segment ] = array();
			}
			$cursor = &$cursor[ $segment ];
		}

		$cursor[ $leaf ] = $value;
	}
}
