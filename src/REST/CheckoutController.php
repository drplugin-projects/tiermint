<?php
/**
 * Public REST controller for the checkout intent, quote and status routes.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\REST;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Catalog\Countries;
use DrPlugin\WP\TierMint\Customers\Customer;
use DrPlugin\WP\TierMint\Customers\CustomerValidationException;
use DrPlugin\WP\TierMint\Frontend\CheckoutFieldSettings;
use DrPlugin\WP\TierMint\Orders\Order;
use DrPlugin\WP\TierMint\Orders\OrderPersistenceException;
use DrPlugin\WP\TierMint\Orders\OrderStatus;
use DrPlugin\WP\TierMint\Orders\OrderValidationException;
use DrPlugin\WP\TierMint\Payments\CheckoutService;
use DrPlugin\WP\TierMint\Payments\GatewayConfigurationException;
use DrPlugin\WP\TierMint\Payments\GatewayNotFoundException;
use DrPlugin\WP\TierMint\Payments\PaymentException;
use DrPlugin\WP\TierMint\Util\RateLimiter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Public REST controller for checkout intent, quote and status routes.
 *
 * Orders use server-side prices. Extensions can add checkout context only.
 *
 * @since 1.0.0
 */
final class CheckoutController extends AbstractRestController {

	/**
	 * Order meta key for the terms acceptance time.
	 *
	 * @var string
	 */
	public const TERMS_META = '_tiermint_terms_accepted_at';

	/**
	 * Order meta key for the billing address.
	 *
	 * @var string
	 */
	public const ADDRESS_META = '_tiermint_billing_address';

	/**
	 * Order meta key for a company purchase name.
	 *
	 * @var string
	 */
	public const COMPANY_META = '_tiermint_company_name';

	/**
	 * Order meta key for the VAT or tax ID the buyer typed, normalized.
	 *
	 * @var string
	 */
	public const TAX_ID_META = '_tiermint_tax_id';

	/**
	 * The checkout orchestration service.
	 *
	 * @var CheckoutService
	 */
	private CheckoutService $checkout;

	/**
	 * The fixed-window rate limiter guarding the intent and quote routes.
	 *
	 * @var RateLimiter
	 */
	private RateLimiter $limiter;

	/**
	 * Checkout and account settings.
	 *
	 * @var CheckoutFieldSettings
	 */
	private CheckoutFieldSettings $fields;

	/**
	 * Wire the controller with the checkout service and the rate limiter.
	 *
	 * @since 1.0.0
	 *
	 * @param CheckoutService            $checkout The checkout orchestration service.
	 * @param RateLimiter                $limiter  The transient-backed rate limiter.
	 * @param CheckoutFieldSettings|null $fields   Which optional fields the checkout collects.
	 */
	public function __construct( CheckoutService $checkout, RateLimiter $limiter, ?CheckoutFieldSettings $fields = null ) {
		$this->checkout = $checkout;
		$this->limiter  = $limiter;
		$this->fields   = $fields ?? new CheckoutFieldSettings();
	}

