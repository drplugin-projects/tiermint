<?php
/**
 * The customer account-dashboard front-end component.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend\Components;

use DrPlugin\WP\TierMint\Customers\Customer;
use DrPlugin\WP\TierMint\Frontend\AbstractComponent;
use DrPlugin\WP\TierMint\Frontend\AttributeResolver;
use DrPlugin\WP\TierMint\Frontend\Component;
use DrPlugin\WP\TierMint\Frontend\ComponentRegistry;
use DrPlugin\WP\TierMint\Frontend\RenderContext;
use DrPlugin\WP\TierMint\Orders\Order;
use DrPlugin\WP\TierMint\Orders\OrderService;
use DrPlugin\WP\TierMint\Orders\OrderStatus;
use DrPlugin\WP\TierMint\Orders\OrderType;
use DrPlugin\WP\TierMint\Products\Product;
use DrPlugin\WP\TierMint\Util\Money;
use DrPlugin\WP\TierMint\Util\RequestUrl;

defined( 'ABSPATH' ) || exit;

/**
 * Account home for purchases, downloads and licenses.
 *
 * Without JavaScript, all panels remain visible sections.
 *
 * @since 1.0.0
 */
final class AccountDashboard extends AbstractComponent {

	/**
	 * Maximum rows in the recent-purchases panel.
	 *
	 * @var int
	 */
	private const MAX_PURCHASES = 20;

	/**
	 * Filter for extension dashboard panels.
	 *
	 * @var string
	 */
	public const PANELS_FILTER = 'tiermint_account_dashboard_panels';

	/**
	 * Panel keys reserved by the dashboard.
	 *
	 * @var string[]
	 */
	private const RESERVED_KEYS = array( 'purchases', 'downloads', 'licenses' );

	/**
	 * The registry the downloads and licenses panels are composed through.
	 *
	 * @var ComponentRegistry
	 */
	private ComponentRegistry $registry;

	/**
	 * Resolves default attributes for composed components.
	 *
	 * @var AttributeResolver
	 */
	private AttributeResolver $attributes;

	/**
	 * The order service the purchases panel reads history from.
	 *
	 * @var OrderService
	 */
	private OrderService $orders;

	/**
	 * The money formatter for each order total.
	 *
	 * @var Money
	 */
	private Money $money;

	/**
	 * Wire dashboard collaborators.
	 *
	 * @since 1.0.0
	 *
	 * @param ComponentRegistry $registry   The shared component registry.
	 * @param AttributeResolver $attributes The schema attribute resolver.
	 * @param OrderService      $orders     The order service.
	 * @param Money             $money      The money formatter.
	 */
	public function __construct(
		ComponentRegistry $registry,
		AttributeResolver $attributes,
		OrderService $orders,
		Money $money
	) {
		$this->registry   = $registry;
		$this->attributes = $attributes;
		$this->orders     = $orders;
		$this->money      = $money;
	}

	/**
	 * Get the component slug.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'account-dashboard';
	}

	/**
	 * Get component attribute definitions.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function schema(): array {
		return array(
			'title'          => array(
				'type'        => 'string',
				'default'     => __( 'My account', 'tiermint' ),
				'control'     => 'text',
				'label'       => __( 'Heading', 'tiermint' ),
				'description' => __( 'The heading shown above the dashboard. Leave empty to hide it.', 'tiermint' ),
			),
			'show_purchases' => array(
				'type'        => 'boolean',
				'default'     => true,
				'control'     => 'toggle',
				'label'       => __( 'Show purchases', 'tiermint' ),
				'description' => __( 'Show the purchase-history panel.', 'tiermint' ),
			),
			'show_downloads' => array(
				'type'        => 'boolean',
				'default'     => true,
				'control'     => 'toggle',
				'label'       => __( 'Show downloads', 'tiermint' ),
				'description' => __( 'Show the downloads panel.', 'tiermint' ),
			),
			'show_licenses'  => array(
				'type'        => 'boolean',
				'default'     => true,
				'control'     => 'toggle',
				'label'       => __( 'Show licenses', 'tiermint' ),
				'description' => __( 'Show the licenses panel.', 'tiermint' ),
			),
		);
	}

	/**
	 * Get dashboard and composed-panel assets.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string[]>
	 */
	public function assets(): array {
		$assets = array(
			'styles'  => array(
				'tiermint-account-dashboard',
				'tiermint-downloads-list',
				'tiermint-license-manager',
			),
			'scripts' => array(
				'tiermint-account-dashboard',
				'tiermint-license-manager',
			),
		);

		// Composed extension panels also need their declared assets.
		foreach ( $this->contributedPanels() as $panel ) {
			$component = $this->registry->get( $panel['slug'] );

			if ( ! $component instanceof Component ) {
				continue;
			}

			$declared = $component->assets();

			foreach ( array( 'styles', 'scripts' ) as $kind ) {
				$handles         = isset( $declared[ $kind ] ) && is_array( $declared[ $kind ] ) ? $declared[ $kind ] : array();
				$assets[ $kind ] = array_values( array_unique( array_merge( $assets[ $kind ], array_filter( $handles, 'is_string' ) ) ) );
			}
		}

		return $assets;
	}

