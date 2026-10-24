<?php
/**
 * The settings field schema served to the admin SPA.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Admin;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Frontend\CheckoutFieldSettings;

/**
 * Defines settings fields for the admin SPA.
 *
 * Extensions add fields through a filter. Secret storage stays server-side.
 *
 * @since 1.0.0
 */
final class SettingsSchema {

	/**
	 * Which checkout fields are in force, for copy that depends on it.
	 *
	 * @var CheckoutFieldSettings
	 */
	private CheckoutFieldSettings $checkout;

	/**
	 * Wire the schema to the checkout field settings.
	 *
	 * @since 1.0.0
	 *
	 * @param CheckoutFieldSettings|null $checkout The checkout field settings, or null for the default.
	 */
	public function __construct( ?CheckoutFieldSettings $checkout = null ) {
		$this->checkout = $checkout ?? new CheckoutFieldSettings();
	}

	/**
	 * Get valid settings sections in display order.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array<string, string>> Section rows.
	 */
	public function sections(): array {
		$core = array(
			array(
				'id'    => 'general',
				'title' => __( 'General', 'tiermint' ),
			),
			array(
				'id'    => 'appearance',
				'title' => __( 'Appearance', 'tiermint' ),
			),
			array(
				'id'    => 'emails',
				'title' => __( 'Emails', 'tiermint' ),
			),
			array(
				'id'    => 'payments',
				'title' => __( 'Payments', 'tiermint' ),
			),
			array(
				'id'    => 'discounts',
				'title' => __( 'Discounts', 'tiermint' ),
			),
			array(
				'id'    => 'webhooks',
				'title' => __( 'Webhooks', 'tiermint' ),
			),
			array(
				'id'    => 'integrations',
				'title' => __( 'Integrations', 'tiermint' ),
			),
		);

		/**
		 * Filters the settings sections served to the admin SPA.
		 *
		 * Extensions add sections here. Invalid and duplicate ids are dropped.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, array<string, string>> $sections Section rows.
		 */
		$sections = apply_filters( 'tiermint_settings_sections', $core );

		$byId = array();
		foreach ( (array) $sections as $section ) {
			if ( ! is_array( $section ) || ! isset( $section['id'], $section['title'] ) ) {
				continue;
			}
			if ( ! is_string( $section['id'] ) || '' === $section['id'] || ! is_string( $section['title'] ) ) {
				continue;
			}
			if ( isset( $byId[ $section['id'] ] ) ) {
				continue;
			}
			$byId[ $section['id'] ] = array(
				'id'    => $section['id'],
				'title' => $section['title'],
			);
		}

		return array_values( $byId );
	}

	/**
	 * Get valid settings fields after extension filters.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>> Fields keyed by id.
	 */
	public function fields(): array {
		/**
		 * Filters the settings field definitions served to the admin SPA.
		 *
		 * Extensions add field definitions here.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, array<string, mixed>> $fields Field definitions.
		 */
		$fields = apply_filters( 'tiermint_settings_fields', $this->coreFields() );

		$byId = array();
		foreach ( (array) $fields as $field ) {
			if ( ! is_array( $field ) || ! isset( $field['id'], $field['section'], $field['type'], $field['label'] ) ) {
				continue;
			}
			if ( ! isset( $field['storage'] ) ) {
				$field['storage'] = 'option';
			}
			if ( 'option' === $field['storage'] && empty( $field['path'] ) ) {
				continue;
			}
			$byId[ (string) $field['id'] ] = $field;
		}

		return $byId;
	}

	/**
	 * Get one field definition.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id Field id.
	 * @return array<string, mixed>|null Field definition, or null.
	 */
	public function field( string $id ): ?array {
		return $this->fields()[ $id ] ?? null;
	}

	/**
	 * Get fields the SPA can render.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array<string, mixed>> Client field rows.
	 */
	public function forClient(): array {
		$client = array();

		foreach ( $this->fields() as $field ) {
			$row = array(
				'id'          => $field['id'],
				'section'     => $field['section'],
				'type'        => $field['type'],
				'label'       => $field['label'],
				'description' => $field['description'] ?? '',
				'secret'      => 'secret' === $field['type'],
			);
			if ( isset( $field['options'] ) ) {
				$row['options'] = $field['options'];
			}
			// The gateway key selects a Payments sub-tab, and the label names it.
			if ( isset( $field['gateway'] ) ) {
				$row['gateway'] = $field['gateway'];
			}
			if ( isset( $field['gateway_label'] ) ) {
				$row['gateway_label'] = $field['gateway_label'];
			}
			// The group key selects a shared fieldset.
			if ( isset( $field['group'] ) ) {
				$row['group'] = $field['group'];
			}
			// Extensions persist render-only fields through their own endpoints.
			if ( 'none' === $field['storage'] ) {
				$row['external'] = true;
			}
			$client[] = $row;
		}

		return $client;
	}