	/**
	 * Register public checkout routes with scoped abuse limits.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			'/checkout/intent',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'intent' ),
					// Public because guests check out; rate limited per client IP.
					'permission_callback' => '__return_true',
					'args'                => $this->intentArgs(),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/checkout/quote',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'quote' ),
					// Public because guests see the totals before they pay; rate limited per client IP.
					'permission_callback' => '__return_true',
					'args'                => $this->quoteArgs(),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/checkout/status/(?P<uuid>[0-9a-fA-F\-]{36})',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'status' ),
					// Public because the payment return page polls it; the order UUID cannot be guessed.
					'permission_callback' => '__return_true',
					'args'                => $this->uuidArgs(),
				),
			)
		);
	}

	/**
	 * Create a pending order and a gateway payment session.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error The session bootstrap, or an error envelope.
	 */
	public function intent( WP_REST_Request $request ) {
		$limited = $this->rateLimit();

		if ( null !== $limited ) {
			return $limited;
		}

		$authError = $this->authError();

		if ( null !== $authError ) {
			return $authError;
		}

		$buyer = $this->resolveBuyer( $request );

		if ( '' === (string) ( $buyer['email'] ?? '' ) ) {
			return $this->invalidParams( array( __( 'An email address is required to check out.', 'tiermint' ) ) );
		}

		$compliance = $this->complianceError( $request );

		if ( null !== $compliance ) {
			return $compliance;
		}

		$context = $this->requestContext( $request );

		try {
			$result = $this->checkout->createIntent(
				(string) $request->get_param( 'gateway' ),
				$buyer,
				$context
			);
		} catch ( GatewayNotFoundException $e ) {
			return $this->invalidParams( array( $e->getMessage() ) );
		} catch ( GatewayConfigurationException $e ) {
			return $this->error( 'tiermint_gateway_not_configured', $e->getMessage(), 503 );
		} catch ( \DrPlugin\WP\TierMint\Discounts\DiscountValidationException $e ) {
			// A distinct code so clients can tell a rejected discount from
			// other validation failures (checkout.js currently announces the
			// translated message in its shared alert region).
			return $this->error( 'tiermint_invalid_discount', $e->getMessage(), 400 );
		} catch ( \DrPlugin\WP\TierMint\Discounts\DiscountPersistenceException $e ) {
			return $this->serverError( __( 'The discount code could not be checked. Please try again.', 'tiermint' ) );
		} catch ( OrderValidationException $e ) {
			return $this->invalidParams( $e->getErrors() );
		} catch ( CustomerValidationException $e ) {
			return $this->invalidParams( $e->getErrors() );
		} catch ( OrderPersistenceException $e ) {
			return $this->serverError( __( 'The order could not be created. Please try again.', 'tiermint' ) );
		} catch ( PaymentException $e ) {
			// The gateway call failed after the order was created (the service already
			// marked the order failed). The gateway's message is log-only detail.
			return $this->error(
				'tiermint_gateway_error',
				__( 'The payment could not be started. Please try again.', 'tiermint' ),
				502
			);
		}

		/**
		 * The pending order and its gateway session.
		 *
		 * @var Order                                             $order
		 * @var \DrPlugin\WP\TierMint\Payments\PaymentSession     $session
		 */
		$order   = $result['order'];
		$session = $result['session'];

		$this->recordCompliance( $order, $request );

		// Defensive: a checkout service double (or a future one) that reports no
		// notices degrades to an empty list, so the key is always present and
		// always a list of strings the storefront can render without checking.
		$notices = isset( $result['notices'] ) && is_array( $result['notices'] )
			? array_values( array_filter( $result['notices'], 'is_string' ) )
			: array();

		// The code and automatic-discount components as they landed (same shape quote() sends),
		// so the checkout module can revert the correct summary line if a discount dies at intent.
		$breakdown = isset( $result['discount'] ) && is_array( $result['discount'] ) ? $result['discount'] : array();

		return new WP_REST_Response(
			array(
				'order_uuid'      => $order->uuid(),
				'status'          => $order->status()->value,
				'gateway'         => $session->gateway(),
				'amount'          => $order->total(),
				// The breakdown behind `amount`, so the confirmation screen can show a
				// tax line without a second round trip. `amount` stays the grand total
				// every existing client already reads and every gateway is charged.
				'subtotal'        => $order->subtotal(),
				// The reduction the buyer was shown, matching the quote, so checkout.js
				// reconciles. It can differ from the stored line discounts when an
				// extension rebased a line.
				'discount_total'  => $this->displayedDiscountTotal( $breakdown ),
				// The typed code's landed contribution (`{valid, code, label, amount}` or null)
				// and the automatic discount's (`{label, amount}` or null), so the module reverts
				// or redraws the correct line when a reduction changes between paint and payment.
				'discount'        => isset( $breakdown['discount'] ) ? $breakdown['discount'] : null,
				'auto_discount'   => isset( $breakdown['auto_discount'] ) ? $breakdown['auto_discount'] : null,
				'tax_total'       => $order->taxTotal(),
				'currency'        => $order->currency(),
				// Why the money looks the way it does, from
				// `tiermint_checkout_quote_notices` at charge time. The quote route
				// deliberately never consults a VAT-number checker, so reverse charge
				// is decided here and the total can legitimately fall between the
				// preview and this response: these are what let the storefront say so
				// instead of reporting a price change nobody made.
				'notices'         => $notices,
				'client_secret'   => $session->clientSecret(),
				'publishable_key' => $session->publishableKey(),
				// The gateway-side id (Stripe: the PaymentIntent) and, for a gateway that
				// redirects the buyer, the approval URL the browser navigates to. Null for
				// in-page gateways such as Stripe.
				'transaction_id'  => $session->transactionId(),
				'redirect_url'    => $session->redirectUrl(),
			),
			201
		);
	}

