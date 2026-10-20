# Maradigma for WordPress

WordPress plugin for Maradigma that synchronizes boat data, builds fleet pages, displays availability, and enables quote and booking flows.

## Features

- Frontend boat listings and cards through shortcodes and page-builder integrations
- REST endpoints for availability, pricing, quotes, and bookings
- Gutenberg, Elementor, WPBakery, and WooCommerce integrations
- Multilingual boat-page synchronization
- Locally bundled frontend assets

## Portfolio catalogues

Use a Maradigma boat portfolio in an existing listing:

    [maradigma_boats portfolio="12345678-1234-4321-8123-000000000012" limit_services="12" show_filters="1"]

`portfolio` accepts only a portfolio UUID. In Elementor and Gutenberg, select a
portfolio in the boat archive's listing controls. WPBakery provides a Portfolio
UUID field. Choices belong to the tenant configured in the existing API
settings; no additional credentials are required.

Numeric portfolio IDs are rejected, including previously saved numeric selections
and cached results. Replace those selections with the portfolio UUID. Portfolios
without a UUID are excluded from the choices. The UUID does not grant access:
the external API authorizes it for the authenticated tenant, which cannot be
overridden by a shortcode or request parameter.

The plugin reads all membership pages and resolves them through the published
boat search. Dates, availability, prices, language, card templates and other
filters keep their existing behavior. The default order follows the portfolio;
an explicit price, length or other order uses the search order. Specific boat IDs
and the visitor's boat selection further narrow the portfolio. Empty, removed,
foreign or invalid portfolios never fall back to the full fleet.

Portfolio PDF/booking flags are metadata and do not override each boat's booking
eligibility. This integration displays boat catalogues; it does not generate
portfolio PDFs or synchronize a separate set of WordPress boat pages. Cold
requests may scan the whole filtered fleet because the search API has no ID
filter; normal catalogue caching applies when enabled.

## Requirements

- WordPress 6.0 or later
- PHP 8.1 or later

## Source code

The canonical public source repository is:

https://github.com/JonatanMachoMaza/maradigma-wordpress

WordPress.org release packages are generated from this source with
`php bin/build-release.php`.

## Development

Install this repository inside a WordPress installation at wp-content/plugins/wp-plugin-maradigma/.

Install the development dependencies and run the quality checks with:

    composer install
    composer quality
    npm install
    npm run build

- composer test runs the PHPUnit unit suite.
- composer analyse runs PHPStan over the plugin core and tests.
- rtk test composer quality runs the PHP checks with compact failure output.

## Security

Do not commit API keys, secret keys, credentials, logs, database exports, or customer data. Report security issues privately as described in [SECURITY.md](SECURITY.md) instead of opening a public issue.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for the development and validation workflow.

## Third-party components

Bundled libraries and datasets are documented in docs/THIRD-PARTY-NOTICES.md; their complete license texts are kept in licenses/.

## License

Copyright (c) 2025-2026 Maradigma.

This plugin is licensed under GPL-2.0-or-later. See LICENSE.
