<?php
/**
 * Service provider for the frontend component layer.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Frontend;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Core\Container;
use DrPlugin\WP\TierMint\Core\ServiceProvider;
use DrPlugin\WP\TierMint\Customers\CustomerService;
use DrPlugin\WP\TierMint\Frontend\Adapters\Blocks\BlockAttributes;
use DrPlugin\WP\TierMint\Frontend\Adapters\Blocks\Patterns;
use DrPlugin\WP\TierMint\Frontend\Adapters\Blocks\Registrar as BlocksRegistrar;
use DrPlugin\WP\TierMint\Frontend\Adapters\Elementor\ControlMap;
use DrPlugin\WP\TierMint\Frontend\Adapters\Elementor\ControlOptions;
use DrPlugin\WP\TierMint\Frontend\Adapters\Elementor\ElementorSettings;
use DrPlugin\WP\TierMint\Frontend\Adapters\Elementor\Registrar as ElementorRegistrar;
use DrPlugin\WP\TierMint\Frontend\Adapters\Elementor\StyleControlMap;
use DrPlugin\WP\TierMint\Frontend\Adapters\Elementor\Tags\TagValues;
use DrPlugin\WP\TierMint\Frontend\Adapters\Elementor\WidgetRenderer;
use DrPlugin\WP\TierMint\Frontend\Adapters\Shortcodes\Registrar;
use DrPlugin\WP\TierMint\Frontend\Components\AccountDashboard;
use DrPlugin\WP\TierMint\Frontend\Components\BuyButton;
use DrPlugin\WP\TierMint\Frontend\Components\CartSummary;
use DrPlugin\WP\TierMint\Frontend\Components\Checkout;
use DrPlugin\WP\TierMint\Frontend\Components\DownloadsList;
use DrPlugin\WP\TierMint\Frontend\Components\LicenseManager;
use DrPlugin\WP\TierMint\Frontend\Components\LoginForm;
use DrPlugin\WP\TierMint\Frontend\Components\PricingTable;
use DrPlugin\WP\TierMint\Frontend\Components\ProductDescription;
use DrPlugin\WP\TierMint\Frontend\Components\ProductDetails;
use DrPlugin\WP\TierMint\Frontend\Components\ProductGrid;
use DrPlugin\WP\TierMint\Frontend\Components\ProductImages;
use DrPlugin\WP\TierMint\Frontend\Components\ProductOverview;
use DrPlugin\WP\TierMint\Frontend\Components\ProductChangelog;
use DrPlugin\WP\TierMint\Frontend\Components\ProductMeta;
use DrPlugin\WP\TierMint\Frontend\Components\ProductPricingTiers;
use DrPlugin\WP\TierMint\Frontend\Components\ProductScreenshots;
use DrPlugin\WP\TierMint\Frontend\Components\PasswordResetForm;
use DrPlugin\WP\TierMint\Frontend\Components\PurchaseConfirmation;
use DrPlugin\WP\TierMint\Frontend\Components\RegistrationForm;
use DrPlugin\WP\TierMint\Database\Logger;
use DrPlugin\WP\TierMint\Licensing\ActivationService;
use DrPlugin\WP\TierMint\Licensing\LicenseService;
use DrPlugin\WP\TierMint\Orders\CartService;
use DrPlugin\WP\TierMint\Orders\OrderService;
use DrPlugin\WP\TierMint\Payments\GatewayRegistry;
use DrPlugin\WP\TierMint\Products\ProductService;
use DrPlugin\WP\TierMint\Products\TermService;
use DrPlugin\WP\TierMint\Updates\ChangelogRenderer;
use DrPlugin\WP\TierMint\Updates\CustomerDownloads;
use DrPlugin\WP\TierMint\Updates\ReleaseRepository;
use DrPlugin\WP\TierMint\Util\Money;
use DrPlugin\WP\TierMint\Util\RateLimiter;

/**
 * Binds frontend components, adapters and request services.
 *
 * @since 1.0.0
 */
class FrontendServiceProvider implements ServiceProvider {