	/**
	 * Price the current cart without creating an order.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error The priced quote, or an error envelope.
	 */
	public function quote( WP_REST_Request $request ) {
		$limited = $this->rateLimit( 'checkout_quote' );

		if ( null !== $limited ) {
			return $limited;
		}

		// A submitted discount code turns this cheap, generous route into a
		// code-probing oracle, so it draws on a second, tight budget. Peek (do not
		// spend) on entry: a caller already over that budget is turned away before
		// the discount engine is ever consulted, while a shopper re-quoting a code
		// that is valid never touches this scope at all.
		$probing = '' !== trim( (string) $request->get_param( 'discount_code' ) );

		if ( $probing ) {
			$limited = $this->rateLimitPeek( 'checkout_discount' );

			if ( null !== $limited ) {
				return $limited;
			}
		}

		try {
			$quote = $this->checkout->quote( $this->requestContext( $request ) );
		} catch ( \DrPlugin\WP\TierMint\Discounts\DiscountPersistenceException $e ) {
			return $this->serverError( __( 'The discount code could not be checked. Please try again.', 'tiermint' ) );
		} catch ( OrderValidationException $e ) {
			// An empty cart or a line that is no longer purchasable: the same 400 the
			// intent route answers, because there is no honest price to quote.
			return $this->invalidParams( $e->getErrors() );
		}

		// Spend a probe unit only when the submitted code came back invalid. A valid
		// apply, and the many re-quotes that follow it as the buyer fills the rest of
		// the form, cost nothing; only brute-force guessing burns the tight window.
		if ( $probing && isset( $quote['discount']['valid'] ) && false === $quote['discount']['valid'] ) {
			$this->rateLimitSpend( 'checkout_discount' );
		}

		return new WP_REST_Response( $quote, 200 );
	}

	/**
	 * Get the minimal payment status for an order UUID.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error The status payload, or the not-found envelope.
	 */
	public function status( WP_REST_Request $request ) {
		$order = $this->checkout->status( (string) $request->get_param( 'uuid' ) );

		if ( null === $order ) {
			return $this->notFound();
		}

		return new WP_REST_Response(
			array(
				'order_uuid' => $order->uuid(),
				'status'     => $order->status()->value,
				'completed'  => OrderStatus::Completed === $order->status(),
			),
			200
		);
	}

	/**
	 * Spend a request from a checkout rate-limit scope.
	 *
	 * @since 1.0.0
	 *
	 * @param string $scope The limiter scope, 'checkout_intent' or 'checkout_quote'.
	 * @return WP_REST_Response|null The 429 response, or null when the request may proceed.
	 */
	private function rateLimit( string $scope = 'checkout_intent' ): ?WP_REST_Response {
		list( $limit, $window ) = $this->limitFor( $scope );

		$retryAfter = $this->limiter->check( $scope, $this->clientIp(), $limit, $window );

		return null === $retryAfter ? null : $this->tooMany( $retryAfter );
	}

