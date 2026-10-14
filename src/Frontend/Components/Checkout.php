<?php
/**
 * The checkout component.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend\Components;

use DrPlugin\WP\TierMint\Frontend\AbstractComponent;
use DrPlugin\WP\TierMint\Frontend\AssetManager;
use DrPlugin\WP\TierMint\Frontend\CheckoutFieldSettings;
use DrPlugin\WP\TierMint\Frontend\Components\Support\SamplePreview;
use DrPlugin\WP\TierMint\Frontend\Components\Support\TierMetaPresenter;
use DrPlugin\WP\TierMint\Discounts\DiscountComposer;
use DrPlugin\WP\TierMint\Discounts\DiscountService;
use DrPlugin\WP\TierMint\Frontend\Components\Support\StoredDiscountPreview;
use DrPlugin\WP\TierMint\Frontend\PageResolver;
use DrPlugin\WP\TierMint\Frontend\RenderContext;
use DrPlugin\WP\TierMint\Licensing\LicenseService;
use DrPlugin\WP\TierMint\Orders\Cart;
use DrPlugin\WP\TierMint\Orders\CartService;
use DrPlugin\WP\TierMint\Orders\OrderService;
use DrPlugin\WP\TierMint\Orders\PricingMode;
use DrPlugin\WP\TierMint\Payments\GatewayRegistry;
use DrPlugin\WP\TierMint\Products\Product;
use DrPlugin\WP\TierMint\Products\ProductPrice;
use DrPlugin\WP\TierMint\Products\ProductService;
use DrPlugin\WP\TierMint\Products\ProductStatus;
use DrPlugin\WP\TierMint\Util\Money;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Server-rendered checkout with progressive payment support.
 *
 * Card data stays in the gateway. Without JavaScript, the form explains this.
 *
 * @since 1.0.0
 */
class Checkout extends AbstractComponent {

	/**
	 * Maximum extension fields. Limits untrusted form output.
	 *
	 * @var int
	 */
	private const MAX_FIELDS = 10;

	/**
	 * Allowed extension field types. Unknown types use `text`.
	 *
	 * @var string[]
	 */
	private const FIELD_TYPES = array( 'text', 'email', 'select', 'checkbox' );

	/**
	 * Cart reads for the order summary.
	 *
	 * @var CartService
	 */
	private CartService $cart;

	/**
	 * Product reads for the renewal preload.
	 *
	 * @var ProductService
	 */
	private ProductService $products;

	/**
	 * License reads for the renewal preload.
	 *
	 * @var LicenseService
	 */
	private LicenseService $licenses;

	/**
	 * Gateway configuration probe.
	 *
	 * @var GatewayRegistry
	 */
	private GatewayRegistry $gateways;

	/**
	 * Money formatting for the summary.
	 *
	 * @var Money
	 */
	private Money $money;

	/**
	 * Checkout and account settings.
	 *
	 * @var CheckoutFieldSettings
	 */
	private CheckoutFieldSettings $collection;

	/**
	 * Checkout, terms, privacy and registration pages.
	 *
	 * @var PageResolver
	 */
	private PageResolver $pages;

	/**
	 * The login-form component embedded in the checkout login panel.
	 *
	 * @var LoginForm
	 */
	private LoginForm $login;

	/**
	 * Prices the stored code for display. Null when discounts are unavailable.
	 *
	 * @var DiscountService|null
	 */
	private ?DiscountService $discounts;

	/**
	 * Combines stored and automatic display discounts.
	 *
	 * @var DiscountComposer|null
	 */
	private ?DiscountComposer $discountComposer;