	/**
	 * Bind frontend services into the container.
	 *
	 * @param Container $container The plugin container.
	 * @return void
	 */
	public function register( Container $container ): void {
		$container->singleton(
			Money::class,
			static fn (): Money => new Money()
		);

		$container->singleton(
			AttributeResolver::class,
			static fn (): AttributeResolver => new AttributeResolver()
		);

		$container->singleton(
			Appearance::class,
			static fn (): Appearance => new Appearance()
		);

		$container->singleton(
			AppearanceTokens::class,
			static fn ( Container $c ): AppearanceTokens => new AppearanceTokens( $c->get( Appearance::class ) )
		);

		$container->singleton(
			ProductUrlProvider::class,
			static fn ( Container $c ): ProductUrlProvider => new ProductUrlProvider( $c->get( PageResolver::class ) )
		);

		$container->singleton(
			ProductRequestContext::class,
			static fn ( Container $c ): ProductRequestContext => new ProductRequestContext(
				$c->get( ProductService::class ),
				$c->get( PageResolver::class )
			)
		);

		$container->singleton(
			ProductRewrites::class,
			static fn ( Container $c ): ProductRewrites => new ProductRewrites( $c->get( PageResolver::class ) )
		);

		$container->singleton(
			ProductHeadTags::class,
			static fn ( Container $c ): ProductHeadTags => new ProductHeadTags(
				$c->get( ProductRequestContext::class ),
				$c->get( ProductUrlProvider::class )
			)
		);

		$container->singleton(
			RenderContextResolver::class,
			static fn ( Container $c ): RenderContextResolver => new RenderContextResolver(
				$c->get( CustomerService::class ),
				$c->get( LicenseService::class ),
				$c->get( ProductRequestContext::class )
			)
		);

		$container->singleton(
			ComponentRegistry::class,
			static fn ( Container $c ): ComponentRegistry => new ComponentRegistry(
				array(
					BuyButton::class,
					ProductGrid::class,
					ProductDetails::class,
					ProductImages::class,
					ProductOverview::class,
					ProductPricingTiers::class,
					ProductDescription::class,
					ProductScreenshots::class,
					ProductMeta::class,
					ProductChangelog::class,
					PricingTable::class,
					CartSummary::class,
					LoginForm::class,
					RegistrationForm::class,
					PasswordResetForm::class,
					Checkout::class,
					LicenseManager::class,
					DownloadsList::class,
					PurchaseConfirmation::class,
					AccountDashboard::class,
				),
				static fn ( string $className ): object => $c->get( $className )
			)
		);

		$container->singleton(
			AssetManager::class,
			static fn ( Container $c ): AssetManager => new AssetManager( $c->get( ComponentRegistry::class ) )
		);

		$container->singleton(
			Registrar::class,
			static fn ( Container $c ): Registrar => new Registrar(
				$c->get( ComponentRegistry::class ),
				$c->get( AttributeResolver::class ),
				$c->get( RenderContextResolver::class ),
				$c->get( AssetManager::class )
			)
		);

		$container->singleton(
			BlockAttributes::class,
			static fn (): BlockAttributes => new BlockAttributes()
		);

		$container->singleton(
			BlocksRegistrar::class,
			static fn ( Container $c ): BlocksRegistrar => new BlocksRegistrar(
				$c->get( ComponentRegistry::class ),
				$c->get( AttributeResolver::class ),
				$c->get( BlockAttributes::class ),
				$c->get( RenderContextResolver::class ),
				$c->get( AssetManager::class )
			)
		);

		$container->singleton(
			Patterns::class,
			static fn (): Patterns => new Patterns()
		);

		$this->registerElementorAdapter( $container );

		$container->singleton(
			CartActionListener::class,
			static fn ( Container $c ): CartActionListener => new CartActionListener(
				$c->get( CartService::class ),
				$c->get( PageResolver::class ),
				null,
				$c->get( RateLimiter::class ),
				$c->has( \DrPlugin\WP\TierMint\Discounts\DiscountService::class )
					? $c->get( \DrPlugin\WP\TierMint\Discounts\DiscountService::class )
					: null
			)
		);

		$container->singleton(
			RegistrationActionListener::class,
			static fn ( Container $c ): RegistrationActionListener => new RegistrationActionListener(
				$c->get( RateLimiter::class ),
				null,
				$c->get( CustomerService::class ),
				$c->get( PasswordResetLinks::class )
			)
		);

		$container->singleton(
			PageResolver::class,
			static fn (): PageResolver => new PageResolver()
		);

		$container->singleton(
			CheckoutFieldSettings::class,
			static fn (): CheckoutFieldSettings => new CheckoutFieldSettings()
		);

		$container->singleton(
			RenewalUrlProvider::class,
			static fn ( Container $c ): RenewalUrlProvider => new RenewalUrlProvider( $c->get( PageResolver::class ) )
		);

		$container->singleton(
			AdminBarController::class,
			static fn (): AdminBarController => new AdminBarController()
		);

		$container->singleton(
			LoginRedirector::class,
			static fn ( Container $c ): LoginRedirector => new LoginRedirector( $c->get( PageResolver::class ) )
		);

		$container->singleton(
			PasswordResetCookie::class,
			static fn (): PasswordResetCookie => new PasswordResetCookie()
		);

		$container->singleton(
			PasswordResetActionListener::class,
			static fn ( Container $c ): PasswordResetActionListener => new PasswordResetActionListener(
				$c->get( RateLimiter::class ),
				$c->get( PageResolver::class ),
				$c->get( PasswordResetCookie::class ),
				null,
				$c->get( Logger::class ),
				$c->get( PasswordResetLinks::class )
			)
		);

		$container->singleton(
			PasswordResetLinks::class,
			static fn ( Container $c ): PasswordResetLinks => new PasswordResetLinks( $c->get( PageResolver::class ) )
		);

		$container->singleton(
			ProductPageTitle::class,
			static fn ( Container $c ): ProductPageTitle => new ProductPageTitle(
				$c->get( ProductRequestContext::class ),
				$c->get( PageResolver::class )
			)
		);

		$this->registerComponents( $container );
	}