	/**
	 * Check a rate-limit scope without spending from it.
	 *
	 * @since 1.0.0
	 *
	 * @param string $scope The limiter scope.
	 * @return WP_REST_Response|null The 429 response, or null when the request may proceed.
	 */
	private function rateLimitPeek( string $scope ): ?WP_REST_Response {
		list( $limit, $window ) = $this->limitFor( $scope );

		$retryAfter = $this->limiter->peek( $scope, $this->clientIp(), $limit, $window );

		return null === $retryAfter ? null : $this->tooMany( $retryAfter );
	}

	/**
	 * Spend one unit from a rate-limit scope.
	 *
	 * @since 1.0.0
	 *
	 * @param string $scope The limiter scope.
	 * @return void
	 */
	private function rateLimitSpend( string $scope ): void {
		list( $limit, $window ) = $this->limitFor( $scope );

		$this->limiter->check( $scope, $this->clientIp(), $limit, $window );
	}

	/**
	 * Get a rate-limit scope's limit and window.
	 *
	 * @since 1.0.0
	 *
	 * @param string $scope The limiter scope.
	 * @return array{0: int, 1: int} The resolved limit and window.
	 */
	private function limitFor( string $scope ): array {
		$defaults = array(
			'checkout_intent'   => array(
				'limit'  => 10,
				'window' => 600,
			),
			'checkout_quote'    => array(
				'limit'  => 60,
				'window' => 600,
			),
			'checkout_discount' => array(
				'limit'  => 10,
				'window' => 600,
			),
		);

		/**
		 * Filters REST rate limits by scope.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, array{limit: int, window: int}> $limits The limit map.
		 */
		$limits = apply_filters( 'tiermint_rest_rate_limits', $defaults );

		$fallback   = $defaults[ $scope ] ?? $defaults['checkout_intent'];
		$configured = is_array( $limits ) && isset( $limits[ $scope ] ) && is_array( $limits[ $scope ] )
			? $limits[ $scope ]
			: $fallback;
		$limit      = max( 1, (int) ( $configured['limit'] ?? $fallback['limit'] ) );
		$window     = max( 1, (int) ( $configured['window'] ?? $fallback['window'] ) );

		return array( $limit, $window );
	}

	/**
	 * Build the shared 429 envelope with its Retry-After header.
	 *
	 * @since 1.0.0
	 *
	 * @param int $retryAfter Seconds until the window resets.
	 * @return WP_REST_Response The 429 response.
	 */
	private function tooMany( int $retryAfter ): WP_REST_Response {
		$response = new WP_REST_Response(
			array(
				'code'    => 'tiermint_rate_limited',
				'message' => __( 'Too many checkout attempts. Please wait a moment and try again.', 'tiermint' ),
				'data'    => array( 'status' => 429 ),
			),
			429
		);
		$response->header( 'Retry-After', (string) $retryAfter );

		return $response;
	}

	/**
	 * Resolve a buyer without linking another person's customer record.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return array<string, mixed> Buyer identity for CustomerService::findOrCreate.
	 */
	private function resolveBuyer( WP_REST_Request $request ): array {
		$email     = trim( (string) $request->get_param( 'email' ) );
		$firstName = trim( (string) $request->get_param( 'first_name' ) );
		$lastName  = trim( (string) $request->get_param( 'last_name' ) );

		// The split fields win over the legacy single name: when either is given, the
		// canonical display name is their trimmed join, so a blank part never leaves a
		// stray space. The legacy `name` arg (still accepted for external callers)
		// stands only when no split name was sent.
		$name = ( '' !== $firstName || '' !== $lastName )
			? trim( $firstName . ' ' . $lastName )
			: trim( (string) $request->get_param( 'name' ) );

		$buyer  = array();
		$userId = get_current_user_id();

		if ( $userId > 0 ) {
			$accountEmail = (string) wp_get_current_user()->user_email;

			// With guest checkout off, a logged-in buyer always checks out as
			// themselves: the account email replaces whatever was submitted, so the
			// order links to their account and can never be pinned to another
			// person's address. Substituting the email (rather than attaching the
			// user id to a mismatched one) is what keeps CustomerService from
			// relinking a guest record owned by that address to this account.
			if ( ! $this->fields->guestCheckout() ) {
				$email = $accountEmail;
			}

			$isOwnEmail = '' === $email
				|| Customer::normalizeEmail( $email ) === Customer::normalizeEmail( $accountEmail );

			if ( $isOwnEmail ) {
				$buyer['user_id'] = $userId;
				$email            = '' === $email ? $accountEmail : $email;
			}
		}

		$buyer['email'] = $email;

		if ( '' !== $name ) {
			$buyer['name'] = $name;
		}

		if ( '' !== $firstName ) {
			$buyer['first_name'] = $firstName;
		}

		if ( '' !== $lastName ) {
			$buyer['last_name'] = $lastName;
		}

		return $buyer;
	}