	/**
	 * Wire collaborators.
	 *
	 * @param CartService                $cart       Cart reads.
	 * @param ProductService             $products   Product reads.
	 * @param LicenseService             $licenses   License reads.
	 * @param GatewayRegistry            $gateways   Payment gateway registry.
	 * @param Money                      $money      Money formatting.
	 * @param CheckoutFieldSettings|null $collection Checkout collection and account settings.
	 * @param PageResolver|null          $pages      Terms/privacy/checkout/register page resolver.
	 * @param LoginForm|null             $login      The login-form component for the login panel.
	 * @param DiscountService|null       $discounts  Prices the cart's stored discount code for display.
	 * @param DiscountComposer|null      $discountComposer Folds the automatic discount into the display.
	 */
	public function __construct(
		CartService $cart,
		ProductService $products,
		LicenseService $licenses,
		GatewayRegistry $gateways,
		Money $money,
		?CheckoutFieldSettings $collection = null,
		?PageResolver $pages = null,
		?LoginForm $login = null,
		?DiscountService $discounts = null,
		?DiscountComposer $discountComposer = null
	) {
		$this->cart             = $cart;
		$this->products         = $products;
		$this->licenses         = $licenses;
		$this->gateways         = $gateways;
		$this->money            = $money;
		$this->collection       = $collection ?? new CheckoutFieldSettings();
		$this->pages            = $pages ?? new PageResolver();
		$this->login            = $login ?? new LoginForm();
		$this->discounts        = $discounts;
		$this->discountComposer = $discountComposer;
	}

	/**
	 * Unique component identifier.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'checkout';
	}

	/**
	 * Machine-readable attribute definitions.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function schema(): array {
		return array(
			'title'             => array(
				'type'        => 'string',
				'default'     => __( 'Checkout', 'tiermint' ),
				'label'       => __( 'Title', 'tiermint' ),
				'description' => __( 'Heading above the checkout. Empty hides it.', 'tiermint' ),
				'control'     => 'text',
			),
			'button_text'       => array(
				'type'        => 'string',
				'default'     => __( 'Pay now', 'tiermint' ),
				'label'       => __( 'Button text', 'tiermint' ),
				'description' => __( 'Label of the payment button.', 'tiermint' ),
				'control'     => 'text',
			),
			'show_summary'      => array(
				'type'        => 'boolean',
				'default'     => true,
				'label'       => __( 'Show order summary', 'tiermint' ),
				'description' => __( 'Display the cart contents beside the payment form.', 'tiermint' ),
				'control'     => 'toggle',
			),
			'editable_summary'  => array(
				'type'        => 'boolean',
				'default'     => true,
				'label'       => __( 'Editable order summary', 'tiermint' ),
				'description' => __( 'Let buyers change quantities and remove lines from the order summary.', 'tiermint' ),
				'control'     => 'toggle',
			),
			'show_plan_details' => array(
				'type'        => 'boolean',
				'default'     => true,
				'label'       => __( 'Show plan details', 'tiermint' ),
				'description' => __( "Repeat each line's license length, activation limit, and billing in the order summary.", 'tiermint' ),
				'control'     => 'toggle',
			),
			'show_discount'     => array(
				'type'        => 'boolean',
				'default'     => true,
				'label'       => __( 'Show discount code field', 'tiermint' ),
				'description' => __( 'Let buyers enter a discount code before paying.', 'tiermint' ),
				'control'     => 'toggle',
			),
			'layout'            => array(
				'type'        => 'enum',
				'enum'        => array( 'two-column', 'single' ),
				'default'     => 'two-column',
				'label'       => __( 'Layout', 'tiermint' ),
				'description' => __( 'Two columns (form beside a sticky summary) where there is room, or a single stacked column.', 'tiermint' ),
				'control'     => 'select',
			),
		);
	}

	/**
	 * Checkout assets. Loads Stripe.js only when Stripe is configured.
	 *
	 * @return array<string, string[]>
	 */
	public function assets(): array {
		$scripts = array( 'tiermint-checkout' );

		if ( array_key_exists( 'stripe', $this->availableGateways() ) ) {
			array_unshift( $scripts, AssetManager::STRIPE_JS_HANDLE );
		}

		// LoginForm renders directly here, so include its style handle.
		return array(
			'styles'  => array( 'tiermint-checkout', 'tiermint-login-form' ),
			'scripts' => $scripts,
		);
	}