	/**
	 * Render the dashboard for the current context.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $attrs Resolved attribute values.
	 * @param RenderContext        $ctx   Per-request customer/license state.
	 * @return string Escaped HTML.
	 */
	public function render( array $attrs, RenderContext $ctx ): string {
		$title = (string) $attrs['title'];

		// In an editor preview always render the sample dashboard, whether or not
		// the merchant happens to have a customer record. The preview exists to
		// design the page, not to show the merchant's own account, so the greeting,
		// the purchase row, and the composed panels are consistent sample content
		// end to end rather than a mix of the merchant's real shell and sample
		// panels. Live rendering (below) is untouched.
		if ( $ctx->isEditorPreview() ) {
			return $this->renderSample( $this->sampleDashboardData( $attrs, $title, $ctx ) );
		}

		// A signed-in user gets a customer record with their first purchase.
		if ( null === $ctx->customer() && ! is_user_logged_in() ) {
			return $this->renderTemplate( $this->loggedOutData( $title, $ctx ) );
		}

		return $this->renderTemplate( $this->dashboardData( $attrs, $title, $ctx ) );
	}

	/**
	 * Build a customer-free preview context.
	 *
	 * It prevents preview panels from reading store data.
	 *
	 * @since 1.0.0
	 *
	 * @param RenderContext $ctx The current (preview) render context.
	 * @return RenderContext
	 */
	private function sampleContext( RenderContext $ctx ): RenderContext {
		return new RenderContext(
			null,
			static fn (): array => array(),
			true,
			null,
			static fn (): ?Product => $ctx->product(),
			true
		);
	}

	/**
	 * Build sample dashboard data for the editor.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $attrs The resolved attributes.
	 * @param string               $title The resolved heading.
	 * @param RenderContext        $ctx   The current (preview) render context.
	 * @return array<string, mixed>
	 */
	private function sampleDashboardData( array $attrs, string $title, RenderContext $ctx ): array {
		$base = wp_unique_id( 'tiermint-account-dashboard-' );

		// Use sample data, not store data.
		$sampleCtx = $this->sampleContext( $ctx );

		$tabs = array();

		if ( (bool) $attrs['show_purchases'] ) {
			$tabs[] = array_merge(
				$this->tab( $base, 'purchases', __( 'Purchases', 'tiermint' ) ),
				array( 'purchases' => $this->samplePurchaseRows() )
			);
		}

		if ( (bool) $attrs['show_downloads'] && $this->registry->has( 'downloads-list' ) ) {
			$tabs[] = array_merge(
				$this->tab( $base, 'downloads', __( 'Downloads', 'tiermint' ) ),
				array( 'html' => $this->composePanel( 'downloads-list', $sampleCtx ) )
			);
		}

		if ( (bool) $attrs['show_licenses'] && $this->registry->has( 'license-manager' ) ) {
			$tabs[] = array_merge(
				$this->tab( $base, 'licenses', __( 'Licenses', 'tiermint' ) ),
				array( 'html' => $this->composePanel( 'license-manager', $sampleCtx ) )
			);
		}

		foreach ( $this->contributedPanels() as $panel ) {
			$tabs[] = array_merge(
				$this->tab( $base, $panel['key'], $panel['label'] ),
				array( 'html' => $this->composePanel( $panel['slug'], $sampleCtx ) )
			);
		}

		// Preview one active tab and panel.
		$this->markFirstTabActive( $tabs );

		return array(
			'state'  => 'dashboard',
			'title'  => $title,
			'header' => array(
				'avatar'   => '',
				'greeting' => sprintf(
					/* translators: %s: the customer's display name. */
					__( 'Hi, %s', 'tiermint' ),
					__( 'Sample customer', 'tiermint' )
				),
			),
			'tabs'   => $tabs,
		);
	}

