# Contributing

## Development setup

Install the plugin in `wp-content/plugins/wp-plugin-maradigma`, then install the
development dependencies:

```bash
composer install
npm ci
```

Build the frontend assets:

```bash
npm run build
```

Run the PHP test suite and static analysis:

```bash
composer quality
```

## Pull requests

- Keep changes focused and compatible with WordPress 6.0 and PHP 8.1 or later.
- Do not commit credentials, logs, database exports, customer data, or local paths.
- Update source assets and their compiled counterparts when frontend behavior changes.
- Add or update focused tests when behavior, synchronization, routing, or packaging changes.
- Confirm `npm audit`, `composer audit`, `npm run build`, and `composer quality` pass.

## Release packages

Generate the WordPress.org package with:

```bash
php bin/build-release.php
```

The generated ZIP intentionally excludes development dependencies, tests, internal
tooling, translation source files, logs, and local artifacts.