	/**
	 * Bind the default component classes as container services.
	 *
	 * @param Container $container The plugin container.
	 * @return void
	 */
	private function registerComponents( Container $container ): void {
		$container->singleton(
			BuyButton::class,
			static fn ( Container $c ): BuyButton => new BuyButton(
				$c->get( ProductService::class ),
				$c->get( Money::class ),
				self::automaticDiscountResolver( $c ),
				self::discountSettings( $c )
			)
		);

		$container->singleton(
			ProductGrid::class,
			static fn ( Container $c ): ProductGrid => new ProductGrid(
				$c->get( ProductService::class ),
				$c->get( TermService::class ),
				$c->get( Money::class ),
				$c->get( ProductUrlProvider::class ),
				self::automaticDiscountResolver( $c ),
				self::discountSettings( $c )
			)
		);

		$container->singleton(
			ProductDetails::class,
			static fn ( Container $c ): ProductDetails => new ProductDetails(
				$c->get( ProductService::class ),
				$c->get( Money::class ),
				$c->get( ProductPageTitle::class ),
				self::automaticDiscountResolver( $c ),
				self::discountSettings( $c )
			)
		);

		$container->singleton(
			ProductPricingTiers::class,
			static fn ( Container $c ): ProductPricingTiers => new ProductPricingTiers(
				$c->get( ProductService::class ),
				$c->get( Money::class ),
				self::automaticDiscountResolver( $c ),
				self::discountSettings( $c )
			)
		);

		$container->singleton(
			ProductImages::class,
			static fn ( Container $c ): ProductImages => new ProductImages( $c->get( ProductService::class ) )
		);

		$container->singleton(
			ProductOverview::class,
			static fn ( Container $c ): ProductOverview => new ProductOverview(
				$c->get( ProductService::class ),
				$c->get( ProductPageTitle::class )
			)
		);

		$container->singleton(
			ProductDescription::class,
			static fn ( Container $c ): ProductDescription => new ProductDescription( $c->get( ProductService::class ) )
		);

		$container->singleton(
			ProductScreenshots::class,
			static fn ( Container $c ): ProductScreenshots => new ProductScreenshots( $c->get( ProductService::class ) )
		);

		$container->singleton(
			ProductMeta::class,
			static fn ( Container $c ): ProductMeta => new ProductMeta(
				$c->get( ProductService::class ),
				$c->get( TermService::class ),
				$c->get( PageResolver::class ),
				$c->get( ReleaseRepository::class )
			)
		);

		$container->singleton(
			ProductChangelog::class,
			static fn ( Container $c ): ProductChangelog => new ProductChangelog(
				$c->get( ProductService::class ),
				$c->get( ReleaseRepository::class ),
				$c->get( ChangelogRenderer::class )
			)
		);

		$container->singleton(
			PricingTable::class,
			static fn ( Container $c ): PricingTable => new PricingTable(
				$c->get( ProductService::class ),
				$c->get( Money::class ),
				self::automaticDiscountResolver( $c ),
				self::discountSettings( $c )
			)
		);

		$container->singleton(
			CartSummary::class,
			static fn ( Container $c ): CartSummary => new CartSummary(
				$c->get( CartService::class ),
				$c->get( ProductService::class ),
				$c->get( Money::class ),
				// Price a stored discount code when the optional engine is available.
				$c->has( \DrPlugin\WP\TierMint\Discounts\DiscountService::class )
					? $c->get( \DrPlugin\WP\TierMint\Discounts\DiscountService::class )
					: null,
				self::discountComposer( $c )
			)
		);

		$container->singleton(
			LoginForm::class,
			static fn ( Container $c ): LoginForm => new LoginForm( $c->get( PageResolver::class ) )
		);

		$container->singleton(
			RegistrationForm::class,
			static fn ( Container $c ): RegistrationForm => new RegistrationForm( $c->get( PageResolver::class ) )
		);

		$container->singleton(
			PasswordResetForm::class,
			static fn ( Container $c ): PasswordResetForm => new PasswordResetForm(
				$c->get( PageResolver::class ),
				$c->get( PasswordResetCookie::class )
			)
		);

		$container->singleton(
			AccountDashboard::class,
			static fn ( Container $c ): AccountDashboard => new AccountDashboard(
				$c->get( ComponentRegistry::class ),
				$c->get( AttributeResolver::class ),
				$c->get( OrderService::class ),
				$c->get( Money::class )
			)
		);

		$container->singleton(
			PurchaseConfirmation::class,
			static fn ( Container $c ): PurchaseConfirmation => new PurchaseConfirmation(
				$c->get( OrderService::class ),
				$c->get( LicenseService::class ),
				$c->get( CustomerDownloads::class ),
				$c->get( CustomerService::class ),
				$c->get( Money::class )
			)
		);

		$container->singleton(
			DownloadsList::class,
			static fn ( Container $c ): DownloadsList => new DownloadsList(
				$c->get( CustomerDownloads::class )
			)
		);

		$container->singleton(
			LicenseManager::class,
			static fn ( Container $c ): LicenseManager => new LicenseManager(
				$c->get( ProductService::class ),
				$c->get( ActivationService::class )
			)
		);

		$container->singleton(
			Checkout::class,
			static fn ( Container $c ): Checkout => new Checkout(
				$c->get( CartService::class ),
				$c->get( ProductService::class ),
				$c->get( LicenseService::class ),
				$c->get( GatewayRegistry::class ),
				$c->get( Money::class ),
				$c->get( CheckoutFieldSettings::class ),
				$c->get( PageResolver::class ),
				$c->get( LoginForm::class ),
				// Price a stored code when the optional discount engine is available.
				$c->has( \DrPlugin\WP\TierMint\Discounts\DiscountService::class )
					? $c->get( \DrPlugin\WP\TierMint\Discounts\DiscountService::class )
					: null,
				self::discountComposer( $c )
			)
		);
	}

