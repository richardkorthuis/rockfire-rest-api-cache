# Contributing

Thanks for helping improve Rockfire – REST API Cache! Bug reports, fixes and improvements are all welcome.

## Reporting a bug

Open an issue with:

- the WordPress, PHP and plugin versions, and whether the site is a multisite or runs WooCommerce;
- the request (route and query parameters) and what you expected versus what you got;
- the `X-Rfrac-Cache` response header (`HIT`, `MISS` or `BYPASS`) and, if relevant, the output of `wp rfrac status`.

Never report security issues in a public issue: see [SECURITY.md](SECURITY.md).

## Proposing a change

For anything larger than a small fix, open an issue first so we can agree on the approach before you spend time on
it. Then:

1. Fork the repository and create a branch from `main`.
2. Make your change, with tests.
3. Run `composer lint` and the test suites below.
4. Open a pull request against `main` that explains what changes and why.

Pull requests are squash-merged, so the title of the pull request becomes the commit message. Use the
[Conventional Commits](https://www.conventionalcommits.org/) style, for example `fix(admin): keep the filter after a bulk flush`
or `feat(cli): add --dry-run to wp rfrac prune`.

CI runs the linters and the PHPUnit suites (single site, multisite and WooCommerce) on every pull request.

## Development setup

You need PHP 8.1 or later with the `mysqli` and `zip` extensions, Composer, Git, `svn` (used by the test installer)
and a MySQL or MariaDB server.

```sh
composer install
```

### Linting

```sh
composer lint
```

This runs PHPCS (WordPress Coding Standards, see `phpcs.xml.dist`), PHPStan (`phpstan.neon`), the project's compliance
checks (`bin/check-compliance.php`), the readme check and the POT check. Fix PHPCS issues automatically where possible
with `composer phpcbf`. When you add or change a translatable string, regenerate the POT file with
`composer make-pot`.

### Tests

Install the WordPress test suite once per suite. The arguments are the database name, user, password and host; the
installer creates the database.

```sh
# Single site
composer install-wp-tests -- wordpress_test root root 127.0.0.1
composer test

# Multisite
composer install-wp-tests-multisite -- wordpress_test_ms root root 127.0.0.1
composer test-multisite

# WooCommerce (the last argument is the WooCommerce version)
composer install-wp-tests-woocommerce -- wordpress_test_wc root root 127.0.0.1 latest false 11.3.1
composer test-woocommerce
composer test-woocommerce-hpos
composer test-woocommerce-admin
```

`composer coverage` writes an HTML coverage report of the single-site and multisite suites to `coverage/html`.

### Building the zip

```sh
composer build
```

This writes `build/rockfire-rest-api-cache.zip` with the files git tracks, minus the paths in `.distignore`.

## Code guidelines

- Follow the WordPress Coding Standards; `composer lint` must pass without new suppressions. When a `phpcs:ignore` is
  unavoidable, name the sniff and give the reason on the same line.
- Prefix everything global: functions, options, hooks, constants and tables use `rfrac` / `RFRAC_`, and classes live in
  the `Rockfire\Rest_Api_Cache` namespace. The autoloader maps `class-*.php`, `trait-*.php` and `interface-*.php` files
  below `includes/` and `admin/`.
- Every PHP file starts with the `defined( 'ABSPATH' ) || exit;` guard.
- Use the text domain `rockfire-rest-api-cache` for translatable strings.
- Sanitise input where it is read and escape output where it is printed. Admin actions check a nonce and a capability.
- Never cache a response for an authenticated request, and never make outbound HTTP requests.
- A new hook belongs in `includes/class-hooks.php`, with its parameters documented.
- Update `readme.txt` (and its changelog) when behaviour visible to site owners changes.

## License

By contributing, you agree that your contributions are licensed under the GPLv3, the license of this project.
