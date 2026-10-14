=== TierMint: Digital Downloads and Software Licensing ===
Contributors: drplugin
Tags: digital downloads, sell digital products, software licensing, license keys, stripe
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell plugins and digital downloads with Stripe checkout, license keys and an update server. TierMint takes no commission on your sales.

== Description ==

TierMint is a free WordPress plugin for selling plugins, themes and other digital products from your own site. It takes card payments through Stripe, issues a license key with each sale and runs an update server, so the plugins you sell get their updates on your customers' Plugins screen.

TierMint takes no commission and needs no license key of its own. Payments go to your own Stripe account.

= License keys for the software you sell =

Each pricing tier sets an activation limit and a license length, from a number of days to lifetime. When an order completes, TierMint issues a key for each licensed product in it. Customers activate and deactivate their sites from their account page. Local and staging sites, such as `localhost` or `staging.example.com`, do not count toward the activation limit.

Your plugin talks to the store through a public REST API to activate, validate and deactivate a key.

= Updates for licensed sites =

Upload a release with its version number and changelog. Sites with a valid license see it on their Plugins screen, read the changelog under View details and update like any other plugin. Every download goes through a signed link that expires, and the license key never appears in it.

Add the free [update client](https://drplugin.com/docs/tiermint/licensing/update-client) to the plugin you sell to connect it to your store.

= Store features =

* Products with pricing tiers, categories, tags and bundles
* Card checkout with Stripe Elements, so card details never reach your server
* Guest checkout
* Discount codes, percent or fixed, for all or chosen products, with a minimum order total, use limits and a date window
* Automatic sales that need no code, with the old price struck through in the catalog
* Full and partial refunds
* A receipt email with the license keys and download links
* A customer account page with Purchases, Downloads and Licenses tabs
* Sales reports with gross, refunds and net by day, and CSV export
* Signed webhooks for orders, refunds, licenses and new customers

= A storefront that fits your theme =

Activation creates the Store, Product, Checkout, Account, Sign in and Reset password pages, plus Register when registration is open. You can swap any of them for your own page.

The storefront comes as 20 blocks, each with a matching shortcode, and five block patterns for pricing, product, checkout and account pages. The blocks work in block themes and classic themes. Eight Elementor widgets work with the free Elementor plugin. Set colors, corner radius and font in the settings, or override the `--tiermint-*` CSS custom properties.

= Documentation =

* [Install TierMint](https://drplugin.com/docs/tiermint/getting-started/installation)
* [License keys and activations](https://drplugin.com/docs/tiermint/licensing/licensing)
* [Update client for your plugins](https://drplugin.com/docs/tiermint/licensing/update-client)
* [Licensing REST API](https://drplugin.com/docs/tiermint/developers/licensing-api)
* [All TierMint documentation](https://drplugin.com/docs/tiermint)

= TierMint Pro =

[TierMint Pro](https://drplugin.com/plugins/tiermint) is a separate plugin that adds to this one, so both stay active. It adds subscriptions through Stripe and PayPal, plan changes for Stripe subscribers, failed payment reminders, abandoned cart emails, EU and UK VAT on one-time sales, PDF invoices, signed license answers, offline and team licenses, release channels, an analytics dashboard, an affiliate program and imports from other stores.

= External services =

TierMint connects to Stripe, and to webhook endpoints you add yourself. It makes no other outbound requests and sends no telemetry.

**Stripe (payments).** When you connect a Stripe account, your server calls the Stripe API at api.stripe.com to create and refund payments. It sends the amount, the currency and TierMint's order identifiers. On checkout pages the buyer's browser loads Stripe.js from js.stripe.com, so Stripe collects the card details directly. Both happen only after you add Stripe keys, and only during checkout or a refund. See the [Stripe terms of service](https://stripe.com/legal) and [privacy policy](https://stripe.com/privacy).

**Your webhook endpoints (optional).** If you add endpoints under TierMint > Settings > Webhooks, TierMint sends signed JSON notifications about orders, refunds, licenses and new customers to those URLs. They include customer email addresses and full license keys. Nothing is sent until you add an endpoint.

= Source code =

The JavaScript in `assets/admin/build` and `assets/blocks/build` is compiled with `@wordpress/scripts`. The source and build setup are at [github.com/drplugin-projects/tiermint](https://github.com/drplugin-projects/tiermint). To rebuild, run `npm install`, then `npm run build`.

== Installation ==

1. In WordPress, go to Plugins > Add New Plugin, search for "TierMint", then install and activate it.
2. Open TierMint > Settings > General and set your store currency before you add products. Prices are not converted if you change it later.
3. In your Stripe Dashboard, add a webhook endpoint at `https://your-site/wp-json/tiermint/v1/webhooks/stripe` with the events `payment_intent.succeeded`, `payment_intent.payment_failed` and `charge.refunded`. Then open TierMint > Settings > Payments, add your Stripe keys and the endpoint's signing secret, and leave Stripe mode on Test.
4. Check the storefront pages under TierMint > Settings > General. Activation created them, and you can pick your own pages instead.
5. Add a product with at least one pricing tier. Turn on Licensed product if you sell software.
6. Buy it with a Stripe test card, then check that the receipt email and the license key arrive.

You need WordPress 6.6 or newer, PHP 8.1 or newer, and HTTPS for live card payments.

== Frequently Asked Questions ==

= Does TierMint charge a commission on sales? =

No. Payments go to your own Stripe account, and you pay only Stripe's own fees. The plugin needs no license key of its own.

= How do my customers get updates for the plugins I sell? =

Add the TierMint update client to each plugin you sell. It asks your store for new versions, and WordPress shows them on the Plugins screen like any other update. A site with an expired or unknown license still sees the new version, but gets no download link. The client works for plugins, not themes.

= Can I sell themes, ebooks or templates? =

Yes. Leave Licensed product off for files that need no key. A theme can carry a license key, but the update client delivers plugin updates only.

= Do local and staging sites count toward the activation limit? =

No. `localhost`, hosts that end in `.test`, `.local` or `.dev`, hosts that start with `staging.` or `dev.`, and private or reserved IP addresses never count. They still show in the license's activations.

= What happens to updates if my store goes down? =

The update client keeps using its last good answer for up to 72 hours. After that it shows no update until your store answers again. The customer's site keeps working.

= Do I need a Stripe account? =

Yes, for card payments. Card details go from the buyer's browser to Stripe and never reach your server. Stripe handles 3D Secure. TierMint Pro adds PayPal.

= Can buyers check out as guests? =

Yes, it is on by default. When a guest later registers or signs in with the same email, TierMint links their orders and licenses to the account.

= Can I move my store from Easy Digital Downloads? =

Yes, with TierMint Pro. It imports products, customers, orders, license keys and activations from Easy Digital Downloads 3.x, License Manager for WooCommerce and SureCart. Subscriptions, discount codes and download files are not imported.

= Does TierMint advertise TierMint Pro? =

Only on its own admin screens, and only while Pro is not active: a TierMint Pro menu item and the screen it opens, a TierMint Pro tab under Settings, and a one-line note you can dismiss. Nothing shows on other admin screens or on your site.

= What happens to my data when I delete TierMint? =

TierMint keeps it unless you turn on Delete all data on uninstall under TierMint > Settings > General before you delete the plugin. That setting removes its tables, options, capabilities, package files and the pages it created. On multisite, each site's own setting decides.

= How does TierMint handle personal data? =

Its data is in the WordPress Export Personal Data and Erase Personal Data tools. Erasing anonymizes the customer and their orders, which stay for your accounts, and disables their licenses.

= Does TierMint have a REST API? =

Yes, under `tiermint/v1`. The licensing routes are public and take the license key. The management routes need a capability and accept a session, an Application Password or a TierMint API key.

== Screenshots ==

1. The Products screen with search, status, category and tag filters.
2. Pricing tiers in the product editor, with price, activation limit and license length.
3. A pricing table on the storefront.
4. Checkout with a discount code and the Stripe card fields.
5. The customer account page with Purchases, Downloads and Licenses tabs.
6. The Licenses screen with masked keys and activations used.
7. The Orders screen with statuses and a partly refunded order.
8. Discount codes with uses and date windows.
9. The sales report with gross, refunds and net by day.
10. Stripe settings in test mode, with saved secrets masked.

== Changelog ==

= 1.0.0 =
* First release.
