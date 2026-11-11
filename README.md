# wp-edge-cache-headers

Per-template `Cache-Control` for WordPress, so Cloudflare and nginx can cache anonymous HTML without ever storing a logged-in, preview, cart or cookie-setting page. It makes the decision once, in PHP, per request, and generates the matching nginx and Cloudflare configuration so the three layers agree.

Measured on a news site publishing about 79 posts an hour: before the header, Cloudflare reported `DYNAMIC` on every HTML request and uncached time to first byte was 1.29 s on the home page; an nginx page-cache hit is about 5 ms.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

## Contents

- [Install](#install)
- [Quick start](#quick-start)
- [What it sends](#what-it-sends)
- [Configuration](#configuration)
- [Filters and actions](#filters-and-actions)
- [WP-CLI](#wp-cli)
- [How it works](#how-it-works)
- [Limitations](#limitations)
- [FAQ](#faq)
- [Licence](#licence)

## Install

Requires WordPress 6.4 or later and PHP 8.1 or later. No Composer at runtime, no build step.

### As a normal plugin

1. Download the release zip, or clone into `wp-content/plugins/wp-edge-cache-headers`.
2. Activate it (network-activate on multisite if every site should use it).

### As a must-use plugin

WordPress loads only top-level files in `mu-plugins`, so a one-file loader is needed.

1. Copy the plugin directory to `wp-content/mu-plugins/wp-edge-cache-headers/`.
2. Copy [`examples/mu-plugin-loader.php`](examples/mu-plugin-loader.php) to `wp-content/mu-plugins/wp-edge-cache-headers-loader.php`. It contains one line that matters:

```php
require_once WPMU_PLUGIN_DIR . '/wp-edge-cache-headers/wp-edge-cache-headers.php';
```

The plugin guards against loading twice, so a normal and a must-use copy cannot both run.

### With Composer

```sh
composer require indianopinion/wp-edge-cache-headers
```

With `composer/installers` the package lands in `wp-content/plugins/`. Without Composer, `require 'autoload.php';` is all the engine needs (see [`examples/standalone-engine.php`](examples/standalone-engine.php)).

## Quick start

1. Install and activate. Anonymous HTML now carries a `Cache-Control` with `s-maxage`.
2. Tell it how busy the site is:

   ```sh
   wp edge-cache suggest-rate --apply
   ```

3. Generate and install the nginx snippet ([docs/nginx.md](docs/nginx.md)):

   ```sh
   wp edge-cache nginx
   ```

4. Generate and create the Cloudflare cache rule ([docs/cloudflare.md](docs/cloudflare.md)):

   ```sh
   wp edge-cache cloudflare
   ```

5. Check a URL:

   ```sh
   wp edge-cache explain https://example.com/hello-world/
   wp edge-cache explain https://example.com/hello-world/ --live
   ```

## What it sends

| Outcome | When | Headers |
|---|---|---|
| PUBLIC | Anonymous GET or HEAD, cacheable status, no bypass condition | `Cache-Control: public, max-age=0, s-maxage=T, stale-while-revalidate=S, stale-if-error=E`, `X-Accel-Expires: T`, `X-Edge-Cache: cache`, `Vary: Accept-Encoding`. Removes `Expires` and `Pragma`. |
| PRIVATE | Any bypass condition | `Cache-Control: private, no-store, max-age=0`, `X-Accel-Expires: 0`, `X-Edge-Cache: bypass` |
| RESPECT | Another component already said no-cache | Leaves its `Cache-Control` alone; adds `CDN-Cache-Control: no-store`, `X-Accel-Expires: 0`, `X-Edge-Cache: bypass` |
| SKIP | wp-admin, admin-ajax, cron, XML-RPC, WP-CLI, disabled | Nothing |

Bypass conditions, each with the reason shown in the debug header: logged in (`logged-in`); a cookie whose name starts with a listed prefix, valid or not (`cookie:<prefix>`); commerce pages (`commerce`); an `Authorization` header (`authorization`); preview, customiser and page-builder arguments (`preview`); comment moderation views (`moderation`); `_wpnonce` (`nonce`); password-protected posts (`password`); non-public post status (`non-public-status`); methods other than GET and HEAD (`method`); a `Set-Cookie` in the response (`set-cookie:<name>`); login, signup and activation paths (`path`); `Vary` on anything but `Accept-Encoding` (`unsafe-vary:<header>`); status outside the cacheable list (`status:<code>`); TTL 0 (`ttl-zero`); a filter that threw (`error`); a debug token (`debug-token`).

## Configuration

Settings live under Settings, Edge cache headers (and Network Settings for network defaults). Resolution order, later wins: built-in defaults, network defaults, the site's own settings, constants, filters.

### Default TTLs (seconds)

The profile follows the publishing rate: quiet under 1 post an hour, steady 1 to 19 (the default), busy 20 or more.

| Type | quiet | steady | busy |
|---|---|---|---|
| front_page, home | 900 | 300 | 300 |
| singular_post, embed, singular_other | 3600 | 1800 | 1800 |
| page, attachment | 7200 | 3600 | 3600 |
| category, tag, taxonomy, post_type_archive | 1800 | 600 | 600 |
| author, date | 1800 | 900 | 600 |
| search | 0 | 0 | 0 |
| not_found | 300 | 60 | 60 |
| feed | 1800 | 600 | 300 |
| sitemap | 1800 | 300 | 120 |
| robots (virtual robots.txt only) | 86400 | 3600 | 3600 |
| rest | 300 | 60 | 60 |
| redirect (301, 308) | 600 | 600 | 600 |
| other | 900 | 300 | 300 |

Search is 0, which means never cached: every search URL is unique and expensive.

### Settings

| Setting | Default | Notes |
|---|---|---|
| Profile | from posts per hour | quiet, steady or busy |
| Posts per hour | unset (steady) | `wp edge-cache suggest-rate` measures it |
| TTL per type | profile table | 0 means never cache |
| Browser max-age | 0 | Keep 0 for HTML so a reader who logs in never sees an anonymous copy from disk |
| Stale while revalidate | 5 x TTL | Clamped to one week |
| Stale if error | 86400 | Clamped to one week |
| Send `X-Accel-Expires` | on | nginx's per-URL TTL |
| Send bypass signal header | on | name `X-Edge-Cache` |
| Send `CDN-Cache-Control` | off | Always sent as `no-store` on RESPECT |
| Send `Surrogate-Control` | off | For Fastly and Varnish |
| Extra cookie prefixes, paths, query arguments | none | Added to the built-in lists |
| Respect a cacheable upstream `Cache-Control` | off | Off means the plugin's decision replaces it |
| Caches honour `Vary` | off | Off means unsafe `Vary` makes the page PRIVATE |
| Strict mode | off | Buffers output to catch cookies and status changes made while the theme prints |
| Debug header | off | `X-Edge-Cache-Debug` on every decided response |

### Constants (`wp-config.php`)

A constant locks its setting; the settings page shows it read-only.

| Constant | Effect |
|---|---|
| `EDGE_CACHE_HEADERS_DISABLED` | `true` turns the plugin off everywhere |
| `EDGE_CACHE_HEADERS_PROFILE` | `'quiet'`, `'steady'` or `'busy'` |
| `EDGE_CACHE_HEADERS_POSTS_PER_HOUR` | a number |
| `EDGE_CACHE_HEADERS_DEBUG` | `true` sends the debug header on every response |
| `EDGE_CACHE_HEADERS_DEBUG_TOKEN` | a secret of at least 32 characters for per-request debugging |
| `EDGE_CACHE_HEADERS_STRICT` | `true` turns on strict mode |

### Debugging one request

Rotate a token with `wp edge-cache token` (shown once, stored as a hash), then:

```sh
curl -sI -H 'X-Edge-Cache-Debug-Token: <token>' https://example.com/hello-world/ | grep -i x-edge-cache-debug
# X-Edge-Cache-Debug: outcome=PRIVATE; would-be=PUBLIC; type=singular_post; ttl=1800; swr=9000; sie=86400; status=200; hook=wp_headers; reasons=debug-token
```

A request with the token is always PRIVATE, so it is never cached, and the generated nginx snippet bypasses on it. The debug header never contains cookie values, user ids, nonces or query values.

## Filters and actions

Every filter is prefixed `edge_cache_headers_`.

| Filter | Value | Default |
|---|---|---|
| `enabled` | bool | true |
| `type` | type string; args: request facts | built-in classification |
| `ttl` | int; args: type, request facts (page number, post type, REST route) | profile table |
| `ttls` | full type to TTL map | profile table plus settings |
| `browser_max_age` | int | 0 |
| `stale_while_revalidate` | int; args: TTL, type | 5 x TTL |
| `stale_if_error` | int; args: type | 86400 |
| `cacheable_statuses` | list of ints | 200, 301, 308, 404 |
| `bypass_cookie_prefixes` | list of strings | WordPress, WooCommerce and EDD session, cart and comment cookies |
| `bypass_query_args` | list of strings | preview, customiser, page-builder, moderation, `_wpnonce` |
| `bypass_paths` | list of path prefixes | `/wp-login.php`, `/wp-signup.php`, `/wp-activate.php` |
| `never_cache_conditions` | list of callables returning bool | cart, checkout and account pages when WooCommerce or EDD is active |
| `sitemap_patterns` | list of path globs | core and common SEO-plugin sitemap names |
| `respect_upstream_public` | bool | false |
| `vary` | list of header names | `Accept-Encoding` |
| `vary_is_honoured` | bool | false |
| `rest_ttl` | int; args: route, namespace | 60 |
| `rest_route_denylist` | list of route prefixes | auth, users, settings and batch routes |
| `custom_header_name` | string | `X-Edge-Cache` |
| `send_cdn_cache_control`, `send_surrogate_control`, `send_x_accel_expires`, `send_custom_header` | bool | off, off, on, on |
| `strict_mode` | bool | false |
| `debug` | bool | false |
| `decision` | `Decision`; args: request facts | last chance to change the decision |
| `headers` | header map; args: `Decision` | the headers about to be sent |

Action `edge_cache_headers_decided` fires once per decision with the `Decision`, for logging or metrics. See [`examples/filters.php`](examples/filters.php) for recipes.

PHP functions: `IndianOpinion\EdgeCacheHeaders\current_decision()` returns the current request's `Decision` (or null before it is made); `IndianOpinion\EdgeCacheHeaders\explain( $url, $options )` explains a URL.

A filter that throws makes the response PRIVATE with reason `error`. A filter that returns an invalid value is ignored and the default is used. The `decision` filter can turn a PRIVATE decision PUBLIC: do not do that for identified visitors.

## WP-CLI

```text
wp edge-cache explain <url> [--method=<m>] [--cookie=<names>] [--logged-in] [--header=<name:value>] [--live] [--format=table|json]
wp edge-cache config
wp edge-cache nginx
wp edge-cache cloudflare
wp edge-cache token
wp edge-cache suggest-rate [--days=7] [--post-type=post] [--apply]
```

`explain` exits 0 when the decision was shown (and, with `--live`, matched), 1 on a live mismatch, 2 on an error such as a URL on a foreign host. If the live request cannot be made (some hosts block loopback requests), it falls back to the simulation and says so.

## How it works

The decision engine is a pure PHP class: request facts in, a header set out. The WordPress layer gathers the facts (conditional tags, cookie and query argument names, queued headers) and applies the result.

1. `wp_headers` (WordPress 6.1 and later run the main query before this filter): the main decision, merged into the header array core is about to send.
2. `send_headers`: replace any duplicate `Cache-Control` and remove the `Expires` and `Pragma` core queued for 404s.
3. `wp_redirect_status`: canonical and plugin redirects are re-decided (301 and 308 cacheable, 302, 303 and 307 private).
4. `template_include`: a late check that only downgrades, for cookies, no-cache headers and status changes that appeared after the main decision.
5. `rest_pre_serve_request`: REST responses.

Every bypass condition is checked before a TTL is chosen. Core's own no-cache calls (logged-in users, 404s) are recognised and replaced; anyone else's no-cache is respected. Full detail, with every design decision, is in [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md); the behaviour contract is [docs/PRD.md](docs/PRD.md).

## Limitations

- Cookies set and status codes changed while the theme prints, after `template_include`, cannot be seen without strict mode.
- If output starts before the plugin's hooks (a stray byte before `<?php`), it cannot send headers. nginx then uses its fallback TTL and Cloudflare, with the recommended rule, bypasses.
- No purging. Expiry plus `stale-while-revalidate` is the model; purge plugins can coexist.
- No TTL growth with post age yet.
- The nginx contract does not apply to Apache or LiteSpeed; the headers still work there.
- Cloudflare APO uses its own logic and is out of scope.

## FAQ

**Why is `max-age` 0?** Browsers revalidate HTML every time, so a reader who logs in is never shown an anonymous copy from their own disk cache. Shared caches still hold the page for `s-maxage`.

**My busiest template is never cached.** Run `wp edge-cache explain` on it, or send a debug token. A reason like `set-cookie:views` means a theme or plugin sets a cookie on anonymous views. Count views with a beacon instead.

**A page-cache plugin serves my pages and these headers are missing.** Page-cache plugins that serve before WordPress loads skip this plugin entirely. Its headers only appear on responses WordPress renders.

**Why not a shorter TTL on a busy site?** At about 80 posts an hour a 60 s TTL bought no visible freshness and kept the origin rebuilding. With `stale-while-revalidate` readers get an instant response while the refresh happens behind them.

**I purged Cloudflare and still see the old page.** A Cloudflare purge does not purge nginx. Purge nginx first, or rely on the TTLs.

**How do I remove everything?** Deleting the plugin from the Plugins screen removes its options on every site. For a must-use install, delete the options `edge_cache_headers_settings` (each site) and `edge_cache_headers_network` (network) by hand; `wp edge-cache config` prints both names.

**Does it work on multisite?** Yes: subdirectory, subdomain and domain-mapped. As a must-use plugin it runs on every site.

## Development

See [CONTRIBUTING.md](CONTRIBUTING.md). In short: `composer install`, then `composer test`, `composer stan` and `composer cs`.

## Licence

MIT. See [LICENSE](LICENSE).