	/**
	 * Build checkout pricing context and allow extension fields.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return array<string, mixed> The context for OrderService.
	 */
	private function requestContext( WP_REST_Request $request ): array {
		$context = array( 'ip_address' => $this->clientIp() );

		$discountCode = trim( (string) $request->get_param( 'discount_code' ) );

		if ( '' !== $discountCode ) {
			$context['discount_code'] = $discountCode;
		}

		// The billing fields core collects itself (country, VAT, address) go in
		// before the filters, so a listener can read them, and are re-asserted
		// after (see below), so a listener can never overwrite what core collected.
		$collected = $this->collectBilling( $request );
		$context   = array_merge( $context, $collected );

		/**
		 * Filters checkout context before pricing.
		 * Core restores the client IP and discount code after this filter.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $context The context so far (`ip_address`, and
		 *        `discount_code` on the intent route when the buyer submitted one).
		 * @param WP_REST_Request      $request The incoming checkout request.
		 */
		$filtered = apply_filters( 'tiermint_checkout_intent_context', $context, $request );

		if ( ! is_array( $filtered ) ) {
			return $context;
		}

		$sanitized = array();

		foreach ( $filtered as $key => $value ) {
			if ( ! is_string( $key ) || '' === trim( $key ) ) {
				continue;
			}

			$sanitized[ $key ] = $value;
		}

		// Core's own resolutions win, applied last. The IP is evidence and the code is
		// what the buyer actually typed; neither is an extension's to rewrite. Unsetting
		// (rather than only overwriting) the code matters: without it a listener could
		// inject a discount into a checkout the buyer never asked to discount.
		$sanitized['ip_address'] = $context['ip_address'];

		if ( isset( $context['discount_code'] ) ) {
			$sanitized['discount_code'] = $context['discount_code'];
		} else {
			unset( $sanitized['discount_code'] );
		}

		// Re-assert what core collected, and only that: a country or VAT number
		// core read from the buyer wins over a filter, but a value core did not
		// collect (the toggles are off) is left to whatever the filter supplied,
		// so a tax extension still works on a site with the core toggles off.
		foreach ( array( 'billing_country', 'country', 'vat_number', 'billing_address' ) as $key ) {
			if ( isset( $collected[ $key ] ) ) {
				$sanitized[ $key ] = $collected[ $key ];
			}
		}

		return $sanitized;
	}

	/**
	 * Read normalized billing fields for checkout context.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return array<string, mixed> The collected context keys (may be empty).
	 */
	private function collectBilling( WP_REST_Request $request ): array {
		$collected = array();

		if ( $this->fields->collectCountry() ) {
			$country = strtoupper( trim( (string) $request->get_param( 'billing_country' ) ) );

			if ( 1 === preg_match( '/\A[A-Z]{2}\z/', $country ) && Countries::has( $country ) ) {
				$collected['billing_country'] = $country;
				$collected['country']         = $country;
			}
		}

		if ( $this->fields->collectVat() ) {
			$vat = $this->normalizeVat( (string) $request->get_param( 'vat_number' ) );

			if ( '' !== $vat ) {
				$collected['vat_number'] = $vat;
			}
		}

		$address = $this->billingAddress( $request );

		if ( array() !== $address ) {
			$collected['billing_address'] = $address;
		}

		return $collected;
	}

