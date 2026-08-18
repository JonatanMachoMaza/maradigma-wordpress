=== Maradigma ===
Contributors: maradigma
Tags: boat rental, yacht charter, booking, availability, fleet management
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.168
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WordPress with Maradigma to sync boats, build fleet pages, display availability and prices, and accept online bookings.

== Description ==

Maradigma connects WordPress with your Maradigma account so you can publish and manage your boat catalog directly from your website.

The plugin is designed for boat charter websites that need synchronized fleet data, editable boat pages, searchable listings, availability and pricing information, and an integrated booking flow.

Main capabilities:

* Connect WordPress to Maradigma using the API credentials provided with your account.
* Synchronize boats, boat data, media, and supported multilingual content.
* Create and update boat pages through a dedicated custom post type.
* Display responsive boat listings with filters, sorting, pagination, and editable boat cards.
* Render individual boat content through shortcodes, PHP templates, Gutenberg blocks, Elementor widgets, or WPBakery elements.
* Display galleries, videos, specifications, descriptions, equipment, included and excluded items, additional services, prices, PDF files, and availability calendars.
* Let visitors select dates, configure a booking, provide their contact details, choose an available payment method, and submit the booking to Maradigma.
* Build and propagate editable master layouts for synchronized boat pages.
* Link WooCommerce products to Maradigma boats when WooCommerce is installed and active.
* Provide compatibility layers for GeneratePress, GenerateBlocks, and Gutenverse where those products are active.
* Support multilingual sites using WPML or Polylang.
* Integrate synchronized boat metadata with Yoast SEO when Yoast SEO is installed and active.

An active Maradigma account and valid API credentials are required to retrieve fleet data and use the connected booking features.

= External service =

This plugin connects to the Maradigma platform to retrieve boat data, media, availability, prices, additional services, payment-method information, and booking-related content.

The connection is initiated after a website administrator configures the plugin with valid Maradigma credentials. Additional requests are made when an administrator synchronizes fleet content or when a visitor uses a connected catalog, availability, pricing, or booking feature.

Depending on the feature being used, requests may include:

* The website domain.
* The Maradigma API public key.
* Request signatures generated with the configured secret key.
* Boat identifiers, language codes, dates, filters, and catalog query parameters.
* Booking selections and contact details entered by the visitor, such as name, email address, telephone number, country, and other fields displayed in the booking form.

These data are used to return the requested catalog information, calculate availability and prices, and create or process the requested booking in the connected Maradigma account.

The plugin may also display or download boat images, videos, and PDF files from URLs returned by Maradigma. Frontend JavaScript and CSS dependencies bundled with the plugin are loaded locally rather than from public CDNs.

Service provider: Maradigma / BCH MULTISERVICES S.L.

Service API endpoint: https://app.maradigma.com/api/external/v1

Provider website: https://maradigma.com/

Provider privacy policy: https://maradigma.com/en/privacy-policy

Website administrators should ensure that their own privacy policy explains the use of this external service and the booking data collected through their website where required.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install the plugin through the WordPress administration area.
2. Activate Maradigma.
3. Go to **Maradigma > Settings**.
4. Enter the API connection details supplied with your Maradigma account.
5. Save the settings and verify the connection.
6. Configure the boat-page synchronization options and synchronize your fleet.
7. Add the provided shortcodes to your pages, or build boat layouts using Gutenberg, Elementor, or WPBakery.

== Frequently Asked Questions ==

= Do I need a Maradigma account? =

Yes. An active Maradigma account and valid API credentials are required for the connected catalog, availability, pricing, and booking features.

= Does the plugin create boat pages in WordPress? =

Yes. The plugin registers a dedicated boat post type and can create or update synchronized boat pages. Administrators can choose how generated layouts and boat data are updated.

= Can I display a boat listing on any page? =

Yes. Add the fleet listing shortcode to a page, post, or compatible builder element:

`[maradigma_boats]`

The shortcode supports filters, sorting, pagination, configurable filter placement, and editable boat-card templates. A complete shortcode reference is included in `docs/MARADIGMA_SHORTCODES.md`.

= Can I display a single boat inside a page template or builder layout? =

Yes. The plugin provides a full single-boat shortcode and field-specific shortcodes.

Examples:

`[maradigma_boat slug="my-boat-slug"]`

`[maradigma_boat_title]`

`[maradigma_boat_booking]`

= Which page builders are supported? =

The plugin provides native Gutenberg blocks, dedicated Elementor widgets, and WPBakery elements for fleet listings and individual boat content. It also provides editable master layouts that can be propagated to synchronized boat pages.

= Does the plugin integrate with WooCommerce? =

Yes, when WooCommerce is installed and active. Public WooCommerce products can be linked to Maradigma boats and used as boat-detail pages. The integration does not replace WooCommerce checkout and does not modify WooCommerce system pages such as Cart, Checkout, or My Account.

= Does it support multilingual websites? =

Yes. The plugin includes compatibility layers for WPML and Polylang and can synchronize language-specific boat pages and metadata according to the active multilingual configuration.

= Does it work with Yoast SEO? =

When Yoast SEO is installed and active, the plugin can synchronize or generate boat metadata according to the configured SEO options.

= Can synchronized boat URLs include their destination? =

Yes. The boat base slug setting accepts the `{{destination}}` placeholder. For example, `es:alquiler-barcos-{{destination}},en:boat-rental-{{destination}}` generates localized paths such as `/alquiler-barcos-mallorca/boat-slug/`. The destination is synchronized from the connected Maradigma account. Re-synchronize existing boats after enabling this route format.

= Does the plugin load frontend libraries from public CDNs? =

No. The supported frontend libraries distributed with the plugin are bundled locally. Boat media returned by the connected Maradigma account may still be displayed from Maradigma-provided URLs.