	/**
	 * Boot the frontend Hookables.
	 *
	 * @param Container $container The plugin container.
	 * @return void
	 */
	public function boot( Container $container ): void {
		$container->get( AssetManager::class )->hooks();
		$container->get( AppearanceTokens::class )->hooks();
		$container->get( Registrar::class )->hooks();
		$container->get( BlocksRegistrar::class )->hooks();
		$container->get( ElementorRegistrar::class )->hooks();
		$container->get( Patterns::class )->hooks();
		$container->get( CartActionListener::class )->hooks();
		$container->get( RegistrationActionListener::class )->hooks();
		$container->get( PasswordResetActionListener::class )->hooks();
		$container->get( PasswordResetLinks::class )->hooks();
		$container->get( RenewalUrlProvider::class )->hooks();
		$container->get( AdminBarController::class )->hooks();
		$container->get( LoginRedirector::class )->hooks();
		$container->get( ProductPageTitle::class )->hooks();
		$container->get( ProductRewrites::class )->hooks();
		$container->get( ProductRequestContext::class )->hooks();
		$container->get( ProductHeadTags::class )->hooks();
	}

	/**
	 * Bind the Elementor adapter services.
	 *
	 * @param Container $container The plugin container.
	 * @return void
	 */
	private function registerElementorAdapter( Container $container ): void {
		$container->singleton(
			ElementorSettings::class,
			static fn (): ElementorSettings => new ElementorSettings()
		);

		$container->singleton(
			ControlMap::class,
			static fn (): ControlMap => new ControlMap()
		);

		$container->singleton(
			StyleControlMap::class,
			static fn (): StyleControlMap => new StyleControlMap()
		);

		$container->singleton(
			ControlOptions::class,
			static fn ( Container $c ): ControlOptions => new ControlOptions(
				$c->get( ProductService::class ),
				$c->get( TermService::class )
			)
		);

		$container->singleton(
			WidgetRenderer::class,
			static fn ( Container $c ): WidgetRenderer => new WidgetRenderer(
				$c->get( ComponentRegistry::class ),
				$c->get( AttributeResolver::class ),
				$c->get( AssetManager::class ),
				$c->get( RenderContextResolver::class ),
				$c->get( ControlMap::class ),
				$c->get( StyleControlMap::class ),
				$c->get( ControlOptions::class )
			)
		);

		$container->singleton(
			TagValues::class,
			static fn ( Container $c ): TagValues => new TagValues(
				$c->get( ProductService::class ),
				$c->get( Money::class )
			)
		);

		$container->singleton(
			ElementorRegistrar::class,
			static fn ( Container $c ): ElementorRegistrar => new ElementorRegistrar(
				$c->get( ElementorSettings::class ),
				$c->get( WidgetRenderer::class ),
				$c->get( ComponentRegistry::class ),
				$c->get( AssetManager::class ),
				$c->get( TagValues::class )
			)
		);
	}