	/**
	 * Render the checkout for the current request state.
	 *
	 * @param array<string, mixed> $attrs Resolved attribute values.
	 * @param RenderContext        $ctx   Per-request customer state.
	 * @return string Escaped HTML.
	 */
	public function render( array $attrs, RenderContext $ctx ): string {
		$layout = 'single' === ( $attrs['layout'] ?? 'two-column' ) ? 'single' : 'two-column';

		$orderUuid = $this->returnedOrderUuid();

		if ( null !== $orderUuid ) {
			return $this->renderTemplate(
				array(
					'state'      => 'confirmation',
					'title'      => (string) $attrs['title'],
					'order_uuid' => $orderUuid,
					'layout'     => $layout,
				)
			);
		}

		$cart = $this->cart->getCart();

		// Preview an in-memory cart. Keep the real gateway state.
		$isSample = $cart->isEmpty() && $ctx->isEditorPreview();
		if ( $isSample ) {
			$cart = SamplePreview::cart();
		}

		$html = $this->renderCart( $attrs, $ctx, $cart, $layout );

		return $isSample ? $html . $this->sampleCaption() : $html;
	}

	/**
	 * Render a real or preview cart.
	 *
	 * @param array<string, mixed> $attrs  Resolved attribute values.
	 * @param RenderContext        $ctx    Per-request customer state.
	 * @param Cart                 $cart   The cart to render.
	 * @param string               $layout The resolved layout.
	 * @return string Escaped HTML.
	 */
	private function renderCart( array $attrs, RenderContext $ctx, Cart $cart, string $layout ): string {
		$renewal = $this->renewalBanner( $cart );

		$data = array(
			'title'              => (string) $attrs['title'],
			'button_text'        => (string) $attrs['button_text'],
			'show_summary'       => (bool) $attrs['show_summary'],
			'editable_summary'   => (bool) ( $attrs['editable_summary'] ?? true ),
			'show_plan_details'  => (bool) ( $attrs['show_plan_details'] ?? true ),
			'show_discount'      => (bool) ( $attrs['show_discount'] ?? true ),
			'layout'             => $layout,
			'renewal'            => $renewal,
			'cart_error'         => $this->cartErrorMessage(),
			'action_url'         => remove_query_arg( 'tiermint_cart_error' ),
			// Gross mode relabels the summary tax row; the row itself is unchanged.
			'prices_include_tax' => PricingMode::includesTax(),
		);

		if ( $cart->isEmpty() ) {
			$data['state'] = 'empty';
			return $this->renderTemplate( $data );
		}

		$loginRequired = $this->collection->loginRequired() && ! is_user_logged_in();

		// A required login replaces the payment form, so skip this probe.
		if ( ! $loginRequired ) {
			$gateways = $this->availableGateways();

			if ( array() === $gateways ) {
				$data['state']      = 'unavailable';
				$data['admin_hint'] = current_user_can( 'manage_options' );
				return $this->renderTemplate( $data );
			}
		}

		$lines = $this->summaryLines( $cart, (bool) ( $attrs['show_plan_details'] ?? true ), $cartNotices );
		$total = array_sum( array_column( $lines, 'subtotal_minor' ) );

		// Price only for display. Intent creation remains authoritative.
		$discountView = $this->discountView( $cart, $lines );

		$data['cart_notices'] = $cartNotices;
		$data['state']        = 'form';
		$data['lines']        = $lines;
		$data['discount']     = $discountView;
		// This uses the same combined discount as the order layer.
		$data['total']       = $this->money->format( $total - $discountView['total_minor'] );
		$data['total_minor'] = $total - $discountView['total_minor'];
		// An extension fills the initially hidden tax row from a live quote.
		$data['tax']            = $this->money->format( 0 );
		$data['tax_minor']      = 0;
		$data['login_required'] = $loginRequired;

		// Keep the summary visible while the required login form replaces payment.
		if ( $loginRequired ) {
			$data['login_html'] = $this->loginPanelHtml( $ctx, true );

			return $this->renderTemplate( $data );
		}

		$emailReadonly = $this->emailReadOnly();

		$customer = $ctx->customer();

		$data['gateways']        = $gateways;
		$data['default_gateway'] = (string) array_key_first( $gateways );
		$data['fields']          = $this->extraFields( $ctx );
		$data['billing']         = $this->billingData();
		$data['email']           = $this->prefillEmail( $ctx, $emailReadonly );
		// Do not split legacy combined names into these separate fields.
		$data['first_name']         = null !== $customer ? (string) $customer->firstName() : '';
		$data['last_name']          = null !== $customer ? (string) $customer->lastName() : '';
		$data['require_first_name'] = $this->collection->requireFirstName();
		$data['require_last_name']  = $this->collection->requireLastName();
		$data['email_readonly']     = $emailReadonly;
		$data['account_notice']     = $this->accountNotice();

		if ( $this->showLoginPanel() ) {
			$data['login_html'] = $this->loginPanelHtml( $ctx, false );
		}

		return $this->renderTemplate( $data );
	}