	/**
	 * Get non-empty billing-address fields.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return array<string, string> The address parts that were filled in.
	 */
	private function billingAddress( WP_REST_Request $request ): array {
		if ( ! $this->fields->collectsAddress() ) {
			return array();
		}

		$parts = array(
			'address_1' => 'billing_address_1',
			'address_2' => 'billing_address_2',
			'city'      => 'billing_city',
			'state'     => 'billing_state',
			'postcode'  => 'billing_postcode',
		);

		$address = array();

		foreach ( $parts as $key => $param ) {
			$value = trim( (string) $request->get_param( $param ) );

			if ( '' !== $value ) {
				$address[ $key ] = $value;
			}
		}

		return $address;
	}

	/**
	 * Normalize a VAT or tax ID.
	 *
	 * @since 1.0.0
	 *
	 * @param string $raw The submitted value.
	 * @return string The normalized number, or '' when blank.
	 */
	private function normalizeVat( string $raw ): string {
		return strtoupper( (string) preg_replace( '/[\s.\-]/', '', $raw ) );
	}

	/**
	 * Refuse an intent when checkout requires a signed-in buyer.
	 *
	 * @since 1.0.0
	 *
	 * @return WP_Error|null The 401 envelope, or null when the checkout may proceed.
	 */
	private function authError(): ?WP_Error {
		if ( $this->fields->loginRequired() && ! is_user_logged_in() ) {
			return $this->error(
				'tiermint_login_required',
				__( 'Please log in to complete your purchase.', 'tiermint' ),
				401
			);
		}

		return null;
	}

	/**
	 * Refuse an intent with missing required compliance fields.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_Error|null The 400 envelope, or null when the checkout may proceed.
	 */
	private function complianceError( WP_REST_Request $request ): ?WP_Error {
		if ( $this->fields->requireFirstName() && '' === trim( (string) $request->get_param( 'first_name' ) ) ) {
			return $this->invalidParams( array( __( 'Please enter your first name to continue.', 'tiermint' ) ) );
		}

		if ( $this->fields->requireLastName() && '' === trim( (string) $request->get_param( 'last_name' ) ) ) {
			return $this->invalidParams( array( __( 'Please enter your last name to continue.', 'tiermint' ) ) );
		}

		if ( $this->fields->requireTerms() && '1' !== trim( (string) $request->get_param( 'terms_accepted' ) ) ) {
			return $this->invalidParams( array( __( 'Please accept the terms and conditions to continue.', 'tiermint' ) ) );
		}

		if ( $this->fields->addressRequired() ) {
			$line = trim( (string) $request->get_param( 'billing_address_1' ) );
			$city = trim( (string) $request->get_param( 'billing_city' ) );

			if ( '' === $line || '' === $city ) {
				return $this->invalidParams( array( __( 'Please enter your billing address to continue.', 'tiermint' ) ) );
			}
		}

		return null;
	}