== Third-party libraries ==

This plugin bundles local copies of third-party frontend libraries instead of loading JavaScript or CSS from public CDNs in production.

See `docs/THIRD-PARTY-NOTICES.md` for library names, versions, licenses, and source URLs.

== Source code and build ==

The complete human-readable source code and build tooling are publicly maintained at:

https://github.com/JonatanMachoMaza/maradigma-wordpress

JavaScript and CSS sources are located in `assets/js` and `assets/css`. Compiled production assets are written to `assets/dist`.

To reproduce the distributed assets from a clean checkout:

1. Run `npm ci`.
2. Run `npm run build`.

The repository includes `package.json`, `package-lock.json`, and the Vite configuration used for the build.

== Screenshots ==

1. Maradigma connection and configuration settings.
2. Boat listing displayed on the public website.
3. Individual boat page with synchronized content.
4. Elementor widgets for Maradigma boat layouts.
5. Gutenberg blocks and editable boat master template.
6. Boat synchronization workflow and status.

== Changelog ==

= 0.1.168 =
* Updated the bundled Select2 library to the stable 4.1.0 release.
* Sanitized dynamic Gutenberg block output and legacy page-listing shortcode output with an explicit frontend HTML allowlist.
* Restricted pricing diagnostics and cache bypasses to the server-side `MARADIGMA_PLUGIN_DEBUG` constant.
* Removed direct writes to Elementor, GenerateBlocks, and Gutenverse-owned options.
* Simplified the GeneratePress template nonce flow and retained capability checks.

= 0.1.167 =
* Fixed excessive vertical space around archive filters on mobile layouts.
* Included compiled runtime translation catalogs in release packages while keeping PO source files excluded.
* Added localized date-range placeholder and clear-button labels.

= 0.1.166 =
* Added destination-aware boat permalinks through the `{{destination}}` route placeholder.
* Synchronized localized service destinations and exposed destination tokens to boat cards and SEO templates.
* Added matching rewrite rules, safe fallback routes, and WooCommerce-compatible route sanitization.
* Added `service_destination` to the supported single-boat API expansions.

= 0.1.165 =
* Added complete GPL, MIT, Apache-2.0, and ODbL license texts to release packages.
* Documented Tom Select, its OrchidJS dependencies, and the bundled geographic dataset.
* Fixed damaged UTF-8 text in the boat-card and Elementor administration scripts.
* Restricted payment return URLs to destinations approved by WordPress.
* Replaced HEREDOC/NOWDOC syntax and excluded unused legacy Select2 translations from releases.
* Clarified repository security reporting and licensing.

= 0.1.164 =
* Hardened boat-card template storage and shortcode output escaping.
* Replaced inline admin assets with WordPress-enqueued files.
* Updated Select2 to the stable 4.0.13 release.
* Removed dynamic gettext calls and improved failed-nonce responses.
* Documented the public source repository and reproducible asset build.
* Excluded repository translation binaries from WordPress.org release packages.

= 0.1.163 =
* Updated the Plugin URI to point to the dedicated Maradigma WordPress plugin page.
* Kept the Author URI separate so WordPress.org can distinguish plugin information from author information.

= 0.1.162 =
* Localized the plugin description for Catalan, German, British English, Spanish, French, Italian, and Dutch WordPress installations.
* Aligned the plugin header and WordPress.org short description across all supported languages.
* Improved WordPress.org discovery tags to focus on boat rental, yacht charter, booking, availability, and fleet management.
* Expanded and clarified the public documentation for integrations, external services, privacy, and supported workflows.

= 0.1.161 =
* Added native Elementor Style controls for boat equipment, description, gallery, included items, and additional services widgets.
* Preserved existing gallery spacing and radius defaults while exposing them through Elementor controls.
* Added a stable description wrapper for scoped typography, spacing, background, and border controls.
* Improved WordPress.org Plugin Check compatibility for multilingual boat synchronization.
* Normalized the single-boat template source formatting.
* Aligned the WordPress.org readme with the plugin's current version and supported integrations.

= 0.1.44 =
* Bundled frontend dependencies locally instead of loading Swiper, Flatpickr, noUiSlider, or jQuery UI theme CSS from public CDNs.
* Added third-party library notices for bundled frontend dependencies.
* Updated WordPress.org readme metadata and external service disclosure.
* Documented current Gutenberg block support.

= 0.1.41 =
* Updated plugin metadata for WordPress.org readiness.
* Added a WordPress.org-compatible license declaration.
* Aligned the stable tag with the plugin version.
* Improved readme documentation for the current feature set.

== Upgrade Notice ==

= 0.1.168 =
Security and compatibility hardening requested during the WordPress.org plugin review, including Select2 4.1.0.

= 0.1.167 =
Fixes mobile archive-filter sizing and restores bundled frontend translations in direct plugin installations.

= 0.1.166 =
Adds localized destination-aware URLs for synchronized boat pages. Re-save permalinks and re-synchronize existing boats when enabling `{{destination}}`.

= 0.1.165 =
Licensing, text-encoding, payment-return security, and release-package hardening for public distribution.

= 0.1.164 =
Security, compatibility, source-code documentation, and WordPress.org review-readiness update.

= 0.1.163 =
Plugin and author metadata now use distinct URLs, resolving the WordPress.org directory validation warning.

= 0.1.162 =
Plugin metadata and public documentation are now aligned, with localized descriptions for every language distributed with Maradigma.

= 0.1.161 =
Boat content widgets now provide native Elementor styling controls, and the public plugin documentation reflects the current integrations and external-service behavior.

= 0.1.44 =
Frontend dependencies are now bundled locally and the public readme metadata has been updated for the current release.

= 0.1.41 =
Metadata and directory-readiness update for the first public release workflow.