	/**
	 * Check if the logged-in buyer's email is fixed.
	 *
	 * @return bool
	 */
	private function emailReadOnly(): bool {
		return ! $this->collection->guestCheckout() && is_user_logged_in();
	}

	/**
	 * Return the account-creation notice when auto-registration applies.
	 *
	 * @return string|null
	 */
	private function accountNotice(): ?string {
		if ( $this->collection->guestCheckout() || ! $this->collection->autoRegister() || is_user_logged_in() ) {
			return null;
		}

		return __( 'We will create an account for you when your purchase completes, so your orders and downloads are saved to it. You will get an email with a link to set a password.', 'tiermint' );
	}

	/**
	 * Get the best email value for the form.
	 *
	 * @param RenderContext $ctx        Per-request customer state.
	 * @param bool          $isReadonly Whether the field is read-only.
	 * @return string
	 */
	private function prefillEmail( RenderContext $ctx, bool $isReadonly ): string {
		if ( $isReadonly ) {
			return $this->wpUserEmail();
		}

		return null !== $ctx->customer() ? $ctx->customer()->email() : $this->wpUserEmail();
	}

	/**
	 * Check if the optional login panel should show.
	 *
	 * @return bool
	 */
	private function showLoginPanel(): bool {
		if ( is_user_logged_in() || $this->collection->loginRequired() ) {
			return false;
		}

		return $this->collection->showLogin()
			|| ( ! $this->collection->guestCheckout() && $this->collection->autoRegister() );
	}

	/**
	 * Render the login panel with a checkout return URL.
	 *
	 * @param RenderContext $ctx             Per-request customer state.
	 * @param bool          $withRegisterPage Whether to feed the registration page URL.
	 * @return string The login form markup.
	 */
	private function loginPanelHtml( RenderContext $ctx, bool $withRegisterPage ): string {
		$attrs = array(
			'redirect'      => $this->pages->url( PageResolver::CHECKOUT ) ?? '',
			'show_register' => true,
			'register_url'  => $withRegisterPage ? ( $this->pages->url( PageResolver::REGISTER ) ?? '' ) : '',
		);

		return $this->login->render( $attrs, $ctx );
	}

