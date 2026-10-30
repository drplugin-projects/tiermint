<?php
/**
 * The advertised Pro features and the upsell copy.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Upsell;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * The single source the upsell surfaces read their copy from.
 *
 * Every row names a feature the separate Pro add-on ships.
 *
 * @since 1.0.0
 */
final class ProFeatures {

	/**
	 * The feature rows shown on the upgrade screen and the settings teaser.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array{key: string, title: string, blurb: string}> The rows.
	 */
	public function all(): array {
		return array(
			array(
				'key'   => 'subscriptions',
				'title' => __( 'Subscriptions and recurring billing', 'tiermint' ),
				'blurb' => __( 'Sell plans that renew on a schedule, with trials, upgrades, and automatic retries on a failed payment.', 'tiermint' ),
			),
			array(
				'key'   => 'tax',
				'title' => __( 'EU VAT with VIES and OSS returns', 'tiermint' ),
				'blurb' => __( 'Charge VAT at each buyer\'s country rate, check VAT numbers against VIES, and prepare your OSS return.', 'tiermint' ),
			),
			array(
				'key'   => 'invoices',
				'title' => __( 'PDF invoices', 'tiermint' ),
				'blurb' => __( 'Issue a numbered PDF invoice for every order and let buyers download it from their account.', 'tiermint' ),
			),
			array(
				'key'   => 'paypal',
				'title' => __( 'PayPal payments', 'tiermint' ),
				'blurb' => __( 'Take payments through PayPal alongside Stripe.', 'tiermint' ),
			),
			array(
				'key'   => 'affiliates',
				'title' => __( 'Affiliates', 'tiermint' ),
				'blurb' => __( 'Run an affiliate program with referral tracking, commissions, and payouts.', 'tiermint' ),
			),
			array(
				'key'   => 'recovery',
				'title' => __( 'Cart recovery emails', 'tiermint' ),
				'blurb' => __( 'Email shoppers who leave without paying, on a schedule you set, each with an optional discount.', 'tiermint' ),
			),
			array(
				'key'   => 'analytics',
				'title' => __( 'Store analytics', 'tiermint' ),
				'blurb' => __( 'Track recurring revenue, active subscriptions, and customer lifetime value.', 'tiermint' ),
			),
			array(
				'key'   => 'kits',
				'title' => __( 'Template kits', 'tiermint' ),
				'blurb' => __( 'Install a ready-made storefront layout as a starting point for your own.', 'tiermint' ),
			),
			array(
				'key'   => 'migration',
				'title' => __( 'Store migration', 'tiermint' ),
				'blurb' => __( 'Import products and customers from another platform.', 'tiermint' ),
			),
			array(
				'key'   => 'elementor',
				'title' => __( 'Elementor subscription and invoice widgets', 'tiermint' ),
				'blurb' => __( 'Add Subscriptions, Invoices, and Upgrade Prompt widgets to Elementor.', 'tiermint' ),
			),
			array(
				'key'   => 'conditional_content',
				'title' => __( 'Conditional Content block', 'tiermint' ),
				'blurb' => __( 'Show inner blocks only to visitors who match a license, subscription, purchase, or activation rule.', 'tiermint' ),
			),
		);
	}

	/**
	 * The lead paragraph on the upgrade screen.
	 *
	 * @since 1.0.0
	 *
	 * @return string The intro text.
	 */
	public function intro(): string {
		return __( 'TierMint Pro is a separate add-on that extends this plugin. It adds recurring billing, EU VAT handling, PDF invoices, PayPal, affiliates, cart recovery, and store analytics. The free plugin keeps working as it is.', 'tiermint' );
	}

	/**
	 * The one-line banner pitch.
	 *
	 * @since 1.0.0
	 *
	 * @return string The notice text.
	 */
	public function notice(): string {
		return __( 'TierMint Pro adds subscriptions, EU VAT, PDF invoices, PayPal, affiliates, and cart recovery.', 'tiermint' );
	}

	/**
	 * The label on every upgrade link.
	 *
	 * @since 1.0.0
	 *
	 * @return string The link label.
	 */
	public function linkLabel(): string {
		return __( 'See TierMint Pro', 'tiermint' );
	}

	/**
	 * The label on the upgrade screen's heading button.
	 *
	 * @since 1.0.0
	 *
	 * @return string The button label.
	 */
	public function ctaLabel(): string {
		return __( 'View TierMint Pro features', 'tiermint' );
	}
}
