<?php
/**
 * The customer license-manager front-end component.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend\Components;

use DateTimeImmutable;
use DateTimeZone;
use DrPlugin\WP\TierMint\Frontend\AbstractComponent;
use DrPlugin\WP\TierMint\Frontend\RenderContext;
use DrPlugin\WP\TierMint\Licensing\ActivationService;
use DrPlugin\WP\TierMint\Licensing\ActivationStatus;
use DrPlugin\WP\TierMint\Licensing\License;
use DrPlugin\WP\TierMint\Licensing\LicenseStatus;
use DrPlugin\WP\TierMint\Products\ProductService;
use DrPlugin\WP\TierMint\Util\RequestUrl;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the current customer's licences and site activations.
 *
 * Licences come from the customer-scoped render context.
 *
 * @since 1.0.0
 */
final class LicenseManager extends AbstractComponent {

	/**
	 * The catalog service used to resolve each license's product name.
	 *
	 * @var ProductService
	 */
	private ProductService $products;

	/**
	 * The activation service used to list a license's active sites.
	 *
	 * @var ActivationService
	 */
	private ActivationService $activations;

	/**
	 * Wire the component with its catalog and activation collaborators.
	 *
	 * @since 1.0.0
	 *
	 * @param ProductService    $products    The catalog service.
	 * @param ActivationService $activations The activation service.
	 */
	public function __construct( ProductService $products, ActivationService $activations ) {
		$this->products    = $products;
		$this->activations = $activations;
	}

	/**
	 * The component slug.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'license-manager';
	}

	/**
	 * The attribute schema adapters map their controls onto.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function schema(): array {
		return array(
			'title'            => array(
				'type'        => 'string',
				'default'     => __( 'Your licenses', 'tiermint' ),
				'control'     => 'text',
				'label'       => __( 'Heading', 'tiermint' ),
				'description' => __( 'The heading shown above the license list. Leave empty to hide it.', 'tiermint' ),
			),
			'show_key'         => array(
				'type'        => 'boolean',
				'default'     => true,
				'control'     => 'toggle',
				'label'       => __( 'Show license key', 'tiermint' ),
				'description' => __( 'Print the full license key for each license.', 'tiermint' ),
			),
			'show_activations' => array(
				'type'        => 'boolean',
				'default'     => true,
				'control'     => 'toggle',
				'label'       => __( 'Show site activations', 'tiermint' ),
				'description' => __( 'Show the active-sites list and the activate/deactivate controls.', 'tiermint' ),
			),
		);
	}

	/**
	 * Render the license manager for the current context.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $attrs Resolved attribute values.
	 * @param RenderContext        $ctx   Per-request customer/license state.
	 * @return string Escaped HTML.
	 */
	public function render( array $attrs, RenderContext $ctx ): string {
		$title = (string) $attrs['title'];

		if ( null === $ctx->customer() ) {
			// The editor has no customer record, so show sample data.
			if ( $ctx->isEditorPreview() ) {
				return $this->renderSample( $this->sampleData( $attrs, $title ), $ctx );
			}

			return $this->renderTemplate( $this->loggedOutData( $title ) );
		}

		$licenses = $ctx->licenses();

		if ( array() === $licenses ) {
			if ( $ctx->isEditorPreview() ) {
				return $this->renderSample( $this->sampleData( $attrs, $title ), $ctx );
			}

			return $this->renderTemplate(
				array(
					'state' => 'empty',
					'title' => $title,
				)
			);
		}

		return $this->renderTemplate( $this->listData( $attrs, $title, $licenses ) );
	}

	/**
	 * Sample licence data for an editor preview.
	 *
	 * @param array<string, mixed> $attrs Resolved attribute values.
	 * @param string               $title The resolved heading.
	 * @return array<string, mixed>
	 */
	private function sampleData( array $attrs, string $title ): array {
		$showActivations = (bool) $attrs['show_activations'];

		return array(
			'state'            => 'list',
			'title'            => $title,
			'show_key'         => (bool) $attrs['show_key'],
			'show_activations' => $showActivations,
			'licenses'         => array(
				array(
					'id'                     => 0,
					'product_name'           => __( 'Sample product', 'tiermint' ),
					'license_key'            => 'TIERMINT-XXXX-XXXX-XXXX-XXXX',
					'status_slug'            => 'active',
					'status_label'           => __( 'Active', 'tiermint' ),
					'expiry_label'           => __( 'Lifetime', 'tiermint' ),
					'activation_count'       => 1,
					'activation_limit_label' => '5',
					'can_activate'           => false,
					'activations'            => $showActivations ? array( array( 'site_url' => 'https://example.com' ) ) : array(),
				),
			),
		);
	}