	/**
	 * Describe the billing-address select, naming the override when an
	 * extension requires the address regardless of the choice here.
	 *
	 * @since 1.0.0
	 *
	 * @return string The field description.
	 */
	private function addressDescription(): string {
		if ( $this->checkout->addressRequiredByExtension() ) {
			return __( 'Whether to ask the buyer for a full billing address at checkout. The address is currently required by another setting, so this choice takes effect only when that setting is turned off.', 'tiermint' );
		}

		return __( 'Whether to ask the buyer for a full billing address at checkout.', 'tiermint' );
	}

	/**
	 * Describe the billing-country toggle, saying so when an extension collects
	 * the country whatever the toggle says.
	 *
	 * @since 1.0.0
	 *
	 * @return string The field description.
	 */
	private function countryDescription(): string {
		if ( $this->checkout->collectedByExtension( 'billing_country' ) ) {
			return __( 'Ask the buyer for their country at checkout. The country is currently collected for another setting, so this choice takes effect only when that setting is turned off.', 'tiermint' );
		}

		return __( 'Ask the buyer for their country at checkout.', 'tiermint' );
	}

	/**
	 * Describe the VAT toggle, saying so when an extension collects the number
	 * whatever the toggle says.
	 *
	 * @since 1.0.0
	 *
	 * @return string The field description.
	 */
	private function vatDescription(): string {
		if ( $this->checkout->collectedByExtension( 'vat_number' ) ) {
			return __( 'Ask the buyer for a VAT, GST, or tax registration number at checkout. The number is currently collected for another setting, so this choice takes effect only when that setting is turned off.', 'tiermint' );
		}

		return __( 'Ask the buyer for a VAT, GST, or tax registration number at checkout.', 'tiermint' );
	}