	/**
	 * Build one sample purchase row.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function samplePurchaseRows(): array {
		return array(
			array(
				'reference'    => '#1042',
				'date'         => (string) date_i18n( (string) get_option( 'date_format' ), 1_722_506_400 ),
				'items'        => array(
					array(
						'name'     => __( 'Sample product', 'tiermint' ),
						'quantity' => 1,
					),
				),
				'total'        => $this->money->format( 4900 ),
				'status_slug'  => 'completed',
				'status_label' => __( 'Completed', 'tiermint' ),
			),
		);
	}

	/**
	 * Build the logged-out login prompt.
	 *
	 * @since 1.0.0
	 *
	 * @param string        $title The resolved heading.
	 * @param RenderContext $ctx   The current render context, threaded into the composed form.
	 * @return array<string, mixed>
	 */
	private function loggedOutData( string $title, RenderContext $ctx ): array {
		// Keep a fallback URL if the composed form is unavailable.
		$data = array(
			'state'      => 'logged_out',
			'title'      => $title,
			'login_html' => '',
			'login_url'  => wp_login_url( RequestUrl::current() ),
		);

		if ( $this->registry->has( 'login-form' ) ) {
			$data['login_html'] = $this->composePanel( 'login-form', $ctx );
		}

		return $data;
	}

	/**
	 * Build enabled dashboard tabs and panel data.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $attrs The resolved attributes.
	 * @param string               $title The resolved heading.
	 * @param RenderContext        $ctx   The current render context.
	 * @return array<string, mixed>
	 */
	private function dashboardData( array $attrs, string $title, RenderContext $ctx ): array {
		// Keep tab and panel IDs unique across dashboard instances.
		$base = wp_unique_id( 'tiermint-account-dashboard-' );

		$customer   = $ctx->customer();
		$customerId = null !== $customer ? (int) $customer->id() : 0;

		$tabs = array();

		if ( (bool) $attrs['show_purchases'] ) {
			$tabs[] = array_merge(
				$this->tab( $base, 'purchases', __( 'Purchases', 'tiermint' ) ),
				array( 'purchases' => $customerId > 0 ? $this->purchaseRows( $customerId ) : array() )
			);
		}

		if ( (bool) $attrs['show_downloads'] && $this->registry->has( 'downloads-list' ) ) {
			$tabs[] = array_merge(
				$this->tab( $base, 'downloads', __( 'Downloads', 'tiermint' ) ),
				array( 'html' => $this->composePanel( 'downloads-list', $ctx ) )
			);
		}

		if ( (bool) $attrs['show_licenses'] && $this->registry->has( 'license-manager' ) ) {
			$tabs[] = array_merge(
				$this->tab( $base, 'licenses', __( 'Licenses', 'tiermint' ) ),
				array( 'html' => $this->composePanel( 'license-manager', $ctx ) )
			);
		}

		// Render extension panels through the same safe component path.
		foreach ( $this->contributedPanels() as $panel ) {
			$tabs[] = array_merge(
				$this->tab( $base, $panel['key'], $panel['label'] ),
				array( 'html' => $this->composePanel( $panel['slug'], $ctx ) )
			);
		}

		return array(
			'state'  => 'dashboard',
			'title'  => $title,
			'header' => null !== $customer ? $this->headerData( $customer ) : $this->userHeaderData(),
			'tabs'   => $tabs,
		);
	}

	/**
	 * Build the greeting for a signed-in user with no customer record yet.
	 *
	 * @since 1.0.0
	 *
	 * @return array{avatar: string, greeting: string} The avatar HTML (empty when avatars are off) and the greeting text.
	 */
	private function userHeaderData(): array {
		$user   = wp_get_current_user();
		$avatar = get_avatar( (int) $user->ID, 48 );
		$name   = '' !== (string) $user->display_name ? (string) $user->display_name : (string) $user->user_email;

		return array(
			'avatar'   => is_string( $avatar ) ? $avatar : '',
			'greeting' => sprintf(
				/* translators: %s: the customer's display name. */
				__( 'Hi, %s', 'tiermint' ),
				$name
			),
		);
	}