	/**
	 * View data for the customer-less login prompt.
	 *
	 * @since 1.0.0
	 *
	 * @param string $title The resolved heading.
	 * @return array<string, mixed>
	 */
	private function loggedOutData( string $title ): array {
		$currentUrl = RequestUrl::current();

		return array(
			'state'            => 'logged_out',
			'title'            => $title,
			'login_url'        => wp_login_url( $currentUrl ),
			'registration_url' => (bool) get_option( 'users_can_register' ) ? wp_registration_url() : null,
		);
	}

	/**
	 * The view data for the license-list state: one prepared row per license.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $attrs    Resolved attribute values.
	 * @param string               $title    The resolved heading.
	 * @param array<int, License>  $licenses The customer's licenses (newest first).
	 * @return array<string, mixed>
	 */
	private function listData( array $attrs, string $title, array $licenses ): array {
		$now             = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$showActivations = (bool) $attrs['show_activations'];

		$rows = array();

		foreach ( $licenses as $license ) {
			$rows[] = $this->licenseRow( $license, $now, $showActivations );
		}

		return array(
			'state'            => 'list',
			'title'            => $title,
			'show_key'         => (bool) $attrs['show_key'],
			'show_activations' => $showActivations,
			'licenses'         => $rows,
		);
	}

	/**
	 * Build a view row for one licence.
	 *
	 * Use the current time for expiry without changing stored licence state. A new
	 * activation needs an active licence, no expiry and a free slot.
	 *
	 * @since 1.0.0
	 *
	 * @param License           $license         The license to present.
	 * @param DateTimeImmutable $now             The clock display-expiry is judged against.
	 * @param bool              $showActivations Whether to load and show the sites list.
	 * @return array<string, mixed>
	 */
	private function licenseRow( License $license, DateTimeImmutable $now, bool $showActivations ): array {
		$product     = $this->products->get( $license->productId() );
		$productName = null !== $product ? $product->name() : __( 'Unavailable product', 'tiermint' );

		$displayExpired = $license->isExpiredAt( $now );
		$displayStatus  = $displayExpired ? LicenseStatus::Expired : $license->status();

		$limit = $license->activationLimit();

		$canActivate = LicenseStatus::Active === $license->status()
			&& ! $displayExpired
			&& ! $license->hasReachedActivationLimit();

		$activations = array();

		if ( $showActivations ) {
			foreach ( $this->activations->listForLicense( $license, ActivationStatus::Active ) as $activation ) {
				$activations[] = array( 'site_url' => $activation->siteUrl() );
			}
		}

		return array(
			'id'                     => (int) $license->id(),
			'product_name'           => $productName,
			'license_key'            => $license->licenseKey(),
			'status_slug'            => $displayStatus->value,
			'status_label'           => $this->statusLabel( $displayStatus ),
			'expiry_label'           => $this->expiryLabel( $license ),
			'activation_count'       => $license->activationCount(),
			'activation_limit_label' => null === $limit
				? __( 'unlimited', 'tiermint' )
				: (string) $limit,
			'can_activate'           => $canActivate,
			'activations'            => $activations,
		);
	}

	/**
	 * The human, translated label for a display status.
	 *
	 * @since 1.0.0
	 *
	 * @param LicenseStatus $status The status to label.
	 * @return string
	 */
	private function statusLabel( LicenseStatus $status ): string {
		return match ( $status ) {
			LicenseStatus::Active   => __( 'Active', 'tiermint' ),
			LicenseStatus::Expired  => __( 'Expired', 'tiermint' ),
			LicenseStatus::Disabled => __( 'Disabled', 'tiermint' ),
		};
	}

	/**
	 * Return a date label or the lifetime label.
	 *
	 * @since 1.0.0
	 *
	 * @param License $license The license.
	 * @return string
	 */
	private function expiryLabel( License $license ): string {
		if ( $license->isLifetime() ) {
			return __( 'Lifetime', 'tiermint' );
		}

		$expiresAt = $license->expiresAt();

		return (string) date_i18n( (string) get_option( 'date_format' ), $expiresAt->getTimestamp() );
	}
}