	/**
	 * Get the automatic-discount resolver, if the Discounts domain is registered.
	 *
	 * @param Container $c The plugin container.
	 * @return \DrPlugin\WP\TierMint\Discounts\AutomaticDiscountResolver|null
	 */
	private static function automaticDiscountResolver( Container $c ): ?\DrPlugin\WP\TierMint\Discounts\AutomaticDiscountResolver {
		return $c->has( \DrPlugin\WP\TierMint\Discounts\AutomaticDiscountResolver::class )
			? $c->get( \DrPlugin\WP\TierMint\Discounts\AutomaticDiscountResolver::class )
			: null;
	}

	/**
	 * Get the discount display settings, if the Discounts domain is registered.
	 *
	 * @param Container $c The plugin container.
	 * @return \DrPlugin\WP\TierMint\Discounts\DiscountSettings|null
	 */
	private static function discountSettings( Container $c ): ?\DrPlugin\WP\TierMint\Discounts\DiscountSettings {
		return $c->has( \DrPlugin\WP\TierMint\Discounts\DiscountSettings::class )
			? $c->get( \DrPlugin\WP\TierMint\Discounts\DiscountSettings::class )
			: null;
	}

	/**
	 * Get the shared discount composer, if the Discounts domain is registered.
	 *
	 * @param Container $c The plugin container.
	 * @return \DrPlugin\WP\TierMint\Discounts\DiscountComposer|null
	 */
	private static function discountComposer( Container $c ): ?\DrPlugin\WP\TierMint\Discounts\DiscountComposer {
		return $c->has( \DrPlugin\WP\TierMint\Discounts\DiscountComposer::class )
			? $c->get( \DrPlugin\WP\TierMint\Discounts\DiscountComposer::class )
			: null;
	}
}