	/**
	 * Build the logged-in customer greeting.
	 *
	 * @since 1.0.0
	 *
	 * @param Customer $customer The current customer.
	 * @return array{avatar: string, greeting: string} The avatar HTML (empty when avatars are off) and the greeting text.
	 */
	private function headerData( Customer $customer ): array {
		$userId = $customer->userId();

		// An email keeps the avatar available for an unlinked customer.
		$avatar = get_avatar( null !== $userId ? $userId : $customer->email(), 48 );

		return array(
			'avatar'   => is_string( $avatar ) ? $avatar : '',
			'greeting' => sprintf(
				/* translators: %s: the customer's display name. */
				__( 'Hi, %s', 'tiermint' ),
				$this->displayName( $customer )
			),
		);
	}

	/**
	 * Get a non-empty customer display name.
	 *
	 * @since 1.0.0
	 *
	 * @param Customer $customer The current customer.
	 * @return string A non-empty display name.
	 */
	private function displayName( Customer $customer ): string {
		$userId = $customer->userId();

		if ( null !== $userId ) {
			$user = get_userdata( $userId );

			if ( false !== $user && '' !== (string) $user->display_name ) {
				return (string) $user->display_name;
			}
		}

		if ( null !== $customer->name() ) {
			return $customer->name();
		}

		$full = trim( (string) $customer->firstName() . ' ' . (string) $customer->lastName() );

		return '' !== $full ? $full : $customer->email();
	}

	/**
	 * Build one tab and its panel identifiers.
	 *
	 * @since 1.0.0
	 *
	 * @param string $base  The per-render id base.
	 * @param string $key   The panel key (purchases|downloads|licenses).
	 * @param string $label The translated tab/panel heading.
	 * @return array<string, mixed>
	 */
	private function tab( string $base, string $key, string $label ): array {
		return array(
			'key'       => $key,
			'tab_id'    => $base . '-' . $key . '-tab',
			'panel_id'  => $base . '-' . $key,
			'label'     => $label,
			'purchases' => array(),
			'html'      => '',
			// Live markup sets no tab state and leaves all panels visible without JavaScript.
			'selected'  => null,
			'hidden'    => false,
		);
	}

	/**
	 * Set preview-only state for the first tab and panel.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, array<string, mixed>> $tabs The prepared tabs, by reference.
	 * @return void
	 */
	private function markFirstTabActive( array &$tabs ): void {
		foreach ( array_keys( $tabs ) as $index ) {
			$tabs[ $index ]['selected'] = 0 === $index;
			$tabs[ $index ]['hidden']   = 0 !== $index;
		}
	}