	/**
	 * Build the native billing-field view data.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function billingData(): array {
		$collectCountry = $this->collection->collectCountry();
		$collectVat     = $this->collection->collectVat();
		$collectCompany = $this->collection->collectCompany();
		$requireTerms   = $this->collection->requireTerms();

		$vat = $this->refineBillingField(
			array(
				'show'        => $collectVat,
				'value'       => '',
				'label'       => __( 'VAT, GST, or tax ID (optional)', 'tiermint' ),
				'help'        => __( 'Buying as a business? Enter your VAT, GST, or tax registration number.', 'tiermint' ),
				'placeholder' => __( 'For example DE123456789', 'tiermint' ),
			),
			'vat_number'
		);

		// Open the business fields when an extension prefills a tax ID.
		$revealOpen = $collectCompany && ! empty( $vat['show'] ) && '' !== (string) ( $vat['value'] ?? '' );

		return array(
			'country' => $this->refineBillingField(
				array(
					'show'        => $collectCountry,
					'required'    => $this->collection->countryRequired(),
					'options'     => $collectCountry ? $this->collection->countryOptions() : array(),
					'value'       => '',
					'label'       => __( 'Country', 'tiermint' ),
					'help'        => '',
					'placeholder' => __( 'Select your country', 'tiermint' ),
				),
				'billing_country'
			),
			'company' => array(
				'show'        => $collectCompany,
				'value'       => '',
				'label'       => __( 'Company name', 'tiermint' ),
				'reveal_open' => $revealOpen,
			),
			'vat'     => $vat,
			'address' => array(
				'mode' => $this->collection->addressMode(),
			),
			// Resolve page URLs only for a visible terms checkbox.
			'terms'   => array(
				'show'        => $requireTerms,
				'terms_url'   => $requireTerms ? $this->pages->url( PageResolver::TERMS ) : null,
				'privacy_url' => $requireTerms ? $this->pages->url( PageResolver::PRIVACY ) : null,
			),
		);
	}

	/**
	 * Let extensions refine visible billing-field copy.
	 *
	 * @param array<string, mixed> $field The field view data.
	 * @param string               $key   The field key (`billing_country`, `vat_number`).
	 * @return array<string, mixed> The refined field view data.
	 */
	private function refineBillingField( array $field, string $key ): array {
		if ( empty( $field['show'] ) ) {
			return $field;
		}

		/**
		 * Filters one native checkout billing field's presentation.
		 *
		 * Extensions can change displayed field copy and the prefilled value.
		 * Core keeps `show`, `required` and `options`.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $field The field view data.
		 * @param string               $key   The field key (`billing_country` or `vat_number`).
		 */
		$refined = apply_filters( 'tiermint_checkout_billing_field', $field, $key );

		return is_array( $refined ) ? array_merge( $field, $refined ) : $field;
	}

