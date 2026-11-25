# Rockfire – REST API Cache

Fast, security-hardened caching for the WordPress REST API, with tag-based invalidation, a route manager and
WP-CLI commands.

[![CI](https://github.com/richardkorthuis/rockfire-rest-api-cache/actions/workflows/ci.yml/badge.svg)](https://github.com/richardkorthuis/rockfire-rest-api-cache/actions/workflows/ci.yml)

The plugin stores the JSON responses of the WordPress REST API and serves repeated anonymous `GET` requests from the
cache, before most of WordPress has loaded. Cached responses are flushed when the content they contain changes, so
headless front ends, apps and decoupled sites get fast responses without stale data.

- **Install it** from the WordPress.org plugin directory: <https://wordpress.org/plugins/rockfire-rest-api-cache/>
- **Documentation**, including the hooks reference and the migration guide from WP REST Cache: <https://rockfire.nl/docs/>
- **Features, FAQ and changelog**: [`readme.txt`](readme.txt)

## Highlights

- **Early serving** through a small must-use file, with a fallback mode when that file cannot be written.
- **Route manager** to enable, exclude and tune any registered route.
- **Tag-based invalidation**: responses are flushed when the posts, terms, users or comments they contain change.
- **Public PHP API**: `rfrac_flush_tag()`, `rfrac_flush_url()`, `rfrac_flush_route()`, `rfrac_flush_all()` and
  `rfrac_tag_response()`.
- **WP-CLI**: `wp rfrac flush`, `list`, `status`, `prune` and `config get`.
- **Multisite** and **WooCommerce Store API** support.
- Never caches authenticated requests, nonces, credentials or cookies that identify a visitor.

## Requirements

- WordPress 6.6 or later
- PHP 8.1 or later

## Development

The repository contains everything needed to lint, test and build the plugin:

```sh
composer install
composer lint      # PHPCS, PHPStan, compliance, readme and POT checks
composer test      # PHPUnit, single site (after composer install-wp-tests, see CONTRIBUTING.md)
composer build     # build/rockfire-rest-api-cache.zip
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for the full setup and how to send a pull request.

## Security

Please do not report security issues in a public issue: see [SECURITY.md](SECURITY.md).

## License

GPLv3, see [LICENSE.txt](LICENSE.txt). Rockfire – REST API Cache is a fork of
[WP REST Cache](https://wordpress.org/plugins/wp-rest-cache/) by Acato.