	/**
	 * Validate dashboard panels from extensions.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array{key: string, slug: string, label: string}> The usable contributions, in declaration order.
	 */
	private function contributedPanels(): array {
		/**
		 * Filters extension dashboard panels.
		 *
		 * Each item needs a registered `slug` and translated `label`. `key` is optional.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, array{key?: string, slug: string, label: string}> $panels The contributed panels. Default none.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant is the literal `tiermint_account_dashboard_panels` hook name; it is a constant only because assets() applies the same filter.
		$declared = apply_filters( self::PANELS_FILTER, array() );

		if ( ! is_array( $declared ) ) {
			return array();
		}

		$panels = array();
		$slugs  = array();

		// Reserve both generated ID forms to prevent ARIA collisions.
		$taken = array();

		foreach ( self::RESERVED_KEYS as $reserved ) {
			$taken[] = $reserved;
			$taken[] = $reserved . '-tab';
		}

		foreach ( $declared as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$slug  = $entry['slug'] ?? '';
			$label = $entry['label'] ?? '';

			if ( ! is_string( $slug ) || '' === $slug || ! is_string( $label ) || '' === $label ) {
				continue;
			}

			$declaredKey = $entry['key'] ?? '';
			$key         = sanitize_key( is_string( $declaredKey ) && '' !== $declaredKey ? $declaredKey : $slug );

			// Check both generated ID forms for collisions.
			if ( '' === $key || in_array( $key, $taken, true ) || in_array( $key . '-tab', $taken, true ) ) {
				continue;
			}

			// Each component can appear once.
			if ( in_array( $slug, $slugs, true ) ) {
				continue;
			}

			// Reject self-composition to prevent recursive rendering.
			if ( $slug === $this->slug() ) {
				continue;
			}

			// Do not show a tab for an unregistered component.
			if ( ! $this->registry->has( $slug ) ) {
				continue;
			}

			$taken[]  = $key;
			$taken[]  = $key . '-tab';
			$slugs[]  = $slug;
			$panels[] = array(
				'key'   => $key,
				'slug'  => $slug,
				'label' => $label,
			);
		}

		return $panels;
	}

	/**
	 * Render a registered component as a dashboard panel.
	 *
	 * @since 1.0.0
	 *
	 * @param string        $slug The component slug to compose.
	 * @param RenderContext $ctx  The shared render context.
	 * @return string The sub-component's fully-escaped HTML.
	 */
	private function composePanel( string $slug, RenderContext $ctx ): string {
		$component = $this->registry->get( $slug );

		if ( ! $component instanceof Component ) {
			return '';
		}

		$attrs = $this->attributes->resolve( $component->schema(), array() );

		// The panel supplies the heading, so suppress the component title.
		if ( array_key_exists( 'title', $attrs ) ) {
			$attrs['title'] = '';
		}

		return $component->render( $attrs, $ctx );
	}

	/**
	 * Build recent completed or partly refunded purchase rows.
	 *
	 * @since 1.0.0
	 *
	 * @param int $customerId The customer id.
	 * @return array<int, array<string, mixed>> One prepared row per shown order.
	 */
	private function purchaseRows( int $customerId ): array {
		$orders = $this->orders->list(
			array(
				'customer_id' => $customerId,
				'per_page'    => 50,
			)
		);

		$rows = array();

		foreach ( $orders as $order ) {
			if ( OrderType::Refund === $order->type() || ! $this->orderIsOwned( $order ) ) {
				continue;
			}

			$rows[] = $this->purchaseRow( $order );

			if ( count( $rows ) >= self::MAX_PURCHASES ) {
				break;
			}
		}

		return $rows;
	}

	/**
	 * Check if the customer still owns part of this order.
	 *
	 * @since 1.0.0
	 *
	 * @param Order $order The order to test.
	 * @return bool True when the order should appear in the purchases panel.
	 */
	private function orderIsOwned( Order $order ): bool {
		return in_array(
			$order->status(),
			array( OrderStatus::Completed, OrderStatus::PartiallyRefunded ),
			true
		);
	}

	/**
	 * Build one purchase-row view model.
	 *
	 * @since 1.0.0
	 *
	 * @param Order $order The order to present.
	 * @return array<string, mixed>
	 */
	private function purchaseRow( Order $order ): array {
		$items = array();

		foreach ( $order->items() as $item ) {
			$items[] = array(
				'name'     => $item->productName(),
				'quantity' => $item->quantity(),
			);
		}

		return array(
			'reference'    => '#' . (int) $order->id(),
			'date'         => (string) date_i18n( (string) get_option( 'date_format' ), $this->orderTimestamp( $order ) ),
			'items'        => $items,
			'total'        => $this->money->format( $order->total(), $order->currency() ),
			'status_slug'  => $order->status()->value,
			'status_label' => $this->statusLabel( $order->status() ),
		);
	}

	/**
	 * Get the order display timestamp from its UTC dates.
	 *
	 * @since 1.0.0
	 *
	 * @param Order $order The order.
	 * @return int The Unix timestamp.
	 */
	private function orderTimestamp( Order $order ): int {
		$stamp = $order->completedAt() ?? $order->createdAt();

		if ( null === $stamp ) {
			return time();
		}

		$parsed = strtotime( $stamp . ' UTC' );

		return false !== $parsed ? $parsed : time();
	}

	/**
	 * Get the translated purchase status label.
	 *
	 * @since 1.0.0
	 *
	 * @param OrderStatus $status The order status.
	 * @return string
	 */
	private function statusLabel( OrderStatus $status ): string {
		return match ( $status ) {
			OrderStatus::Completed         => __( 'Completed', 'tiermint' ),
			OrderStatus::PartiallyRefunded => __( 'Partially refunded', 'tiermint' ),
			default                        => $status->value,
		};
	}
}
