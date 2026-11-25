=== Rockfire – REST API Cache ===
Contributors: rockfire
Tags: cache, rest api, performance, headless, api
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Fast, security-hardened caching for the WordPress REST API, with tag-based invalidation, a route manager and WP-CLI commands.

== Description ==

Rockfire – REST API Cache stores the JSON responses of the WordPress REST API and serves repeated anonymous `GET` requests from the cache, before most of WordPress has loaded. Cached responses are flushed when the content they contain changes, so headless front ends, apps and decoupled sites get fast responses without stale data.

The full documentation, with a getting-started guide, every hook and the migration from WP REST Cache, is at [rockfire.nl/docs](https://rockfire.nl/docs/).

**Features**

* **Early serving.** A small must-use file serves cache hits before regular plugins and the theme load. Without it the plugin still works in fallback mode and serves hits from `plugins_loaded`.
* **Route manager.** Every readable `wp/v2` route and the oEmbed route are cached by default. Under REST API Cache → Routes you enable or exclude any registered route (or a wildcard family of routes) and set its TTL, accepted parameters, ignored and bypass parameters, vary headers, and the post types and taxonomies whose changes flush it. Routes that only have `POST`, `PUT` or `PATCH` handlers are listed too, and a route can opt in to caching the `POST`, `PUT` or `PATCH` methods it registers (never on `wp/v2`).
* **Tag-based invalidation.** Every cached response is tagged with the posts, terms, users, comments and lists it contains. Saving, trashing or deleting content, and changing terms, REST-exposed meta, users, comments or relevant options flushes the responses carrying those tags. A post response only refers to its terms, so adding another post to one of its categories or removing one from it, and renaming or describing a category, flush the category's own responses but not the other posts in it. A post response is flushed when its own terms change, or when one of its terms is deleted or gets a new slug or parent.
* **Public PHP API.** Flush by tag, URL, route or everything, and add your own tags to responses: `rfrac_flush_tag()`, `rfrac_flush_url()`, `rfrac_flush_route()`, `rfrac_flush_all()` and `rfrac_tag_response()`.
* **Pruning and limits.** An hourly maintenance run deletes flushed and expired entries and evicts the least recently used entries above the entry limit. The payload size, the number of query parameters, the query length and the number of tags per entry are limited.
* **Object-cache store.** With a persistent object cache the payloads are served from the object cache, so the plugin looks up and serves a hit without querying its database tables.
* **WP-CLI.** `wp rfrac flush`, `wp rfrac list`, `wp rfrac status`, `wp rfrac prune` and `wp rfrac config get`.
* **Multisite.** Per-site tables and settings, network activation, automatic setup of new sites and `--network` options in WP-CLI.
* **WooCommerce.** With WooCommerce active, the public Store API catalog (products, categories, brands, tags, attributes and reviews) can be cached once you enable it under REST API Cache → Routes. The cart, checkout, orders and every logged-in shopper are never cached.
* **Admin screens and Site Health.** Browse and flush cached responses, manage routes and settings, check the must-use file and run maintenance from the REST API Cache menu; Site Health reports problems.

**Documentation**

* [Getting started](https://rockfire.nl/docs/) – requirements, installation and checking that responses are cached.
* [Hooks reference](https://rockfire.nl/docs/hooks/) – every action and filter, with its parameters and examples.
* [Switching from WP REST Cache](https://rockfire.nl/docs/migration/) – the import, the notices and the report explained.
* [FAQ](https://rockfire.nl/docs/faq/) – including why an endpoint is not cached.

**What is never cached**

Requests from logged-in users; requests with an `Authorization` header, a nonce (the `X-WP-Nonce` header or a `_wpnonce` parameter, unless the `rfrac/nonce_caching` filter lets a logged-out request that only carries a nonce be cached), an API key header or a login, session or post password cookie (a login cookie is never cached, whatever the filters say); requests with a method the route is not opted in to (only `GET`, unless the route opts in to `POST`, `PUT` or `PATCH`); requests with an `X-HTTP-Method-Override` header; GET requests that carry a body; requests with a multipart, invalid or oversized body, or a body that is neither JSON nor a URL-encoded form; a `context` other than `view` or `embed`; `status` queries other than `publish`; requests with a response format parameter (`_jsonp`, `_envelope`, `_pretty` or `_method`); the routes on the hard deny-list (`/wp/v2/users/me`, `/batch/v1`, the WooCommerce Store API cart, checkout, order, batch and shopper-list routes, and the WooCommerce `wc/agentic` and `wc/private` routes); requests over the limits (a route longer than 191 bytes, a query string longer than 2048 bytes, more query parameters than the configured maximum, or a route or parameter that is not valid UTF-8; the route is checked as sent, so a percent-encoded byte such as `%FF` in the path is plain ASCII); and responses that are not `200 OK`, are empty result sets, are larger than the payload limit, carry more tags than the tag limit, set a cookie, are marked `private`, `no-store`, `no-cache` or `max-age=0`, or are vetoed by the `rfrac/store_allowed` filter. No new responses are stored while the cache holds about 1.5 times the entry limit, until maintenance has pruned it.

= Credits =

Rockfire – REST API Cache is a fork of WP REST Cache by Acato (https://wordpress.org/plugins/wp-rest-cache/), GPLv3.

= Significant changes from WP REST Cache =

* Security fixes: responses are never cached for authenticated requests (logged-in users, `Authorization`, nonces unless the `rfrac/nonce_caching` filter lets a logged-out request that only carries a nonce be cached, credential headers and cookies; a login cookie always bypasses); routes are matched strictly against the registered routes; the cache key is built from the parameters a route knows, and unknown parameters bypass the cache instead of creating new entries; sizes and counts are limited; the plugin makes no outbound HTTP requests and stores no credentials; only an allow-list of response headers is replayed.
* A new architecture and database schema (`rfrac_entries` and `rfrac_tags`), a compiled configuration that the early phase reads without loading other plugins, and a database or object-cache store.
* A route manager instead of a list of allowed endpoints.
* Tag-based invalidation instead of object-type relations, with a public PHP API.
* Pruning and size limits keep the cache tables bounded.
* The `wp rfrac` WP-CLI commands.
* Full multisite support.
* A real uninstall that removes every table, option, transient, user meta key, cron event and the must-use file.
* The cache regeneration cron and the upsell have been removed.

= Must-use file =

**What:** the plugin installs `wp-content/mu-plugins/rfrac-early-cache.php` (in the must-use plugins directory, `WPMU_PLUGIN_DIR`). It is a small loader that does nothing unless this plugin is active.

**Why:** must-use plugins load before regular plugins, so a cache hit is served before other plugins and the theme are loaded. That is what makes a hit fast.

**When it is written:** at exactly three moments. On activation (on multisite only when the activating user may manage network plugins, `manage_network_plugins`; an activation by a site administrator or from WP-CLI does not write it). When someone clicks the "Install must-use file" button (a user who may manage plugins, `activate_plugins`; on multisite `manage_network_plugins`, because the file is shared by the whole network). And when this plugin is updated, by any updater (including automatic updates), while an outdated copy built from the plugin's template (it carries an `RFRAC Template:` header) already exists: that copy is refreshed. An update never creates a missing file and never touches a file that is not built from the template. Nothing else writes the file. It is written atomically: a temporary file is written, verified and renamed into place, and the plugin never asks for FTP credentials.

**Notice:** when the file is missing or outdated, a notice with an "Install must-use file" button appears on the Plugins screen and on the plugin's own pages for a user who may install it, and the plugin serves cache hits from `plugins_loaded` (fallback mode) until it is installed.

**When it is removed:** on deactivation, unless the plugin is still active on another site of the network, and on uninstall.

**Fallback mode:** when the file cannot be written (the directory is not writable, WordPress cannot write files directly, or `DISALLOW_FILE_MODS` is set) the plugin keeps working and serves cache hits from `plugins_loaded`. REST API Cache → Tools and Site Health show the mode and the reason.

**Manual install:** copy `wp-content/plugins/rockfire-rest-api-cache/mu-plugin/rfrac-early-cache.php` to `wp-content/mu-plugins/rfrac-early-cache.php`, then in the copy replace `{{RFRAC_PLUGIN_VERSION}}` with the plugin version and `{{RFRAC_PLUGIN_BASENAME}}` with `rockfire-rest-api-cache/rockfire-rest-api-cache.php`. REST API Cache → Tools shows the exact values for your site.

**Opting out:** add `define( 'RFRAC_DISABLE_MU_INSTALL', true );` to `wp-config.php` and the plugin never writes the file.

= Privacy =

The plugin does not track users, does not send data to external services and makes no external HTTP requests. It only caches public REST API responses: requests carrying credentials are never cached. All cached data is stored in your own database (or object cache) and is removed when the plugin is uninstalled.

== Installation ==

1. Install the plugin from Plugins → Add New, or upload the `rockfire-rest-api-cache` folder to `wp-content/plugins/`.
2. Activate the plugin (network-activate it on multisite to cache every site). Activation creates the cache tables and installs the must-use file.
3. If the must-use file is missing or outdated, a notice on the Plugins screen and on the plugin's pages offers an "Install must-use file" button. Open REST API Cache → Tools to check that the file is installed and serving early; if it cannot be written, follow the manual install instructions shown there.
4. Review REST API Cache → Routes: the `wp/v2` routes and the oEmbed route are cached by default; enable the routes of other plugins you want to cache.
5. Optionally adjust REST API Cache → Settings (default and maximum TTL, entry limit, payload size, store).
6. [Check that responses are cached](https://rockfire.nl/docs/#check).

Do not run this plugin together with WP REST Cache: deactivate WP REST Cache first.

**Configuration constants** (in `wp-config.php`; a constant locks the matching setting in the admin):

* `RFRAC_DISABLE` – `true` disables caching immediately (kill switch).
* `RFRAC_STORE` – `auto` (default: the object cache when a persistent object cache is available, else the database), `database` or `object_cache`.
* `RFRAC_DEFAULT_TTL` – default time to live in seconds (default 86400).
* `RFRAC_MAX_TTL` – maximum time to live in seconds (default 2592000).
* `RFRAC_MAX_ENTRIES` – maximum number of cached responses per site (default 10000).
* `RFRAC_MAX_PAYLOAD_KB` – largest response stored, in KB (default 1024).
* `RFRAC_MAX_PARAMS` – maximum number of query parameters of a cached request (default 20).
* `RFRAC_HIT_SAMPLING` – count one hit in N for the statistics; 0 disables hit counting (default 10).
* `RFRAC_GLOBAL_IGNORE_PARAMS` – query parameters that never change the cache key on any route, for example `define( 'RFRAC_GLOBAL_IGNORE_PARAMS', array( 'utm_source', 'fbclid' ) );` (an array, or one string separated by commas or spaces; at most 20 names of letters, digits and `_-.[]`). The constant locks the setting under REST API Cache → Settings, and the `rfrac/global_ignore_params` filter still applies on top of it. A parameter a route registers is never ignored on that route. A value that is not valid (a bad name or too many names) is ignored and reported; `array()` locks the list to empty.
* `RFRAC_GLOBAL_VARY_HEADERS` – request headers that vary the cache key on every route, for example `define( 'RFRAC_GLOBAL_VARY_HEADERS', array( 'Accept-Language' ) );` (an array, or one string separated by commas or spaces; names are lowercased and an underscore counts as a dash). At most 5 headers together with the vary headers of each route; `Authorization`, `Cookie`, `Set-Cookie`, `Proxy-Authorization` and `X-WP-Nonce` are not allowed. The constant locks the setting, and the `rfrac/global_vary_headers` filter still applies on top of it. Unlike the other constants, a value that is not valid is not set aside for the stored setting: it is reported, and no route is cached until it is fixed, because caching with a different list would serve one shared response to visitors who should get their own.
* `RFRAC_NONCE_CACHING` – whether a logged-out request that only carries a REST nonce (the `X-WP-Nonce` header or a `_wpnonce` parameter) may be cached (default false), for example `define( 'RFRAC_NONCE_CACHING', true );`. A cache hit never verifies the nonce, and every other credential signal and every login cookie still bypasses. The constant locks the Cache requests with a REST nonce setting under REST API Cache → Settings, and the `rfrac/nonce_caching` filter still applies on top of it. A value that is not a boolean is ignored and reported as the plugin's last error.
* `RFRAC_ROUTES` – route rules defined in code, keyed by route, for example `define( 'RFRAC_ROUTES', array( '/my/v1/header' => array( 'status' => 'enabled', 'ttl' => 3600 ) ) );`. A rule can also list the request `methods` it caches, for example `'methods' => array( 'GET', 'POST' )` (`GET` when absent; `POST`, `PUT` and `PATCH` only for methods the route registers, never on `wp/v2`; see "Can I cache POST, PUT or PATCH requests?"). These routes are read-only in the admin.
* `RFRAC_ALLOW_SKIP_CACHE` – whether the `skip_cache` request parameter bypasses the cache (default false).
* `RFRAC_BYPASS_COOKIES` – extra cookie name prefixes that bypass the cache (array). Entries that are not valid cookie name characters (letters, digits and the other cookie name characters such as `-`, `_`, `.` and `!`, but not `%`; at most 128) are ignored and reported as the plugin's last error; a `.` matches `_`, the name PHP gives the cookie.
* `RFRAC_BYPASS_HEADERS` – extra request headers that carry credentials and bypass the cache (array). Entries that are not header names (letters, digits, dashes and underscores, at most 64; an underscore counts as a dash, as PHP can not tell them apart) are ignored and reported as the plugin's last error.
* `RFRAC_CORS_WILDCARD` – send `Access-Control-Allow-Origin: *` on cache hits for origins that are not allowed.
* `RFRAC_DISABLE_MU_INSTALL` – never write the must-use file.

== Frequently Asked Questions ==

= Why isn't my endpoint cached? =

REST API `GET` responses, and the `POST`, `PUT` and `PATCH` responses of routes that opted in to those methods, carry an `X-Rfrac-Cache` header: `HIT` (served from the cache), `MISS` (generated by WordPress and stored in the cache) or `BYPASS` (not served from the cache and not stored). With `WP_DEBUG` enabled, a bypassed response also carries `X-Rfrac-Cache-Reason`, for example:

* `route_not_enabled` – enable the route under REST API Cache → Routes.
* `unknown_param` – the request uses a query parameter the route does not register. Add it to the route's ignored parameters, to REST API Cache → Settings → Ignored query parameters to ignore it on every route, or let the route accept all parameters.
* `credentials` – the request is authenticated or carries a bypass cookie or header, or a `password` parameter.
* `context` or `status_param` – the request asks for non-public data.
* `denied_route` – the route is on the hard deny-list and is never cached.
* `method`, `method_override` or `body` – the route is not opted in to the request method (only `GET`, unless the route opts in to `POST`, `PUT` or `PATCH`), the request carries an `X-HTTP-Method-Override` header, it is a `GET` request with any body, or its body is multipart, neither JSON nor a URL-encoded form, invalid, or larger than 64 KB.
* `format_param` – the request uses `_jsonp`, `_envelope`, `_pretty` or `_method`.
* `bypass_param` – the request uses one of the route's bypass parameters.
* `visitor_varies` – another plugin makes this route's responses vary per visitor (e.g. WooCommerce prices with a tax-location plugin), so it is never cached.
* `vary_cookie` – a declared cookie carries a value outside its allow-list.
* `skip_cache` – the request uses `skip_cache` and skipping is allowed (`RFRAC_ALLOW_SKIP_CACHE` or the `rfrac/allow_skip_cache` filter).
* `too_many_params` or `query_too_long` – more query parameters than the configured maximum, or a query string longer than 2048 bytes.
* `coexistence` – WP REST Cache is active; deactivate it.
* `disabled` – caching is turned off in the settings or by `RFRAC_DISABLE`.

A cacheable request whose response is not stored is marked `BYPASS` too, with the reason the response was not stored:

* `status` – the response is not `200 OK`.
* `empty` – the response is an empty result set (see the `rfrac/cache_empty_results` filter).
* `too_large` – the response is larger than the payload limit.
* `set_cookie` or `private` – the response sets a cookie, or is marked `private`, `no-store`, `no-cache` or `max-age=0`.
* `user` – a user was logged in while the response was generated.
* `too_many_tags` – the response carries more tags than the tag limit.
* `entry_ceiling` – the cache already holds 1.5 times the entry limit (the count includes responses stored moments ago and is recounted as it nears that ceiling); maintenance prunes it back to the limit.
* `vary_cookie` – a cookie declaration changed since the configuration was compiled; the next recompile fixes it.
* `filtered` – the `rfrac/store_allowed` filter vetoed storing, a `wp_rest_cache/cache_key` or `wp_rest_cache/filter_cache_output` callback is hooked while `RFRAC_LEGACY_HIT_FILTERS` is not defined, or the `rfrac/bypass_all` filter bypassed every request.
* `flushed` – the cache was flushed after this request looked it up (for example, content was taken down while the response was being generated); the next request stores it again.
* `no_lookup` – no lookup recorded a starting point for this request (which is how a flush in between is detected), for example on the first request after the plugin was updated, before its configuration was rebuilt; the next request normally stores it.
* `disabled` – caching was turned off (`RFRAC_DISABLE`) while the response was being generated.
* `error` – storing failed, or the tags of the response could not be collected completely; the error is shown on the plugin's admin pages.

More causes and how to fix them: [why an endpoint is not cached](https://rockfire.nl/docs/faq/#not-cached).

= Can I cache POST, PUT or PATCH requests? =

Yes, for front ends that read data with a `POST`, `PUT` or `PATCH` request (a search or filter endpoint, for example). It is an opt-in per route, and `GET` stays the only method cached everywhere else:

* Under REST API Cache → Routes, edit the route and tick the methods to cache, or set `'methods'` in a rule (`RFRAC_ROUTES`). Only methods the route registers a handler for can be chosen, and the route has to be enabled. Routes that only have `POST`, `PUT` or `PATCH` handlers are listed too.
* Never on `wp/v2`: core routes are only cached for `GET`. `DELETE` is never cached, and the deny-list and the credential checks apply to every method.
* The cache key adds the method, the content type and a hash of the request body. `application/json` and `application/x-www-form-urlencoded` bodies are decoded and canonicalised first, so the order of the keys and the whitespace do not split the cache. Only the hash is stored, never the body.
* A body larger than 64 KiB, a `multipart` body, a body that is not valid JSON or UTF-8, and any request with an `X-HTTP-Method-Override` header bypass the cache.
* A `GET` with a body is never cached.

Warning: a cached `POST`, `PUT` or `PATCH` response is served without running the route's handler, so anything the handler does besides returning data (saving, sending, counting) does not happen on a cache hit. Enable these methods only for routes that only read data. The route editor repeats this warning next to the method checkboxes.

= How do I flush the cache from code? =

Use the public API. Every flush function returns the number of flushed responses and acts on the current site:

    rfrac_flush_tag( 'post:12' );                    // Everything that contains post 12.
    rfrac_flush_tag( array( 'post:12', 'list:product' ) );
    rfrac_flush_url( rest_url( 'wp/v2/posts' ) );    // One URL, including its query string.
    rfrac_flush_route( '/wp/v2/posts' );             // A route and everything below it.
    rfrac_flush_all();                               // Everything of the current site.

Tags have the form `prefix:value`: `post:{id}`, `term:{id}`, `term-ref:{id}`, `user:{id}`, `comment:{id}`, `list:{post type or taxonomy}`, `list:users`, `list:comments`, or your own prefix. `term:{id}` marks responses that show a term (the term itself, term lists, embedded terms) and is flushed when the term changes; `term-ref:{id}` marks responses that only refer to the term, like a post with its `categories`, `class_list` and link, and is flushed only when the term is deleted or its slug or parent changes (for the term and its descendants). A value has no spaces or commas, so several tags can also be passed as one string separated by commas or spaces (`'post:12, list:product'`). Tag your own responses while they are generated, for example in a route callback, and flush them later:

    rfrac_tag_response( 'acf-options:header' );
    // ... later, after the options were saved:
    rfrac_flush_tag( 'acf-options:header' );

Call these functions from `plugins_loaded` or later. A custom route such as `/my/v1/header` is only cached once you enable it under REST API Cache → Routes or in `RFRAC_ROUTES`: by default only the `wp/v2` routes and the oEmbed route are cached.

With WooCommerce active, the tags of its public Store API responses are `wc-price:all`, `wc-attribute:{id}`, `list:wc-attribute`, `list:wc-review` and `list:wc-popularity` (carried by product lists sorted by popularity and flushed when a product's sales count changes), plus the tags above that WooCommerce objects reuse: `post:{id}`, `term:{id}`, `list:product`, `list:product_cat`, `list:product_tag`, `list:product_brand` and `list:pa_{attribute}` (for example `list:pa_color`). Flush them like any other tag. Changes to the location of a tax rate made by calling WooCommerce's internal `WC_Tax::_update_tax_rate_postcodes()` or `WC_Tax::_update_tax_rate_cities()` directly are not detected: code that does this should flush the `wc-price:all` tag afterwards, for example `rfrac_flush_tag( 'wc-price:all' )`.

= Which hooks exist? =

Actions to react to flushes, stores, failures, maintenance and the plugin lifecycle, such as `rfrac/flushed` (purge a CDN after a flush) and `rfrac/stored`. Filters to change routes, bypass rules, cache keys, tags, invalidation and admin access, such as `rfrac/route_rules`, `rfrac/bypass_headers`, `rfrac/entry_ttl`, `rfrac/store_allowed` and `rfrac/settings_capability`. Filters that are compiled into the configuration apply after the next recompile, such as a settings save.

Every hook, with its parameters and examples, is in the [hooks reference](https://rockfire.nl/docs/hooks/) and in `includes/class-hooks.php`.

= Which WP-CLI commands are available? =

* `wp rfrac flush --all`, `wp rfrac flush --tag=post:1,post:2`, `wp rfrac flush --route=/wp/v2/posts` and `wp rfrac flush --request-url=https://example.com/wp-json/wp/v2/posts` – flush cached responses. The URL option is named `--request-url` because WP-CLI reserves `--url` for selecting the site.
* `wp rfrac list` – list cached responses; filter and format with `--route`, `--state`, `--search`, `--per-page`, `--page`, `--fields` and `--format`.
* `wp rfrac status` – show the cache status, including whether the must-use file serves early.
* `wp rfrac prune` – run one maintenance pass.
* `wp rfrac config get` – show the effective settings and where each value comes from; `--compiled` prints the compiled configuration.
* `wp rfrac import-legacy` – import the settings of WP REST Cache and report what became of every value. `wp rfrac import-legacy --dry-run` prints what would happen and saves nothing; `--format` picks the output. The steps are described under "How do I switch from WP REST Cache?" below.

On multisite, `wp rfrac flush`, `wp rfrac list` and `wp rfrac prune` accept `--network` to act on every site where the plugin is active. `wp rfrac import-legacy --dry-run --network` does the same for the import, in one WP-CLI process per site, so that each site loads its own plugins.

= Why does a notice appear after I switch a plugin on or off? =

A plugin can change what the REST API returns, so after any other plugin is activated or deactivated (from the admin, by AJAX or with WP-CLI) the cached responses of the site may be outdated. The plugin does not flush by itself: it shows a notice on the Plugins screen and on the plugin's own admin pages, to users who may manage the plugin, with two buttons. Flush all clears the cached responses of the site, and the notice goes away with any full flush. Dismiss hides the notice and keeps the cache as it is. On multisite, a network-wide change shows the notice on every site when the plugin is network-active; otherwise only on the site where the change was made.

= Does it work with a persistent object cache? =

Yes. With the default store setting `auto` and a persistent object cache (such as Redis or Memcached), the payloads are kept in the `rfrac` object-cache group and the plugin serves a hit from the object cache without querying its database tables; the entry metadata and tags stay in the database tables, so invalidation and pruning keep working. Set `RFRAC_STORE` to `database` or `object_cache` to choose explicitly.

= Does it support multisite? =

Yes. Every site has its own tables, settings and cache. A network activation sets up the first 100 sites immediately (filter `rfrac/network_activation_batch`) and the others on their next request; new sites are set up automatically. The must-use file is shared by the network, so only network administrators can install it from the admin. The admin pages are per site, and `wp rfrac flush`, `wp rfrac list` and `wp rfrac prune` accept `--network`. An archived, spammed or deactivated site is never served from the cache, and its cache is flushed when its status changes.

= Does it work with WooCommerce? =

Yes, for the public catalog. The plugin caches the anonymous `GET` catalog routes of the WooCommerce Store API under `/wc/store` and `/wc/store/v1`: products (the list, by ID and by slug), product attributes (the list, by ID and the terms of an attribute), product categories (the list and by ID), product brands (the list and by identifier), product tags, product reviews and the collection data.

These routes are off until you enable them: use "Enable WooCommerce catalog" under REST API Cache → Routes, or enable single routes there.

A stock change flushes the cached responses that show the product, including the product lists it appears in. All product lists are flushed only when a product goes in or out of stock, so orders do not keep the catalog cache cold.

Never cached: the cart, checkout, order, batch and shopper-list routes, the `wc/agentic` and `wc/private` routes, `wc/v3` and every other authenticated WooCommerce API, and every request of a logged-in shopper.

Prices: the product list, a single product and the collection data carry prices. For an anonymous request WooCommerce computes them from the shop's base location, unless a plugin changes the tax location through `woocommerce_get_tax_location`, as geolocation and multi-currency plugins do. While a callback is hooked on that filter, shops with location pricing get no caching of products or collection data: those responses are neither served from nor stored in the cache, so a price computed for one visitor is never shown to another. The filter `rfrac/woocommerce/location_pricing` receives whether such a callback was detected: return true to bypass these routes regardless, or false to cache them although a callback is hooked. It is compiled into the configuration, so a change takes effect after the next recompile.

A currency plugin that keeps the visitor's currency in a cookie can declare that cookie with `rfrac/woocommerce/currency_cookies`. Products and collection data are then cached once per allowed value, and a request with any other value is not cached:

    add_filter(
        'rfrac/woocommerce/currency_cookies',
        function () {
            return array( 'wmc_current_currency' => array( 'EUR', 'USD', 'GBP' ) );
        }
    );

Plugins that vary prices per visitor in any other way, without a declared cookie, are not supported: the plugin cannot tell their responses apart. Keep the product and collection-data routes disabled on such a shop.

The plugin is compatible with High-Performance Order Storage (HPOS) and with the cart and checkout blocks. It is tested with WooCommerce 11.3, which requires WordPress 7.0. Site Health → Info shows the WooCommerce version, whether HPOS is on, how many catalog routes are enabled, whether the route snapshot is current, whether products and collection data are cached or bypassed and why, and which currency cookies are declared.

WooCommerce registers only the requested namespace on requests to its own namespaces, so the plugin does not rebuild its route snapshot from them. On a site whose REST traffic only reaches WooCommerce namespaces, route and setting changes and the first route discovery after activation apply only after a settings save or a REST request to another namespace.

= Does it work on a site served on several domains? =

Yes. A request on a host other than the site's home host (the host of `home_url()`) is cached apart from the requests on the home host, so a response whose links follow the request host, as on a site that builds `WP_HOME` from `HTTP_HOST`, is never served to a visitor on another domain. The entries of the home host are unchanged. Flushing a URL reaches the entries of every host. The home host is part of the compiled configuration, so a changed home host is picked up at the next compile, which flushes the cache.

= Can I use it together with a page cache or a CDN? =

Yes. This plugin caches REST responses inside WordPress. A page cache or CDN in front of WordPress may cache REST responses itself, and this plugin does not purge it. Use the `rfrac/flushed` action to purge your CDN when entries are flushed, or exclude `/wp-json/` from the page cache.

= Do the REST API restrictions of other plugins apply to cached responses? =

Not on a cache hit. The must-use file serves a hit before regular plugins load; in fallback mode, without the must-use file, a hit is served on `plugins_loaded`, before `init` and the REST API run. A restriction that another plugin adds later, while the request runs, such as blocking an IP address or a country, rate limiting, or a `rest_authentication_errors` filter, is not applied to it. Only responses that a visitor who is not logged in received with `200 OK` are stored, so a route that such a restriction blocks for every visitor who is not logged in is no longer stored once the restriction is in place. Entries stored before that, for example before you activated the plugin that adds the restriction or before the restriction was added in code, keep being served until they expire: flush the cache (REST API Cache → Tools or `wp rfrac flush --all`) or exclude the route. A restriction that blocks only some visitors (by IP address, country or request rate) does not stop the response another visitor received from being served to them. Exclude such routes under REST API Cache → Routes, or let their requests bypass the cache with the `rfrac/bypass_cookie_prefixes` or `rfrac/bypass_headers` filter (or the `RFRAC_BYPASS_COOKIES` and `RFRAC_BYPASS_HEADERS` constants). The same holds for a route whose permission callback checks a secret request header or the visitor's IP address: add that header to the bypass headers, or do not cache the route.

= How much database space can the cache use? =

The cache keeps at most about 1.5 times the Maximum entries setting of active entries, each up to the Maximum response size, plus a small row and its tags per entry. With the defaults (10,000 entries of up to 1,024 KB) that is up to about 15 GB of active entries in the worst case, when every stored response is close to the size limit; most REST responses are far smaller. A flush only marks entries as flushed: they keep their payloads, and do not count towards that limit, until the hourly maintenance deletes them. After a broad flush the tables can therefore hold the flushed entries and a new set of active ones, so that bound is not hard. Visitors choose the URLs they request, so anyone can fill the cache up to the limit with different parameter values (pages, `per_page`, `include` or `_fields`). The hourly maintenance deletes flushed and expired entries and evicts the least recently used active entries above Maximum entries, so entries that are never requested again go first, and no new responses are stored while the cache holds 1.5 times the limit in active entries. Once a maintenance run has finished its work, no flushed entries are left and at most Maximum entries active ones. On a host with a small database quota, lower Maximum entries or Maximum response size under REST API Cache → Settings (or with `RFRAC_MAX_ENTRIES` and `RFRAC_MAX_PAYLOAD_KB`). With the object-cache store the payloads are kept in the object cache, not in the database tables.

= Can I run it next to WP REST Cache? =

No. While WP REST Cache is active, this plugin pauses caching completely. A notice says so on the Plugins screen and on the plugin's own pages, and each user can dismiss it for themselves; REST API Cache → Tools keeps showing that caching is paused. The plugin never deactivates WP REST Cache and never changes its settings or data. You can import the settings of WP REST Cache while it is active (see the next question), but caching only starts once you deactivate WP REST Cache.

= How do I switch from WP REST Cache? =

Install and activate this plugin, import the settings of WP REST Cache, then deactivate WP REST Cache. Caching stays paused until you do, and nothing is imported unless you ask.

Import from the notice on the Plugins screen ("Review on the Import page" previews it without saving) or with `wp rfrac import-legacy` (`--dry-run` previews, `--network` runs on every site of a network). The report gives every value a status: Imported, Kept, Adjusted, Skipped or Unsupported. The cache entries of WP REST Cache are not imported and its data stays untouched.

Afterwards, check for `X-Rfrac-Cache: HIT`, `MISS` or `BYPASS` instead of `X-WP-Cached-Call`, and use `wp rfrac flush` instead of `wp wp-rest-cache flush`. The [migration guide](https://rockfire.nl/docs/migration/) explains the notices and the report.

= Do my WP REST Cache constants still work? =

Two do, while the `RFRAC_*` constant that replaces them is undefined: the setting is then locked under REST API Cache → Settings.

| Constant | Replacement | What happens |
| --- | --- | --- |
| `WP_REST_CACHE_TIMEOUT` and `WP_REST_CACHE_TIMEOUT_INTERVAL` | `RFRAC_DEFAULT_TTL` | Works: the default TTL is their product in seconds, cut to the maximum TTL (30 days by default; raise Max TTL or `RFRAC_MAX_TTL` for up to one year). |
| `WP_REST_CACHE_GLOBAL_CACHEABLE_REQUEST_HEADERS` | `RFRAC_GLOBAL_VARY_HEADERS` | Works: request headers that vary the cache key on every route, at most 5, none that carries credentials. An invalid value is ignored. |
| `WP_REST_CACHE_REGENERATE`, `WP_REST_CACHE_REGENERATE_INTERVAL` and `WP_REST_CACHE_REGENERATE_NUMBER` | none | No effect: expired entries are not regenerated. |
| `WP_REST_CACHE_MEMCACHE_USED` | none | No effect. |

The Site Health test "WP REST Cache compatibility" reports each old constant that is defined.

= Do my WP REST Cache hooks still work? =

Most do. The plugin fires most old `wp_rest_cache/…` hooks from their replacement hook, and WordPress reports each as deprecated while WP_DEBUG is on. `wp_rest_cache/cache_key`, `wp_rest_cache/filter_cache_output` and `wp_rest_cache/pre_output_headers` have no replacement hook. A cache hit applies no plugin filters, so callbacks that depend on the request run on hits only with `define( 'RFRAC_LEGACY_HIT_FILTERS', true );` in `wp-config.php`, which makes hits slower. A hit then applies the old filters directly, as plain filters that never log a notice. A hit served by the must-use file `rfrac-early-cache.php` comes before plugins and the theme load, so their callbacks do not run on it: hook them in a must-use plugin that sorts before `rfrac-early-cache.php`. **Without the constant nothing is stored while `wp_rest_cache/cache_key` or `wp_rest_cache/filter_cache_output` is hooked** (reason `filtered`): define the constant or unhook the callback.

The hooks of removed features no longer fire; REST API Cache → Import and Site Health list the ones your site still uses. Every old hook with its replacement is listed in the [legacy hooks reference](https://rockfire.nl/docs/migration/legacy-hooks/).

= Does code that calls WP REST Cache classes still work? =

Calls do not cause a fatal error once WP REST Cache is removed: seven classes have a shim, among them `Caching`, `Endpoint_Api` and the controllers `Post_Controller`, `Term_Controller` and `Attachment_Controller`. A call that still works, such as `Caching::delete_cache_by_endpoint()` or `delete_related_caches()`, runs its replacement (`rfrac_flush_url()`, `rfrac_flush_route()` or `rfrac_flush_tag()`) and raises a deprecation notice. Any other call does nothing, returns a neutral value and is listed under "Classes" in REST API Cache → Import and Site Health.

Routes registered with the old controllers are no longer cached automatically: enable them under REST API Cache → Routes. The shims only cover calls made from `plugins_loaded` or later. Every method and what it does now is listed in the [legacy classes reference](https://rockfire.nl/docs/migration/legacy-classes/).

= What does uninstalling remove? =

Deleting the plugin from the Plugins screen removes, on every site of the network: the `rfrac_entries` and `rfrac_tags` tables, every option and transient whose name starts with `rfrac_`, the network options, user meta whose key starts with `rfrac_` or with the site's table prefix followed by `rfrac_`, the maintenance cron events, the `rfrac` object-cache group (when the object cache supports flushing a group) and the must-use file `rfrac-early-cache.php`. Deactivating flushes the cache of the site, unschedules its maintenance cron events and removes the must-use file (unless the plugin is still active on another site of the network); the tables and settings stay.

= Where do I report security bugs found in this plugin? =

Please report security bugs found in the source code of the Rockfire – REST API Cache plugin through the [Patchstack Vulnerability Disclosure Program](https://patchstack.com/database/vdp/3b6565d9-6316-455d-8034-ac732df8b164). The Patchstack team will assist you with verification, CVE assignment, and notify the developers of this plugin.

== Screenshots ==

1. Caches: the cached REST API responses with their method, route, state, size, hits and expiry, ready to flush or delete.
2. Routes: every registered REST route with its namespace, caching status, TTL and parameters.
3. A route's edit view: its status, the methods it caches and its parameters.
4. Settings: defaults and request rules.
5. Tools: the status of the must-use file and the cache, and flushing by tag, URL or route.

== Changelog ==

= 1.0.0 =
* First public release.
* Early serving of cache hits from a must-use file, with a fallback mode when the file is not installed.
* A route manager to enable or exclude any registered route and set its TTL, accepted parameters and vary headers.
* Tag-based invalidation that flushes the responses carrying the posts, terms, users, comments and lists that change.
* A public PHP API to flush by tag, URL, route or everything and to add your own tags: `rfrac_flush_tag()`, `rfrac_flush_url()`, `rfrac_flush_route()`, `rfrac_flush_all()` and `rfrac_tag_response()`.
* Pruning of flushed and expired entries, and limits on the entry count, payload size, query parameters, query length and tags per entry.
* An object-cache store that serves payloads from a persistent object cache without querying the database tables.
* WP-CLI commands: `wp rfrac flush`, `wp rfrac list`, `wp rfrac status`, `wp rfrac prune`, `wp rfrac import-legacy` and `wp rfrac config get`.
* Multisite support with per-site tables and settings, network activation and `--network` options in WP-CLI.
* Caching of the public WooCommerce Store API catalog, which you enable under REST API Cache → Routes.
* Admin screens to browse and flush cached responses, manage routes and settings and check the must-use file, and Site Health checks that report problems.
* Built from WP REST Cache 2026.3.1 (GPLv3): imports its settings on request, keeps its public classes and hooks working while it is absent, and pauses caching while it is active.

== Upgrade Notice ==

= 1.0.0 =
First public release: there is no earlier version of this plugin to upgrade from. Moving over from WP REST Cache is covered in the FAQ.