	/**
	 * Get core field definitions.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array<string, mixed>> Core fields.
	 */
	private function coreFields(): array {
		return array(
			array(
				'id'          => 'currency',
				'section'     => 'general',
				'group'       => 'store',
				'type'        => 'currency',
				'label'       => __( 'Store currency', 'tiermint' ),
				'description' => __( 'The currency used for all prices. Pick it from the list.', 'tiermint' ),
				'path'        => 'currency',
				'default'     => 'USD',
			),
			array(
				'id'          => 'store_page',
				'section'     => 'general',
				'group'       => 'pages',
				'type'        => 'page',
				'label'       => __( 'Store page', 'tiermint' ),
				'description' => __( 'The page listing your products. Set automatically when the store is provisioned.', 'tiermint' ),
				'path'        => 'pages.store',
				'default'     => 0,
			),
			array(
				'id'          => 'product_page',
				'section'     => 'general',
				'group'       => 'pages',
				'type'        => 'page',
				'label'       => __( 'Product details page', 'tiermint' ),
				'description' => __( 'The page a single product opens on from the store grid.', 'tiermint' ),
				'path'        => 'pages.product',
				'default'     => 0,
			),
			array(
				'id'          => 'checkout_page',
				'section'     => 'general',
				'group'       => 'pages',
				'type'        => 'page',
				'label'       => __( 'Checkout page', 'tiermint' ),
				'description' => __( 'The page with the checkout block or shortcode. Cart links and links to buy a new license open it.', 'tiermint' ),
				'path'        => 'pages.checkout',
				'default'     => 0,
			),
			array(
				'id'          => 'account_page',
				'section'     => 'general',
				'group'       => 'pages',
				'type'        => 'page',
				'label'       => __( 'Account page', 'tiermint' ),
				'description' => __( 'Where customers manage their purchases, downloads, and license keys.', 'tiermint' ),
				'path'        => 'pages.account',
				'default'     => 0,
			),
			array(
				'id'          => 'login_page',
				'section'     => 'general',
				'group'       => 'pages',
				'type'        => 'page',
				'label'       => __( 'Sign-in page', 'tiermint' ),
				'description' => __( 'The page carrying the sign-in form. Leave unset to use the WordPress login screen.', 'tiermint' ),
				'path'        => 'pages.login',
				'default'     => 0,
			),
			array(
				'id'          => 'register_page',
				'section'     => 'general',
				'group'       => 'pages',
				'type'        => 'page',
				'label'       => __( 'Registration page', 'tiermint' ),
				'description' => __( 'The page carrying the account registration form.', 'tiermint' ),
				'path'        => 'pages.register',
				'default'     => 0,
			),
			array(
				'id'          => 'password_reset_page',
				'section'     => 'general',
				'group'       => 'pages',
				'type'        => 'page',
				'label'       => __( 'Password reset page', 'tiermint' ),
				'description' => __( 'The page carrying the lost-password and set-new-password form. While it is published, TierMint\'s own forms and emails link to it, and WordPress password emails do too when wp-login is replaced. Unset it to use the WordPress screens. wp-login.php?tiermint_wp_login=1 always reaches the WordPress screen.', 'tiermint' ),
				'path'        => 'pages.password_reset',
				'default'     => 0,
			),
			array(
				'id'          => 'terms_page',
				'section'     => 'general',
				'group'       => 'pages',
				'type'        => 'page',
				'label'       => __( 'Terms page', 'tiermint' ),
				'description' => __( 'Your terms and conditions page, linked from checkout.', 'tiermint' ),
				'path'        => 'pages.terms',
				'default'     => 0,
			),
			array(
				'id'          => 'privacy_page',
				'section'     => 'general',
				'group'       => 'pages',
				'type'        => 'page',
				'label'       => __( 'Privacy policy page', 'tiermint' ),
				'description' => __( 'Your privacy policy page. Falls back to the WordPress privacy policy page when left unset.', 'tiermint' ),
				'path'        => 'pages.privacy',
				'default'     => 0,
			),
			array(
				'id'          => 'hide_admin_bar',
				'section'     => 'general',
				'group'       => 'frontend',
				'type'        => 'toggle',
				'label'       => __( 'Hide the admin toolbar for customers', 'tiermint' ),
				'description' => __( 'Hide the WordPress toolbar on the front end for logged-in users who cannot edit posts. Administrators and editors always keep it.', 'tiermint' ),
				'path'        => 'frontend.hide_admin_bar',
				'default'     => false,
			),
			array(
				'id'          => 'replace_wp_login',
				'section'     => 'general',
				'group'       => 'frontend',
				'type'        => 'toggle',
				'label'       => __( 'Use the sign-in page instead of wp-login', 'tiermint' ),
				'description' => __( 'Redirect front-end sign-in and password-reset requests, and the links in WordPress password emails, to your pages. Requires published pages; logout, confirmations and admin logins keep working, and wp-login.php?tiermint_wp_login=1 always shows the WordPress screen.', 'tiermint' ),
				'path'        => 'frontend.replace_wp_login',
				'default'     => false,
			),
			array(
				'id'          => 'grid_buy_buttons',
				'section'     => 'general',
				'group'       => 'frontend',
				'type'        => 'toggle',
				'label'       => __( 'Show buy buttons in the product grid', 'tiermint' ),
				'description' => __( 'The store-wide default for add-to-cart buttons on product grid cards. A block or shortcode can still override it.', 'tiermint' ),
				'path'        => 'storefront.grid_buy_buttons',
				'default'     => true,
			),
			array(
				'id'          => 'cart_redirect',
				'section'     => 'general',
				'group'       => 'frontend',
				'type'        => 'select',
				'label'       => __( 'After adding to the cart', 'tiermint' ),
				'description' => __( 'What happens after a shopper adds a product to the cart.', 'tiermint' ),
				'options'     => array(
					array(
						'value' => 'stay',
						'label' => __( 'Stay on the page and show a confirmation', 'tiermint' ),
					),
					array(
						'value' => 'checkout',
						'label' => __( 'Go straight to the checkout page', 'tiermint' ),
					),
				),
				'path'        => 'storefront.cart_redirect',
				'default'     => 'stay',
			),
			array(
				'id'          => 'storefront_product_title_source',
				'section'     => 'general',
				'group'       => 'frontend',
				'type'        => 'select',
				'label'       => __( 'Product page heading', 'tiermint' ),
				'description' => __( 'Where the product name shows as the heading on the product details page. Show it in the block keeps the theme page title hidden there so the block is the only heading. Use the theme page title puts the product name in the theme title instead (so a Post Title block and breadcrumbs show it), and the block hides its own; pick this only if your theme renders the page title.', 'tiermint' ),
				'options'     => array(
					array(
						'value' => 'block',
						'label' => __( 'Show the product name in the block', 'tiermint' ),
					),
					array(
						'value' => 'theme',
						'label' => __( 'Use the theme page title for the product name', 'tiermint' ),
					),
				),
				'path'        => 'storefront.product_title_source',
				'default'     => 'block',
			),
			array(
				'id'          => 'checkout_require_terms',
				'section'     => 'general',
				'group'       => 'checkout',
				'type'        => 'toggle',
				'label'       => __( 'Require terms acceptance at checkout', 'tiermint' ),
				'description' => __( 'Show a checkbox the buyer must tick to accept your terms and privacy policy before paying.', 'tiermint' ),
				'path'        => 'checkout.require_terms',
				'default'     => false,
			),
			array(
				'id'          => 'checkout_collect_country',
				'section'     => 'general',
				'group'       => 'checkout',
				'type'        => 'toggle',
				'label'       => __( 'Collect a billing country', 'tiermint' ),
				'description' => $this->countryDescription(),
				'path'        => 'checkout.collect_country',
				'default'     => false,
			),
			array(
				'id'          => 'checkout_collect_vat',
				'section'     => 'general',
				'group'       => 'checkout',
				'type'        => 'toggle',
				'label'       => __( 'Collect a VAT or tax ID', 'tiermint' ),
				'description' => $this->vatDescription(),
				'path'        => 'checkout.collect_vat',
				'default'     => false,
			),
			array(
				'id'          => 'checkout_collect_address',
				'section'     => 'general',
				'group'       => 'checkout',
				'type'        => 'select',
				'label'       => __( 'Collect a billing address', 'tiermint' ),
				'description' => $this->addressDescription(),
				'options'     => array(
					array(
						'value' => 'none',
						'label' => __( 'Do not collect an address', 'tiermint' ),
					),
					array(
						'value' => 'optional',
						'label' => __( 'Collect an optional address', 'tiermint' ),
					),
					array(
						'value' => 'required',
						'label' => __( 'Require an address', 'tiermint' ),
					),
				),
				'path'        => 'checkout.collect_address',
				'default'     => 'none',
			),
			array(
				'id'          => 'checkout_show_login',
				'section'     => 'general',
				'group'       => 'checkout',
				'type'        => 'toggle',
				'label'       => __( 'Show a login panel at checkout', 'tiermint' ),
				'description' => __( 'Let existing customers log in from the checkout page before they pay.', 'tiermint' ),
				'path'        => 'checkout.show_login',
				'default'     => true,
			),
			array(
				'id'          => 'checkout_auto_register',
				'section'     => 'general',
				'group'       => 'checkout',
				'type'        => 'toggle',
				'label'       => __( 'Create an account for guests', 'tiermint' ),
				'description' => __( 'When a guest completes a purchase, create a WordPress account for them (or link one that already uses their email) so their orders and licenses appear in the account area. They set a password from a link emailed to them.', 'tiermint' ),
				'path'        => 'checkout.auto_register',
				'default'     => false,
			),
			array(
				'id'          => 'checkout_guest_checkout',
				'section'     => 'general',
				'group'       => 'checkout',
				'type'        => 'toggle',
				'label'       => __( 'Allow guest checkout', 'tiermint' ),
				'description' => __( 'Let buyers check out without an account. Turn this off to require an account, through login, auto-registration, or a registration page. Without a registration page or auto-registration, only existing users can buy.', 'tiermint' ),
				'path'        => 'checkout.guest_checkout',
				'default'     => true,
			),
			array(
				'id'          => 'checkout_require_first_name',
				'section'     => 'general',
				'group'       => 'checkout',
				'type'        => 'toggle',
				'label'       => __( 'Require a first name at checkout', 'tiermint' ),
				'description' => __( 'Make the first name field mandatory. Left off, it is offered but optional.', 'tiermint' ),
				'path'        => 'checkout.require_first_name',
				'default'     => false,
			),
			array(
				'id'          => 'checkout_require_last_name',
				'section'     => 'general',
				'group'       => 'checkout',
				'type'        => 'toggle',
				'label'       => __( 'Require a last name at checkout', 'tiermint' ),
				'description' => __( 'Make the last name field mandatory. Left off, it is offered but optional.', 'tiermint' ),
				'path'        => 'checkout.require_last_name',
				'default'     => false,
			),
			array(
				'id'          => 'checkout_collect_company',
				'section'     => 'general',
				'group'       => 'checkout',
				'type'        => 'toggle',
				'label'       => __( 'Offer a company purchase option', 'tiermint' ),
				'description' => __( 'Show an "I am buying as a company" checkbox that reveals a company name field and the tax ID field. Turn off to always show the tax ID field on its own instead.', 'tiermint' ),
				'path'        => 'checkout.collect_company',
				'default'     => true,
			),
			array(
				'id'          => 'delete_data_on_uninstall',
				'section'     => 'general',
				'group'       => 'advanced',
				'type'        => 'toggle',
				'label'       => __( 'Delete all data on uninstall', 'tiermint' ),
				'description' => __( 'When enabled, deleting the plugin removes every TierMint table, option, capability, and stored package file. Leave off to keep customer and license data.', 'tiermint' ),
				'path'        => 'delete_data_on_uninstall',
				'default'     => false,
			),
			array(
				'id'          => 'discount_code_combination',
				'section'     => 'discounts',
				'type'        => 'select',
				'label'       => __( 'Codes and automatic discounts', 'tiermint' ),
				'description' => __( 'What happens when a shopper has a discount code and an automatic discount also matches their cart.', 'tiermint' ),
				'options'     => array(
					array(
						'value' => 'code_replaces_auto',
						'label' => __( 'A discount code replaces automatic discounts', 'tiermint' ),
					),
					array(
						'value' => 'stack',
						'label' => __( 'A discount code stacks with automatic discounts', 'tiermint' ),
					),
				),
				'path'        => 'discounts.code_combination',
				'default'     => 'code_replaces_auto',
			),
			array(
				'id'          => 'discount_strikethrough',
				'section'     => 'discounts',
				'type'        => 'toggle',
				'label'       => __( 'Show the original price struck through', 'tiermint' ),
				'description' => __( 'When an automatic discount applies, show the original price struck through with the discounted price next to it on product pages and the store grid.', 'tiermint' ),
				'path'        => 'storefront.discount_strikethrough',
				'default'     => true,
			),
			array(
				'id'          => 'appearance_primary',
				'section'     => 'appearance',
				'type'        => 'color',
				'label'       => __( 'Primary color', 'tiermint' ),
				'description' => sprintf(
					/* translators: %s: the shipped default colour, a hex code. */
					__( 'The main accent for buttons, links, and selected states. Its hover, soft, and focus shades are derived automatically, so pick a reasonably dark colour for readable button labels. Leave empty for the default (%s).', 'tiermint' ),
					'#2563eb'
				),
				'path'        => 'appearance.primary',
				'default'     => '',
			),
			array(
				'id'          => 'appearance_text',
				'section'     => 'appearance',
				'type'        => 'color',
				'label'       => __( 'Text color', 'tiermint' ),
				'description' => sprintf(
					/* translators: %s: the shipped default colour, a hex code. */
					__( 'The body text colour inside storefront components. Leave empty for the default (%s).', 'tiermint' ),
					'#111827'
				),
				'path'        => 'appearance.text',
				'default'     => '',
			),
			array(
				'id'          => 'appearance_surface',
				'section'     => 'appearance',
				'type'        => 'color',
				'label'       => __( 'Surface color', 'tiermint' ),
				'description' => sprintf(
					/* translators: %s: the shipped default colour, a hex code. */
					__( 'The background for cards, panels, and form fields. Leave empty for the default (%s).', 'tiermint' ),
					'#ffffff'
				),
				'path'        => 'appearance.surface',
				'default'     => '',
			),
			array(
				'id'          => 'appearance_page',
				'section'     => 'appearance',
				'type'        => 'color',
				'label'       => __( 'Page background', 'tiermint' ),
				'description' => sprintf(
					/* translators: %s: the shipped default colour, a hex code. */
					__( 'The page wash behind full-page surfaces such as the checkout and account areas. Leave empty for the default (%s).', 'tiermint' ),
					'#f6f7f9'
				),
				'path'        => 'appearance.page',
				'default'     => '',
			),
			array(
				'id'          => 'appearance_border',
				'section'     => 'appearance',
				'type'        => 'color',
				'label'       => __( 'Border color', 'tiermint' ),
				'description' => sprintf(
					/* translators: %s: the shipped default colour, a hex code. */
					__( 'Borders and dividers. The stronger control border is derived from it. Leave empty for the default (%s).', 'tiermint' ),
					'#e5e7eb'
				),
				'path'        => 'appearance.border',
				'default'     => '',
			),
			array(
				'id'          => 'appearance_success',
				'section'     => 'appearance',
				'type'        => 'color',
				'label'       => __( 'Success color', 'tiermint' ),
				'description' => sprintf(
					/* translators: %s: the shipped default colour, a hex code. */
					__( 'The accent for success notices and badges; its tinted background is derived. Leave empty for the default (%s).', 'tiermint' ),
					'#15803d'
				),
				'path'        => 'appearance.success',
				'default'     => '',
			),
			array(
				'id'          => 'appearance_error',
				'section'     => 'appearance',
				'type'        => 'color',
				'label'       => __( 'Error color', 'tiermint' ),
				'description' => sprintf(
					/* translators: %s: the shipped default colour, a hex code. */
					__( 'The accent for error notices and badges; its tinted background is derived. Leave empty for the default (%s).', 'tiermint' ),
					'#b91c1c'
				),
				'path'        => 'appearance.error',
				'default'     => '',
			),
			array(
				'id'          => 'appearance_radius',
				'section'     => 'appearance',
				'type'        => 'select',
				'label'       => __( 'Corner radius', 'tiermint' ),
				'description' => __( 'How rounded corners are on buttons, inputs, and cards.', 'tiermint' ),
				'options'     => array(
					array(
						'value' => '',
						'label' => __( 'Default', 'tiermint' ),
					),
					array(
						'value' => 'small',
						'label' => __( 'Small', 'tiermint' ),
					),
					array(
						'value' => 'large',
						'label' => __( 'Large', 'tiermint' ),
					),
				),
				'path'        => 'appearance.radius',
				'default'     => '',
			),
			array(
				'id'          => 'appearance_font',
				'section'     => 'appearance',
				'type'        => 'font_stack',
				'label'       => __( 'Font family', 'tiermint' ),
				'description' => __( 'A CSS font-family stack for every storefront component, for example: Inter, Arial, sans-serif. Leave empty for the built-in system font.', 'tiermint' ),
				'path'        => 'appearance.font',
				'default'     => '',
			),
			array(
				'id'          => 'email_from_name',
				'section'     => 'emails',
				'type'        => 'text',
				'label'       => __( 'From name', 'tiermint' ),
				'description' => __( 'The sender name on store emails. Defaults to the site title when empty.', 'tiermint' ),
				'path'        => 'emails.from_name',
				'default'     => '',
			),
			array(
				'id'          => 'email_from_address',
				'section'     => 'emails',
				'type'        => 'email',
				'label'       => __( 'From address', 'tiermint' ),
				'description' => __( 'The sender address on store emails. Defaults to the site admin address when empty.', 'tiermint' ),
				'path'        => 'emails.from_address',
				'default'     => '',
			),
			array(
				'id'          => 'email_enabled_purchase_receipt',
				'section'     => 'emails',
				'type'        => 'toggle',
				'label'       => __( 'Send purchase receipts', 'tiermint' ),
				'description' => __( 'Email buyers a receipt with their downloads and license keys after a completed purchase.', 'tiermint' ),
				'path'        => 'emails.enabled_purchase_receipt',
				'default'     => true,
			),
			array(
				'id'          => 'email_enabled_license_delivery',
				'section'     => 'emails',
				'type'        => 'toggle',
				'label'       => __( 'Send license delivery emails', 'tiermint' ),
				'description' => __( 'Email keys that are generated after the receipt already went out.', 'tiermint' ),
				'path'        => 'emails.enabled_license_delivery',
				'default'     => true,
			),
			array(
				'id'            => 'stripe_mode',
				'section'       => 'payments',
				'gateway'       => 'stripe',
				'gateway_label' => __( 'Stripe', 'tiermint' ),
				'type'          => 'select',
				'label'         => __( 'Stripe mode', 'tiermint' ),
				'description'   => __( 'Test mode uses your test keys and charges nothing real.', 'tiermint' ),
				'options'       => array(
					array(
						'value' => 'test',
						'label' => __( 'Test', 'tiermint' ),
					),
					array(
						'value' => 'live',
						'label' => __( 'Live', 'tiermint' ),
					),
				),
				'path'          => 'payments.stripe.mode',
				'default'       => 'test',
			),
			array(
				'id'            => 'stripe_publishable_key_test',
				'section'       => 'payments',
				'gateway'       => 'stripe',
				'gateway_label' => __( 'Stripe', 'tiermint' ),
				'group'         => 'test',
				'type'          => 'text',
				'label'         => __( 'Test publishable key', 'tiermint' ),
				'description'   => __( 'Starts with pk_test_.', 'tiermint' ),
				'path'          => 'payments.stripe.publishable_key_test',
				'default'       => '',
			),
			array(
				'id'            => 'stripe_publishable_key_live',
				'section'       => 'payments',
				'gateway'       => 'stripe',
				'gateway_label' => __( 'Stripe', 'tiermint' ),
				'group'         => 'live',
				'type'          => 'text',
				'label'         => __( 'Live publishable key', 'tiermint' ),
				'description'   => __( 'Starts with pk_live_.', 'tiermint' ),
				'path'          => 'payments.stripe.publishable_key_live',
				'default'       => '',
			),
			array(
				'id'            => 'stripe_secret_key_test',
				'section'       => 'payments',
				'gateway'       => 'stripe',
				'gateway_label' => __( 'Stripe', 'tiermint' ),
				'group'         => 'test',
				'type'          => 'secret',
				'label'         => __( 'Test secret key', 'tiermint' ),
				'description'   => __( 'Starts with sk_test_ or rk_test_. Stored encrypted; only the last characters are ever shown again.', 'tiermint' ),
				'storage'       => 'stripe',
				'stripe'        => array(
					'kind' => 'secret',
					'mode' => 'test',
				),
			),
			array(
				'id'            => 'stripe_secret_key_live',
				'section'       => 'payments',
				'gateway'       => 'stripe',
				'gateway_label' => __( 'Stripe', 'tiermint' ),
				'group'         => 'live',
				'type'          => 'secret',
				'label'         => __( 'Live secret key', 'tiermint' ),
				'description'   => __( 'Starts with sk_live_ or rk_live_. Stored encrypted; only the last characters are ever shown again.', 'tiermint' ),
				'storage'       => 'stripe',
				'stripe'        => array(
					'kind' => 'secret',
					'mode' => 'live',
				),
			),
			array(
				'id'            => 'stripe_webhook_secret_test',
				'section'       => 'payments',
				'gateway'       => 'stripe',
				'gateway_label' => __( 'Stripe', 'tiermint' ),
				'group'         => 'test',
				'type'          => 'secret',
				'label'         => __( 'Test webhook signing secret', 'tiermint' ),
				'description'   => __( 'Starts with whsec_. From the Stripe dashboard webhook endpoint.', 'tiermint' ),
				'storage'       => 'stripe',
				'stripe'        => array(
					'kind' => 'webhook_secret',
					'mode' => 'test',
				),
			),
			array(
				'id'            => 'stripe_webhook_secret_live',
				'section'       => 'payments',
				'gateway'       => 'stripe',
				'gateway_label' => __( 'Stripe', 'tiermint' ),
				'group'         => 'live',
				'type'          => 'secret',
				'label'         => __( 'Live webhook signing secret', 'tiermint' ),
				'description'   => __( 'Starts with whsec_. From the Stripe dashboard webhook endpoint.', 'tiermint' ),
				'storage'       => 'stripe',
				'stripe'        => array(
					'kind' => 'webhook_secret',
					'mode' => 'live',
				),
			),
			array(
				'id'          => 'integrations_elementor',
				'section'     => 'integrations',
				'type'        => 'toggle',
				'label'       => __( 'Elementor widgets', 'tiermint' ),
				'description' => __( 'Add the TierMint widgets and dynamic tags to the Elementor editor. Turn off to remove them without deactivating Elementor.', 'tiermint' ),
				'path'        => 'integrations.elementor',
				'default'     => true,
			),
		);
	}
}