	/**
	 * Store checkout compliance evidence without failing payment setup.
	 *
	 * @since 1.0.0
	 *
	 * @param Order           $order   The order just created.
	 * @param WP_REST_Request $request The incoming request.
	 * @return void
	 */
	private function recordCompliance( Order $order, WP_REST_Request $request ): void {
		$orderId = $order->id();

		if ( null === $orderId ) {
			return;
		}

		try {
			if ( $this->fields->requireTerms() && '1' === trim( (string) $request->get_param( 'terms_accepted' ) ) ) {
				update_metadata( 'tiermint_order', $orderId, self::TERMS_META, gmdate( 'Y-m-d H:i:s' ) );
			}

			$address = $this->billingAddress( $request );

			if ( array() !== $address ) {
				update_metadata( 'tiermint_order', $orderId, self::ADDRESS_META, $address );
			}

			// Company identity is point-of-sale truth (the same buyer can purchase
			// personally next time), so it lives on the order, not the customer. Gated
			// on the setting so a scripted POST cannot record one when the feature is
			// off, and written only when the buyer actually named a company.
			if ( $this->fields->collectCompany() ) {
				$company = trim( (string) $request->get_param( 'company_name' ) );

				if ( '' !== $company ) {
					update_metadata( 'tiermint_order', $orderId, self::COMPANY_META, $company );
				}
			}

			// The tax ID follows the same rule: written only when the field is
			// on and the buyer typed one, so an invoice can print a number no
			// tax extension checked (a GSTIN, an ABN).
			if ( $this->fields->collectVat() ) {
				$taxId = $this->normalizeVat( (string) $request->get_param( 'vat_number' ) );

				if ( '' !== $taxId ) {
					update_metadata( 'tiermint_order', $orderId, self::TAX_ID_META, $taxId );
				}
			}
		} catch ( \Throwable $e ) {
			// The order and its payment session already exist. Losing a compliance
			// meta write is a merchant-side convenience gone missing, never a reason
			// to 500 a checkout the buyer is one click from paying.
			return;
		}
	}

	/**
	 * The discount the buyer sees, from the code and automatic amounts.
	 *
	 * The intent's `discount_total`, which a rebased line can book lower.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $breakdown The discount breakdown (`discount`, `auto_discount`).
	 * @return int The displayed reduction in minor units.
	 */
	private function displayedDiscountTotal( array $breakdown ): int {
		$total = 0;

		$code = $breakdown['discount'] ?? null;

		if ( is_array( $code ) && true === ( $code['valid'] ?? false ) && is_numeric( $code['amount'] ?? null ) ) {
			$total += (int) $code['amount'];
		}

		$auto = $breakdown['auto_discount'] ?? null;

		if ( is_array( $auto ) && is_numeric( $auto['amount'] ?? null ) ) {
			$total += (int) $auto['amount'];
		}

		return $total;
	}

	/**
	 * Get the direct client IP for rate limiting.
	 *
	 * @since 1.0.0
	 *
	 * @return string The client IP, or 'unknown' when unavailable.
	 */
	private function clientIp(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';

		return '' !== $ip ? $ip : 'unknown';
	}