	/**
	 * Get the valid payment-return UUID, if present.
	 *
	 * @return string|null
	 */
	private function returnedOrderUuid(): ?string {
		if ( ! isset( $_GET['tiermint_order'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of the payment-return marker; no state change.
			return null;
		}

		$raw = sanitize_text_field( wp_unslash( $_GET['tiermint_order'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of the payment-return marker; no state change.

		if ( 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $raw ) ) {
			return null;
		}

		return $raw;
	}

	/**
	 * Build the renewal banner for a valid, purchasable license.
	 *
	 * @param Cart $cart The current cart.
	 * @return array<string, mixed>|null Banner data, or null.
	 */
	private function renewalBanner( Cart $cart ): ?array {
		if ( ! isset( $_GET['tiermint_renew'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only renewal preload from a signed-nothing public link; adding to the cart still requires the nonce-verified POST.
			return null;
		}

		$key = sanitize_text_field( wp_unslash( $_GET['tiermint_renew'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only renewal preload; see above.

		if ( 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9\-]{14,62}[A-Za-z0-9]$/', $key ) ) {
			return null;
		}

		try {
			$license = $this->licenses->findByKey( $key );
		} catch ( Throwable $e ) {
			// A failed license lookup must not break checkout.
			return null;
		}

		if ( null === $license ) {
			return null;
		}

		$product = $this->products->get( $license->productId() );

		if ( null === $product || ProductStatus::Published !== $product->status() ) {
			return null;
		}

		$priceId = $this->renewalPriceId( $product, $license->priceId() );

		if ( null === $priceId ) {
			return null;
		}

		return array(
			'product_name' => $product->name(),
			'product_id'   => (int) $product->id(),
			'price_id'     => $priceId,
			'in_cart'      => $cart->hasLine( (int) $product->id(), $priceId ),
		);
	}

	/**
	 * Get the original active tier, or the default tier.
	 *
	 * @param Product  $product        The licensed product.
	 * @param int|null $grantedPriceId The tier the license was minted from.
	 * @return int|null Price id, or null when nothing is purchasable.
	 */
	private function renewalPriceId( Product $product, ?int $grantedPriceId ): ?int {
		// Renewal offers use the same recurring-tier gate as checkout.
		$recurringSupported = ProductService::recurringTiersSupported();

		if ( null !== $grantedPriceId ) {
			foreach ( $product->prices() as $price ) {
				if ( $price->id() === $grantedPriceId && $price->isPurchasable( $recurringSupported ) ) {
					return $price->id();
				}
			}
		}

		$fallback = $product->defaultPrice( $recurringSupported );

		return null !== $fallback ? $fallback->id() : null;
	}

	/**
	 * The translated message for a whitelisted cart-error redirect marker
	 * (the renewal add form's failure path lands back here), or null.
	 *
	 * @return string|null
	 */
	private function cartErrorMessage(): ?string {
		if ( ! isset( $_GET['tiermint_cart_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of a redirect marker; no state change.
			return null;
		}

		$code = sanitize_key( wp_unslash( $_GET['tiermint_cart_error'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of a redirect marker; no state change.

		switch ( $code ) {
			case 'invalid':
				return __( 'That item could not be added to the cart.', 'tiermint' );
			case 'failed':
				return __( 'Something went wrong updating your cart. Please try again.', 'tiermint' );
			default:
				return null;
		}
	}

	/**
	 * Get configured checkout gateways as ID-to-label pairs.
	 *
	 * @return array<string, string> Gateway id to translated label.
	 */
	private function availableGateways(): array {
		$available = array();

		foreach ( $this->gateways->all() as $id => $gateway ) {
			try {
				if ( $gateway->isConfigured() ) {
					$available[ (string) $gateway->id() ] = (string) $gateway->label();
				}
			} catch ( Throwable $e ) {
				continue;
			}
		}

		/**
		 * Filters configured checkout gateways as ID-to-label pairs.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string> $available The configured gateways, id to label.
		 */
		$available = apply_filters( 'tiermint_checkout_gateways', $available );

		return is_array( $available ) ? $available : array();
	}

	/**
	 * Validate extension fields for the checkout template.
	 *
	 * @param RenderContext $ctx Per-request customer state.
	 * @return array<int, array<string, mixed>> Ready-to-render field definitions.
	 */
	private function extraFields( RenderContext $ctx ): array {
		/**
		 * Fields need an id and label. Core accepts text, email, select and checkbox.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, array<string, mixed>> $fields Field definitions; empty in the free core.
		 * @param RenderContext                    $ctx    Per-request customer state (customer, if known).
		 */
		$fields = apply_filters( 'tiermint_checkout_fields', array(), $ctx );

		if ( ! is_array( $fields ) ) {
			return array();
		}

		$clean = array();

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$id = isset( $field['id'] ) && is_string( $field['id'] ) ? sanitize_key( $field['id'] ) : '';

			if ( '' === $id || isset( $clean[ $id ] ) ) {
				continue;
			}

			$label = isset( $field['label'] ) && is_string( $field['label'] ) ? trim( $field['label'] ) : '';

			if ( '' === $label ) {
				continue;
			}

			$type = isset( $field['type'] ) && is_string( $field['type'] ) ? strtolower( $field['type'] ) : 'text';

			if ( ! in_array( $type, self::FIELD_TYPES, true ) ) {
				$type = 'text';
			}

			$clean[ $id ] = array(
				'id'           => $id,
				'label'        => $label,
				'type'         => $type,
				'required'     => ! empty( $field['required'] ),
				'help'         => $this->fieldString( $field, 'help' ),
				'autocomplete' => $this->fieldString( $field, 'autocomplete' ),
				'options'      => 'select' === $type ? $this->fieldOptions( $field['options'] ?? array() ) : array(),
				'value'        => $this->fieldString( $field, 'value' ),
				'placeholder'  => $this->fieldString( $field, 'placeholder' ),
			);

			if ( count( $clean ) >= self::MAX_FIELDS ) {
				break;
			}
		}

		return array_values( $clean );
	}

	/**
	 * Read a scalar field member as a string.
	 *
	 * @param array<string, mixed> $field The declaration.
	 * @param string               $key   Member to read.
	 * @return string
	 */
	private function fieldString( array $field, string $key ): string {
		return isset( $field[ $key ] ) && is_scalar( $field[ $key ] ) ? (string) $field[ $key ] : '';
	}

	/**
	 * Get the scalar options of a select field.
	 *
	 * @param mixed $options The declared options.
	 * @return array<string, string> Option value to label.
	 */
	private function fieldOptions( $options ): array {
		if ( ! is_array( $options ) ) {
			return array();
		}

		$clean = array();

		foreach ( $options as $value => $label ) {
			if ( ! is_scalar( $label ) || ! is_scalar( $value ) ) {
				continue;
			}

			$clean[ sanitize_text_field( (string) $value ) ] = sanitize_text_field( (string) $label );
		}

		return $clean;
	}

	/**
	 * Build summary rows from current product data.
	 *
	 * Missing tiers keep their cart snapshot. Valid tiers clamp stale quantities.
	 *
	 * @param Cart                    $cart            The current cart.
	 * @param bool                    $showPlanDetails Whether to attach each line's tier meta lines.
	 * @param array<int, string>|null $notices         Out parameter: one advisory per line
	 *                                                 whose quantity the tier rule adjusted.
	 * @return array<int, array<string, mixed>>
	 */
	private function summaryLines( Cart $cart, bool $showPlanDetails, ?array &$notices = null ): array {
		$notices = array();
		$lines   = array();

		$flags = TierMetaPresenter::flagsFrom( array() );

		foreach ( $cart->lines() as $line ) {
			$price     = $this->livePrice( $line->productId(), $line->priceId() );
			$unit      = null !== $price ? $price->amount() : $line->unitAmount();
			$requested = $line->quantity();
			$quantity  = null !== $price ? $price->clampQuantity( $requested ) : $requested;

			if ( null !== $price && $quantity !== $requested ) {
				$notices[] = OrderService::quantityAdjustedNotice( $line->productName(), $quantity );
			}

			$subtotal = $unit * $quantity;

			// The presenter needs a live tier.
			$meta = ( $showPlanDetails && null !== $price ) ? TierMetaPresenter::lines( $price, $flags ) : array();

			$lines[] = array(
				'product_id'     => $line->productId(),
				'price_id'       => $line->priceId(),
				'name'           => $line->productName(),
				'price_name'     => (string) $line->priceName(),
				'quantity'       => $quantity,
				// Use live bounds so the stepper matches checkout validation.
				'min'            => null !== $price ? $price->minQuantity() : 1,
				'max'            => null !== $price ? $price->maxQuantity() : null,
				'fixed_single'   => null !== $price ? $price->isFixedSingle() : false,
				'unit'           => $this->money->format( $unit ),
				'subtotal'       => $this->money->format( $subtotal ),
				'subtotal_minor' => $subtotal,
				'meta'           => $meta,
			);
		}

		return $lines;
	}

	/**
	 * Price the stored code against summary lines for display.
	 *
	 * @param Cart                             $cart  The current cart.
	 * @param array<int, array<string, mixed>> $lines The priced summary lines.
	 * @return array{state: string, code: string, label: string, amount_minor: int, amount: string, error: string}
	 */
	private function discountView( Cart $cart, array $lines ): array {
		$items = array();
		foreach ( $lines as $line ) {
			$items[] = array(
				'product_id' => (int) ( $line['product_id'] ?? 0 ),
				'subtotal'   => (int) ( $line['subtotal_minor'] ?? 0 ),
			);
		}

		return StoredDiscountPreview::view( $this->discounts, $cart->discountCode(), $items, $this->money, $this->discountComposer );
	}

	/**
	 * Get a cart line's active product tier.
	 *
	 * @param int $productId The line's product id.
	 * @param int $priceId   The line's price id.
	 * @return ProductPrice|null The active tier, or null.
	 */
	private function livePrice( int $productId, int $priceId ): ?ProductPrice {
		$product = $this->products->get( $productId );

		if ( null === $product || ProductStatus::Published !== $product->status() ) {
			return null;
		}

		foreach ( $product->prices() as $price ) {
			if ( $price->id() === $priceId && $price->isActive() ) {
				return $price;
			}
		}

		return null;
	}

	/**
	 * Get the logged-in user's email for form prefill.
	 *
	 * @return string
	 */
	private function wpUserEmail(): string {
		if ( ! is_user_logged_in() ) {
			return '';
		}

		return (string) wp_get_current_user()->user_email;
	}
}
