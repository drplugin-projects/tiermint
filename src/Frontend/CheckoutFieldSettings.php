<?php
/**
 * Reads which optional fields the checkout collects.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend;

defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Catalog\Countries;

/**
 * Reads checkout field and account settings.
 *
 * Tax extensions can request billing country and VAT through a filter.
 *
 * @since 1.0.0
 */
class CheckoutFieldSettings {

	/**
	 * The settings option holding the checkout subtree.
	 *
	 * @var string
	 */
	private const SETTINGS_OPTION = 'tiermint_settings';

	/**
	 * Whether the buyer must accept the terms before paying.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function requireTerms(): bool {
		return $this->toggle( 'require_terms' );
	}

	/**
	 * Whether to show the login panel to logged-out buyers.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function showLogin(): bool {
		return $this->toggle( 'show_login', true );
	}

	/**
	 * Whether a guest purchase creates or links an account.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function autoRegister(): bool {
		return $this->toggle( 'auto_register', false );
	}

	/**
	 * Whether buyers may check out without an account.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function guestCheckout(): bool {
		return $this->toggle( 'guest_checkout', true );
	}

	/**
	 * Whether settings require a buyer account.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function loginRequired(): bool {
		return ! $this->guestCheckout() && ! $this->autoRegister();
	}

	/**
	 * Whether checkout requires a first name.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function requireFirstName(): bool {
		return $this->toggle( 'require_first_name' );
	}

	/**
	 * Whether the buyer must enter a last name to check out. Defaults off.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function requireLastName(): bool {
		return $this->toggle( 'require_last_name' );
	}

	/**
	 * Whether checkout offers company details.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function collectCompany(): bool {
		return $this->toggle( 'collect_company', true );
	}

	/**
	 * Whether to collect a billing country: the merchant's own toggle, or a tax
	 * engine asking for it through the seam.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function collectCountry(): bool {
		return $this->toggle( 'collect_country' ) || $this->billingRequestedByExtension();
	}

	/**
	 * Whether to collect a VAT or tax id: the merchant's own toggle, or a tax
	 * engine asking for it through the seam.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function collectVat(): bool {
		return $this->toggle( 'collect_vat' ) || $this->billingRequestedByExtension();
	}

	/**
	 * Whether a tax extension requires the billing country.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function countryRequired(): bool {
		return $this->billingRequestedByExtension();
	}

	/**
	 * The billing-address collection mode: `none`, `optional`, or `required`.
	 * An extension that requires the address through the seam turns any stored
	 * mode into `required`.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function addressMode(): string {
		return $this->addressRequired() ? 'required' : $this->storedAddressMode();
	}

	/**
	 * Whether any billing address is collected.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function collectsAddress(): bool {
		return 'none' !== $this->addressMode();
	}

	/**
	 * Whether the billing address is mandatory: the merchant's own setting, or
	 * an extension requiring it through the seam.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function addressRequired(): bool {
		$stored = 'required' === $this->storedAddressMode();

		/**
		 * Filters whether checkout requires a billing address.
		 *
		 * The stored setting is the default. A listener can require the
		 * address, not relax a requirement the merchant set. A listener must
		 * not read this object's address getters, which fire this filter.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $required Whether the stored setting requires the address.
		 */
		$required = (bool) apply_filters( 'tiermint_checkout_address_required', $stored );

		return $stored || $required;
	}

	/**
	 * Whether the address is required only because an extension asked for it.
	 *
	 * The settings screen uses this to say the merchant's own choice is not
	 * what is in force.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function addressRequiredByExtension(): bool {
		return 'required' !== $this->storedAddressMode() && $this->addressRequired();
	}

	/**
	 * The stored address mode, before the seam. An unknown value degrades to `none`.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private function storedAddressMode(): string {
		$mode = (string) $this->value( 'collect_address', 'none' );

		return in_array( $mode, array( 'optional', 'required' ), true ) ? $mode : 'none';
	}

	/**
	 * Get checkout countries, limited to the core catalog.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string> Country code to name.
	 */
	public function countryOptions(): array {
		$base = Countries::all();

		/**
 * Filters the country list offered by checkout.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string> $base Country code to name.
		 */
		$filtered = apply_filters( 'tiermint_checkout_countries', $base );

		if ( ! is_array( $filtered ) ) {
			return $base;
		}

		$narrowed = array_intersect_key( $filtered, $base );

		return array() === $narrowed ? $base : $narrowed;
	}

	/**
	 * Whether an extension asks core to collect billing fields.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private function billingRequestedByExtension(): bool {
		/**
 * Filters whether core collects billing country and VAT.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $collects Whether core should collect the billing fields.
		 */
		return (bool) apply_filters( 'tiermint_core_collects_billing', false );
	}

	/**
	 * Read a checkout toggle with its default.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key      The key under the `checkout` settings subtree.
	 * @param bool   $fallback The value when the key is absent.
	 * @return bool
	 */
	private function toggle( string $key, bool $fallback = false ): bool {
		return (bool) $this->value( $key, $fallback );
	}

	/**
	 * Read a raw value from the `checkout` settings subtree, or a default.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key      The key under the `checkout` settings subtree.
	 * @param mixed  $fallback The value when the key is absent.
	 * @return mixed
	 */
	private function value( string $key, $fallback ) {
		$settings = get_option( self::SETTINGS_OPTION );

		if ( is_array( $settings ) && isset( $settings['checkout'][ $key ] ) ) {
			return $settings['checkout'][ $key ];
		}

		return $fallback;
	}
}