	/**
	 * Get intent-body parameter schema.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>> The parameter schema.
	 */
	private function intentArgs(): array {
		$args = array(
			'gateway'       => array(
				'description'       => __( 'The payment gateway to pay through.', 'tiermint' ),
				'type'              => 'string',
				'default'           => 'stripe',
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'email'         => array(
				'description'       => __( 'The buyer email address (required for guests).', 'tiermint' ),
				'type'              => 'string',
				'format'            => 'email',
				'sanitize_callback' => 'sanitize_email',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'name'          => array(
				'description'       => __( 'The buyer name (superseded by first_name and last_name).', 'tiermint' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'first_name'    => array(
				'description'       => __( 'The buyer first name.', 'tiermint' ),
				'type'              => 'string',
				'sanitize_callback' => static fn ( $value ): string => self::boundText( sanitize_text_field( (string) $value ), 200 ),
				'validate_callback' => 'rest_validate_request_arg',
			),
			'last_name'     => array(
				'description'       => __( 'The buyer last name.', 'tiermint' ),
				'type'              => 'string',
				'sanitize_callback' => static fn ( $value ): string => self::boundText( sanitize_text_field( (string) $value ), 200 ),
				'validate_callback' => 'rest_validate_request_arg',
			),
			'company_name'  => array(
				'description'       => __( 'The buyer company name, for a company purchase.', 'tiermint' ),
				'type'              => 'string',
				'sanitize_callback' => static fn ( $value ): string => self::boundText( sanitize_text_field( (string) $value ), 200 ),
				'validate_callback' => 'rest_validate_request_arg',
			),
			'discount_code' => array(
				'description'       => __( 'A discount code to apply to the order (validated and computed server-side).', 'tiermint' ),
				'type'              => 'string',
				'maxLength'         => 50,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);

		$args = array_merge( $args, $this->billingArgs() );

		/**
		 * Filters public checkout route parameters.
		 * Extensions can add parameters but cannot replace core parameters.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, array<string, mixed>> $args The parameter schema so far.
		 */
		$filtered = apply_filters( 'tiermint_checkout_intent_args', $args );

		if ( ! is_array( $filtered ) ) {
			return $args;
		}

		$sanitized = array();

		foreach ( $filtered as $name => $schema ) {
			if ( ! is_string( $name ) || '' === trim( $name ) || ! is_array( $schema ) ) {
				continue;
			}

			$sanitized[ $name ] = $schema;
		}

		// Core's own declarations win on a key collision, applied last.
		return array_merge( $sanitized, $args );
	}

	/**
	 * Get core billing and compliance parameter definitions.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>> The parameter schema.
	 */
	private function billingArgs(): array {
		$args = array();

		if ( $this->fields->collectCountry() ) {
			$args['billing_country'] = array(
				'description'       => __( 'The buyer billing country as an ISO 3166-1 alpha-2 code.', 'tiermint' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => static fn ( $value ): string => strtoupper( trim( sanitize_text_field( (string) $value ) ) ),
			);
		}

		if ( $this->fields->collectVat() ) {
			$args['vat_number'] = array(
				'description'       => __( 'The buyer VAT or tax registration number.', 'tiermint' ),
				'type'              => 'string',
				'required'          => false,
				// Bounded (a real VAT/tax id is under 20 chars) so an oversized
				// value cannot bloat the order meta a tax extension writes from it.
				'sanitize_callback' => static fn ( $value ): string => self::boundText( strtoupper( (string) preg_replace( '/[\s.\-]/', '', sanitize_text_field( (string) $value ) ) ), 64 ),
			);
		}

		if ( $this->fields->collectsAddress() ) {
			$addressFields = array(
				'billing_address_1' => __( 'The billing address, line one.', 'tiermint' ),
				'billing_address_2' => __( 'The billing address, line two.', 'tiermint' ),
				'billing_city'      => __( 'The billing city.', 'tiermint' ),
				'billing_state'     => __( 'The billing state, region or province.', 'tiermint' ),
				'billing_postcode'  => __( 'The billing postal code.', 'tiermint' ),
			);

			foreach ( $addressFields as $param => $description ) {
				$args[ $param ] = array(
					'description'       => $description,
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => static fn ( $value ): string => self::boundText( sanitize_text_field( (string) $value ), 100 ),
				);
			}
		}

		if ( $this->fields->requireTerms() ) {
			$args['terms_accepted'] = array(
				'description'       => __( 'Whether the buyer accepted the terms and conditions ("1" for accepted).', 'tiermint' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => static fn ( $value ): string => substr( sanitize_text_field( (string) $value ), 0, 1 ),
			);
		}

		return $args;
	}

	/**
	 * Truncate a value, using multibyte support when available.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value  The value to bound.
	 * @param int    $length The maximum length in characters.
	 * @return string The bounded value.
	 */
	private static function boundText( string $value, int $length ): string {
		return function_exists( 'mb_substr' )
			? mb_substr( $value, 0, $length )
			: substr( $value, 0, $length );
	}

	/**
	 * Get quote-body parameter schema without buyer identity.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>> The parameter schema.
	 */
	private function quoteArgs(): array {
		$args = $this->intentArgs();

		unset(
			$args['gateway'],
			$args['email'],
			$args['name'],
			$args['first_name'],
			$args['last_name'],
			$args['company_name']
		);

		return $args;
	}

	/**
	 * The `args` schema for the `{uuid}` path parameter.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>> The parameter schema.
	 */
	private function uuidArgs(): array {
		return array(
			'uuid' => array(
				'description'       => __( 'The public order identifier issued at checkout.', 'tiermint' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}
}
