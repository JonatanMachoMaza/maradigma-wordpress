# Maradigma for WordPress

WordPress plugin for Maradigma that synchronizes boat data, builds fleet pages, displays availability, and enables quote and booking flows.

## Features

- Frontend boat listings and cards through shortcodes and page-builder integrations
- REST endpoints for availability, pricing, quotes, and bookings
- Gutenberg, Elementor, WPBakery, and WooCommerce integrations
- Multilingual boat-page synchronization
- Locally bundled frontend assets

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
