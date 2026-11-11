# PRD: wp-edge-cache-headers

Status: draft for review. Version target: 0.1.0.

Per-template Cache-Control for WordPress so Cloudflare and nginx can cache anonymous HTML.

This document describes behaviour, cases, interfaces, configuration, failure modes and acceptance tests. It contains no code. Case numbers (C1, C2, ...) are referenced by the acceptance tests in section 12 (T1, T2, ...).

---

## 1. Problem

WordPress sends no `Cache-Control` header on a normal anonymous page. It sends a no-cache set for logged-in users, for 404s and for a few core screens, and nothing at all for the front page, posts, archives, feeds or sitemaps. A CDN that sees no `Cache-Control` treats the page as dynamic, and an nginx page cache has no per-URL signal, so it falls back to one blunt TTL for everything.

The measured result on a news site publishing about 79 posts an hour (peaks of 2 a minute):

- Before the header, Cloudflare reported `cf-cache-status: DYNAMIC` on every HTML request. Nothing was cached at the edge.
- Uncached time to first byte was 1.29 s on the home page and 1.64 s on a category page. An nginx page-cache hit was about 5 ms.
- A single theme feature that set a cookie on anonymous article views made the highest-traffic template uncacheable at both layers until it was found.

The fix is not hard, but it is easy to get subtly wrong: cache one logged-in page publicly and you leak a session; set the TTL too short and the origin rebuilds pages all day; let nginx, PHP and the CDN disagree about what is cacheable and the hit ratio quietly collapses. This plugin makes the decision once, in PHP, per request, and documents the matching nginx and Cloudflare configuration so the three layers agree.

## 2. Goals and non-goals

### Goals

1. Send exactly one correct `Cache-Control` header on every anonymous HTML response WordPress renders, with a TTL chosen by template type.
2. Never let a personalised, authenticated, preview, cart, password or non-GET response be stored by a shared cache.
3. Ship defaults tuned to a publishing rate the operator sets, so a quiet blog and a busy newsroom both start in a sensible place.
4. Support `stale-while-revalidate` and `stale-if-error` so readers never wait for a rebuild and an origin outage serves the last good copy.
5. Optionally send `CDN-Cache-Control`, `Surrogate-Control`, `X-Accel-Expires` and one configurable custom header that nginx can key a bypass on.
6. Handle `Vary` safely.
7. Offer a filter for every value and every list.
8. Let an operator ask "what header would this URL get, and why?" from wp-admin and from WP-CLI.
9. Offer a debug header that explains the decision when enabled.
10. Document the nginx `fastcgi_cache` snippet and the Cloudflare cache rule that match the plugin's decisions, and generate them from the live configuration.
11. Work as a must-use plugin and as a normal plugin, on single sites and multisite.

### Non-goals

- Purging. The plugin never calls a CDN API or deletes cache files. Time-based expiry plus `stale-while-revalidate` is the model. Purge plugins can coexist.
- Full-page caching itself. The plugin sends headers; nginx, Varnish or a CDN stores pages.
- Object caching.
- Static assets (CSS, JS, images, fonts). Those are served by the web server and never reach PHP.
- Cache key normalisation (stripping `utm_*` and similar). That belongs in the CDN or nginx; the docs point to it.
- Rewriting or capturing output. The plugin does not buffer the page by default (see C47 and open question Q3).

## 3. Users and setting

- **Who installs it:** a site operator or platform engineer who runs WordPress behind nginx (with or without `fastcgi_cache`) and a CDN, most often Cloudflare. They can edit nginx config and CDN rules; they may not be able to change the theme.
- **Where it runs:** inside WordPress on every front-end request, every REST request, and on WP-CLI. It does nothing on wp-admin screens, admin-ajax, cron or XML-RPC beyond what is listed in section 4.
- **How it is installed:** dropped into `wp-content/mu-plugins/` as a single loader file plus its directory, or installed and activated as a normal plugin. No Composer at runtime. No build step.
- **Who reads its output:** nginx, the CDN, browsers, and the operator through the debug header, the explain tool and the access log.

## 4. Behaviour

### 4.1 Vocabulary

- **Decision:** the result of classifying one request. It has an outcome, a template type, a TTL, a stale-while-revalidate window, a stale-if-error window, a list of reasons, and the headers to send.
- **Outcomes:**
  - **PUBLIC**: shared caches may store the response for the TTL.
  - **PRIVATE**: the plugin sends `private, no-store, max-age=0`. No shared cache may store it.
  - **RESPECT**: another component already set a no-cache policy. The plugin leaves `Cache-Control` untouched and only adds its bypass signals (C34).
  - **SKIP**: the request is out of scope (admin, cron, CLI and so on). The plugin sends nothing.
- **TTL:** shared-cache lifetime in seconds (`s-maxage`).
- **Profile:** a set of default TTLs picked from the operator's publishing rate (section 7).

### 4.2 Headers by outcome

C1. **PUBLIC** sends `Cache-Control: public, max-age=B, s-maxage=T, stale-while-revalidate=S, stale-if-error=E`, where B is the browser max-age (default 0), T the TTL, S the stale-while-revalidate window and E the stale-if-error window. A directive whose value is 0 is omitted, except `max-age` and `s-maxage`, which are always present.

C2. Browser `max-age` defaults to 0 so browsers revalidate every time. Without this a reader who logs in can be shown an anonymous copy out of their own disk cache. Shared caches still hold the page for `s-maxage`.

C3. **PRIVATE** sends `Cache-Control: private, no-store, max-age=0`.

C4. When enabled, `CDN-Cache-Control` mirrors the shared-cache part: `max-age=T` plus the stale directives on PUBLIC, `no-store` on PRIVATE and RESPECT. Off by default. Cloudflare reads it ahead of `Cache-Control` when present.

C5. When enabled, `Surrogate-Control` carries the same values for Fastly or Varnish. Off by default.

C6. When enabled (default on), `X-Accel-Expires` carries T on PUBLIC and 0 on PRIVATE and RESPECT. nginx honours it ahead of `Cache-Control` and `fastcgi_cache_valid`, and strips it before the response reaches the client. This is the reliable way to give nginx a per-URL TTL (lesson L3).

C7. When enabled (default on), one custom header (default name `X-Edge-Cache`, configurable) carries `cache` on PUBLIC and `bypass` on PRIVATE and RESPECT. nginx can name it in `fastcgi_no_cache` so a PHP decision to bypass always wins, even if the nginx maps missed the case. The docs tell nginx to hide it from clients.

C8. Exactly one `Cache-Control` header leaves PHP. If WordPress core or another plugin queued one earlier and the outcome is PUBLIC or PRIVATE, the plugin replaces it rather than appending a second.

C9. On PUBLIC, the plugin removes `Expires` and `Pragma` headers that were queued by core's no-cache helper (for example the 1984 `Expires` core sends on 404s, C30). A public response must not also say "expired" or `no-cache`.

C10. On PUBLIC, `Last-Modified` and `ETag` set by core (feeds) are kept unchanged.

### 4.3 Template types and default TTLs

Classification runs in this order; the first match wins. Defaults are for the **steady** profile; the quiet and busy columns are in section 7.

C11. **Operator type map first.** A filter receives the request context and may return a type before any built-in check. This exists for custom rewrite endpoints that resolve through `index.php` and would otherwise look like the blog home (lesson L9).

C12. `robots`: the virtual `robots.txt` WordPress serves when there is no file on disk. Default 3600.

C13. `sitemap`: core sitemaps (`wp-sitemap*.xml`) and any path matching the sitemap pattern list (defaults cover common SEO-plugin and news-sitemap names). Default 300.

C14. `feed`: any feed, including comment feeds, category feeds and the main feed. Default 600.

C15. `embed`: oEmbed iframe views of a post. Default equals the singular post TTL.

C16. `search`: any request with a search query. Default 0, which means PRIVATE with reason `ttl-zero`. Search is expensive, has unbounded keys and is a favourite cache-busting target. Operators may set a positive TTL.

C17. `front_page`: the site front page, whether it shows posts or a static page. Default 300.

C18. `home`: the posts page when a static front page is set. Default 300.

C19. `singular_post`: a single post. Default 1800.

C20. `page`: a single page. Default 3600.

C21. `attachment`: an attachment page. Default 3600.

C22. `singular_other`: a single item of any other public post type. Default equals `singular_post`, overridable per post type.

C23. `category`, `tag`, `taxonomy`: term archives. Default 600 each.

C24. `author`: author archives. Default 900.

C25. `date`: year, month and day archives. Default 900.

C26. `post_type_archive`: archives of a custom post type. Default 600.

C27. Paged archives (`/page/2/` and deeper) use their archive's TTL. The page number is passed to the TTL filter so an operator can lengthen deep pages.

C28. `not_found`: a 404. Default 60.

C29. `other`: anything that rendered through the template loader and matched nothing above. Default 300. The debug header names it so operators notice and map it.

### 4.4 Interplay with WordPress core

C30. **Core's 404 no-cache.** WordPress calls its no-cache helper when it marks a request 404, before the plugin's main hook runs. The plugin observes the helper through its filter, records that core issued it for a 404, and treats it as owned: the outcome follows the `not_found` policy, and C8 and C9 apply.

C31. **Core's logged-in no-cache.** Core adds no-cache headers for logged-in users. The plugin's outcome for those requests is PRIVATE anyway (C36), so the two agree.

C32. **Any other call to core's no-cache helper** (by a plugin, a theme or core on a screen not listed above) during the request is a third-party no-cache: outcome RESPECT, reason `nocache-helper`.

C33. **`DONOTCACHEPAGE`.** If the constant is defined and true (the convention WooCommerce and many page-cache plugins use), outcome RESPECT if a `Cache-Control` is already queued, otherwise PRIVATE. Reason `donotcachepage`.

C34. **Upstream no-cache.** If a `Cache-Control` header is already queued, did not come from core's 404 helper, and contains `no-store`, `no-cache`, `private`, or `max-age=0` without `s-maxage`, the outcome is RESPECT, reason `upstream`. The plugin adds only `X-Accel-Expires: 0`, the bypass custom header and the debug header. This covers plugins that call `header()` directly, PHP sessions started with the default cache limiter (which queues `no-store, no-cache` and a 1981 `Expires`), and security plugins.

C35. **Upstream public header.** If a queued `Cache-Control` is cacheable (for example `public, max-age=600` from another plugin), the default is to override it with the plugin's decision. A setting and a filter switch this to RESPECT.

### 4.5 Bypass conditions (never shared-cached)

Every condition below yields PRIVATE unless C32 to C34 already yielded RESPECT. Each records a reason. All lists are filterable.

C36. Logged-in user (core says the current user is logged in). Reason `logged-in`.

C37. **Auth-ish cookie present, valid or not.** A request carrying any cookie whose name starts with a listed prefix is PRIVATE even if WordPress treated it as anonymous. A malformed or expired login cookie authenticates as anonymous, so checking login state alone would stamp a half-authenticated response public. Default prefixes: `wordpress_logged_in_`, `wordpress_sec_`, `wp-postpass_`, `comment_author_`, `woocommerce_items_in_cart`, `woocommerce_cart_hash`, `wp_woocommerce_session_`, `edd_items_in_cart`, `wp-resetpass-`. Reason `cookie:<name prefix>`. The cookie value is never read beyond its name.

C38. Comment cookie (`comment_author_*`): covered by C37. Commenters must see their own pending comment.

C39. Cart and checkout: cart cookies by C37; cart, checkout and account pages through a list of "never cache" condition callbacks that the plugin fills when WooCommerce or Easy Digital Downloads is active. Reason `commerce`.

C40. `Authorization` request header present (Basic, Bearer, application passwords). Reason `authorization`.

C41. Previews and builders: query arguments `preview`, `preview_id`, `preview_nonce`, `customize_changeset_uuid`, `customize_theme`, `customize_messenger_channel`, and page-builder preview arguments (default list includes the common Elementor, Beaver Builder, Divi, Oxygen and Bricks ones). Also the customiser preview state. Reason `preview`.

C42. Comment moderation views: query arguments `unapproved` and `moderation-hash`. Reason `moderation`.

C43. A `_wpnonce` query argument. A URL that carries a nonce was built for one user. Reason `nonce`.

C44. Password-protected content: a singular view of a post that has a password, whether or not the visitor has entered it. The form and the content are both PRIVATE. Reason `password`.

C45. Private, draft, pending, future and trashed posts: anonymous visitors get a 404 from core, handled by C28; a logged-in viewer is caught by C36. If a filter or plugin exposes such a post to an anonymous visitor, the plugin checks the queried post's status and returns PRIVATE, reason `non-public-status`.

C46. Request method other than GET or HEAD (POST, PUT, PATCH, DELETE, OPTIONS). Reason `method`. HEAD is treated exactly like GET.

C47. **Response sets a cookie.** If a `Set-Cookie` header is queued when the decision is made, the outcome is PRIVATE, reason `set-cookie:<cookie name>`. Cloudflare will not cache a response with `Set-Cookie`, nginx must not, and a cookie that is cached would be replayed to every reader. Cookies set after the decision (for example from inside the theme header) cannot be seen without output buffering; see L5 and Q3.

C48. Login, registration and account paths: `wp-login.php`, `wp-signup.php`, `wp-activate.php`, and any path prefix in the operator list (for custom login slugs and membership pages). Core already sends no-cache on `wp-login.php`; for the other paths the plugin returns PRIVATE, reason `path`.

C49. **SKIP, nothing sent:** wp-admin (including network admin), admin-ajax, `wp-cron.php`, XML-RPC, WP-CLI, and any request where the main WordPress query never runs. These never reach the plugin's main hook; the nginx contract bypasses them by URI (section 4.11).

C50. Requests with a debug token (C66) are forced PRIVATE after the would-be decision is computed, so a debug response is never cached. Reason `debug-token`.

C51. **Unsafe Vary.** If a queued `Vary` names anything other than `Accept-Encoding` (for example `User-Agent` from a mobile theme, `Cookie`, or `Accept-Language`), the outcome is PRIVATE, reason `unsafe-vary:<header>`, unless the operator declares that their caches honour `Vary`. Cloudflare ignores most `Vary` values for HTML, so it would serve one variant to everyone.

### 4.6 HTTP status codes

C52. Only statuses in the cacheable list may be PUBLIC. Default list: 200, 301, 404. The list is filterable (an operator may add 308 or 410).

C53. 200 uses the template TTL.

C54. 301 uses the `redirect` TTL, default 600, regardless of the target's template. This covers canonical redirects issued during `template_redirect`, which happen after the main decision; the plugin re-decides on the redirect hook (C61).

C55. 404 uses the `not_found` TTL (C28).

C56. 302, 303 and 307 are PRIVATE, reason `status:<code>`. Temporary redirects are often per-visitor (login, geo, A/B).

C57. 5xx responses (including core's 503 maintenance page and database error page where the plugin is loaded) are PRIVATE, reason `status:<code>`, so a CDN never stores an error and `stale-if-error` can serve the last good copy instead.

C58. Other 4xx (400, 401, 403, 410, 429) are PRIVATE unless added to the cacheable list.

C59. 304 Not Modified (core sends these for feeds on a matching `If-None-Match` or `If-Modified-Since`) carries the same `Cache-Control` the 200 would have carried.

### 4.7 Hook order: when the decision is made

C60. **Main decision on the `wp_headers` filter.** On WordPress 6.1 and later, core runs the main query and its 404 handling before it builds and sends headers, so conditional tags are reliable inside `wp_headers`. The plugin makes the decision there and adds its headers to the array core is about to send. It also hooks `send_headers` (after core has called `header()` for the array) only to remove the conflicting `Expires` and `Pragma` of C9 and to replace any duplicate `Cache-Control` queued directly with `header()`.

C61. **Redirects on `wp_redirect_status`.** Redirects issued later (canonical redirects at `template_redirect`, plugin redirects) pass through the redirect filters before the `Location` header is sent. The plugin re-runs the status part of the decision there (C54, C56) and replaces its own headers.

C62. **Late check on `template_include`.** This is the last hook before the theme prints anything. The plugin re-reads the queued headers and the response code. If a `Set-Cookie`, a new no-cache `Cache-Control`, an unsafe `Vary` or a non-cacheable status has appeared since the main decision, it downgrades PUBLIC to PRIVATE or RESPECT. It never upgrades.

C63. **Why not `template_redirect` alone or `send_headers` alone:** `template_redirect` is too early for redirects issued at its own default priority and too late to merge into the header array; `send_headers` fires after core has already called `header()`. The three-hook arrangement above is the contract.

C64. **Headers already sent.** If output has started before any of these hooks (a stray byte before `<?php`, a plugin echoing early), the plugin cannot send headers. It records the file and line PHP reports, logs once per request when debug logging is on, and does nothing else. The layers then fall back to their own defaults: nginx to its bypass maps and `fastcgi_cache_valid` ceilings, Cloudflare (with the recommended rule) to bypass because no `Cache-Control` is present. This is fail-closed at the edge.

### 4.8 REST API

C65. REST responses are decided on the REST pre-serve filter, using the same bypass conditions (cookies, `Authorization`, method, nonce in the query or the `X-WP-Nonce` header). Type `rest`, default TTL 60. Only GET and HEAD with a 200 are PUBLIC. Errors, the batch endpoint, any route under the authentication or user namespaces, and responses where core already sent its REST no-cache headers are PRIVATE or RESPECT. The `_jsonp` argument makes the response PRIVATE (the body depends on an unkeyed callback name). An operator can set per-namespace or per-route TTLs and a route deny list through filters.

### 4.9 Debug and explain

C66. **Debug header.** When debug mode is on, every decided response carries `X-Edge-Cache-Debug` with: outcome, type, TTL, stale windows, status, reasons in order, and the hook that made the final call. It never contains cookie values, user ids, nonces or query values. Debug mode is off by default. A per-request debug token (sent as a request header, compared in constant time) turns it on for one request without turning it on site-wide; such responses are PRIVATE (C50) and the header shows both the would-be and the forced outcome.

C67. **Explain from WP-CLI.** Given a URL, the plugin resolves it in-process to a query (the way the front end would), runs the same decision code, and prints the decision and the exact headers. Options simulate a method, cookie names, logged-in state and request headers. It reports what it cannot simulate (late cookies, redirects issued by templates) and offers a live mode that makes a real HEAD request with a one-time debug token and compares the emitted headers with the simulated ones. Exit status 0 on match, 1 on mismatch, 2 on error.

C68. **Explain from wp-admin.** The settings page has the same tool: paste a URL, see the decision, the headers and the reasons. Same simulation options, same live mode.

C69. **Generated configuration.** WP-CLI prints the nginx map and location fragments and the Cloudflare rule expression that match the current bypass lists, so the three layers are generated from one source.

### 4.10 Multisite

C70. As a must-use plugin it runs on every site in the network. As a normal plugin it can be network-activated or activated per site.

C71. Settings resolve per site: built-in defaults, then network defaults (set by a super admin), then the site's own settings, then constants, then filters.

C72. Cookie prefixes are the same on every site; subdomain and domain-mapped sites need no extra configuration. Network admin paths are SKIP (C49).

C73. Explain resolves the site from the URL's host and path; a URL that matches no site is an error (exit 2).

### 4.11 nginx contract

C74. The docs ship an nginx snippet that:
- keys the page cache on scheme, method, host and request URI only, never on request headers;
- bypasses on the same cookie prefixes (C37), the same paths (C48, C49), the same query arguments (C41 to C43), any `Authorization` header and any method other than GET and HEAD, generated from the plugin's lists (C69);
- does not store a response that sets a cookie;
- does not store a response whose bypass custom header (C7) says `bypass`;
- honours `X-Accel-Expires` as the per-URL TTL, keeps `fastcgi_cache_valid` as a ceiling for responses that lack it, and ignores `Cache-Control` and `Expires` from PHP only if the operator wants nginx to rely on `X-Accel-Expires` alone;
- serves stale on `updating`, `error`, `timeout` and 500, 503 with a background update and a cache lock;
- hides `X-Accel-Expires` (nginx default) and the custom header from clients, and exposes nginx's own cache status header for observability.

C75. If the operator keeps `fastcgi_ignore_headers Cache-Control` and turns off `X-Accel-Expires`, nginx uses `fastcgi_cache_valid` for every URL. The explain tool and docs warn about this combination (L3).

### 4.12 Cloudflare contract

C76. The docs ship a Cloudflare cache rule that:
- marks HTML on the zone eligible for cache (Cloudflare does not cache HTML by default, whatever the origin says);
- excludes the same paths and cookie prefixes as nginx;
- sets Edge TTL to "use cache-control header if present, bypass cache if not", so a missing header (C64) bypasses rather than caches;
- sets Browser TTL to "respect origin".
It also notes that a Cloudflare purge clears only Cloudflare, not nginx (L10), and points to Cloudflare's cache-key settings for query-string normalisation.

### 4.13 Failure modes

C77. A filter throws: caught, outcome PRIVATE, reason `error`, message logged when debug logging is on. Fail closed: a thrown filter may have been the one marking a page private.

C78. A filter returns an invalid value (negative, non-integer, non-numeric string, wrong list type): ignored, the default for that value is used, a notice is logged once per request in debug mode.

C79. A TTL of 0 from any source: PRIVATE, reason `ttl-zero`.

C80. TTLs above one year are clamped to one year; stale windows above one week are clamped to one week.

C81. Settings missing or corrupt: built-in defaults for the configured profile; the settings page shows a warning.

C82. Plugin disabled by constant or filter: SKIP everywhere, nothing sent, explain reports "disabled".

C83. Unknown template (C29): PUBLIC with the `other` TTL, not PRIVATE. An unknown public template is not a privacy risk because every bypass condition has already been checked; making it PRIVATE would silently disable caching for custom templates.

C84. A page-cache plugin serves the page before WordPress loads: this plugin does not run; its headers are absent. Documented, not handled.

## 5. Public interface

Names below are the public contract. The builder may add internals but not rename these without updating this section.

### Namespace and loader

- PHP namespace `IndianOpinion\EdgeCacheHeaders`.
- One loader file usable from `mu-plugins/` or as the plugin main file, with a standard plugin header.

### Value object

- A read-only Decision object exposing: outcome, type, TTL, browser max-age, stale-while-revalidate, stale-if-error, status, reasons (ordered list of short strings), deciding hook, and the final header map.
- A function returning the current request's Decision (or null before it is made).
- A function that explains a URL with simulation options and returns a Decision.

### Filters (all prefixed `edge_cache_headers_`)

| Filter | Value | Default |
|---|---|---|
| `enabled` | bool | true |
| `type` | string type, request context | from C11 to C29 |
| `ttl` | int seconds, type, context (includes page number, post type, REST route) | profile table |
| `ttls` | full type to TTL map | profile table |
| `browser_max_age` | int | 0 |
| `stale_while_revalidate` | int, TTL, type | 5 x TTL |
| `stale_if_error` | int, type | 86400 |
| `cacheable_statuses` | list of ints | 200, 301, 404 |
| `bypass_cookie_prefixes` | list of strings | C37 list |
| `bypass_query_args` | list of strings | C41 to C43 lists |
| `bypass_paths` | list of path prefixes | C48 list |
| `never_cache_conditions` | list of callables returning bool | commerce pages when present |
| `sitemap_patterns` | list of path patterns | common sitemap names |
| `respect_upstream_public` | bool | false (C35) |
| `vary` | list of header names | `Accept-Encoding` |
| `vary_is_honoured` | bool | false (C51) |
| `rest_ttl` | int, route, namespace | 60 |
| `rest_route_denylist` | list of route patterns | auth and user routes, batch |
| `custom_header_name` | string | `X-Edge-Cache` |
| `send_cdn_cache_control`, `send_surrogate_control`, `send_x_accel_expires`, `send_custom_header` | bool | off, off, on, on |
| `debug` | bool | false |
| `decision` | Decision, context | the computed decision; last chance to change it |
| `headers` | header map, Decision | the headers about to be sent |

### Action

- `edge_cache_headers_decided` fires once per decision with the Decision, for logging or metrics.

### Constants (optional, in `wp-config.php`)

- Disable switch; profile; posts per hour; debug on; debug token. A constant locks the matching setting in the admin page and shows it as read-only.

### WP-CLI (`wp edge-cache`)

- `explain <url>` with `--method`, `--cookie=<name>` (repeatable), `--logged-in`, `--header=<name:value>` (repeatable), `--live`, `--format=table|json`.
- `config` prints the effective settings and the TTL table, with the source of each value.
- `nginx` prints the matching nginx maps and location fragment.
- `cloudflare` prints the matching cache rule expression and settings.
- `token` rotates the debug token.

### Admin

- A settings page under Settings (per site) and under Network Settings (network defaults). Capability `manage_options` per site, `manage_network_options` for the network page. Nonces on every form and on the explain tool.
- Fields: profile or posts per hour, per-type TTL overrides, stale windows, browser max-age, optional headers, custom header name, extra cookie prefixes, paths and query arguments, debug mode, debug token rotation.

## 6. Data

- One option per site holding the settings array, autoloaded, under 4 KB.
- One network option for network defaults on multisite.
- The debug token is stored inside the settings option, never displayed after rotation except once.
- No custom tables, no post meta, no transients, no files, no logs of its own (it uses PHP's error log only in debug mode).
- Retention: settings persist until uninstall.
- Uninstall (normal plugin): removes the site option on every site and the network option. As a must-use plugin there is no uninstall hook; the docs list the two option names to remove by hand, and the `config` command can print them.

## 7. Configuration and sensible defaults

The operator sets a publishing rate in posts per hour. The profile follows:

- **quiet:** fewer than 1 post an hour.
- **steady:** 1 to 19 posts an hour (default if unset).
- **busy:** 20 or more posts an hour.

Default TTLs in seconds:

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
| robots | 86400 | 3600 | 3600 |
| rest | 300 | 60 | 60 |
| redirect (301) | 600 | 600 | 600 |
| other | 900 | 300 | 300 |

Other defaults:

- Browser max-age 0.
- stale-while-revalidate 5 x TTL. stale-if-error 86400.
- `X-Accel-Expires` on, custom header `X-Edge-Cache` on, `CDN-Cache-Control` off, `Surrogate-Control` off, debug off.
- Vary: `Accept-Encoding` only.
- Cacheable statuses: 200, 301, 404.

Why busy is not shorter than steady: at about 80 posts an hour a 60 s TTL bought almost no visible freshness but made every cache layer revalidate constantly against a small origin. With stale-while-revalidate the reader gets an instant response while the refresh happens behind them, so a few minutes of staleness on a page that gains two stories a minute is the right trade (L6).

## 8. Security and privacy

- The core property: no response generated for an identified visitor (session, cookie, `Authorization`, nonce, preview, password, cart) is ever marked PUBLIC. Every bypass check runs before any TTL is chosen, and the late check (C62) can only downgrade.
- Fail closed on errors (C77) and at the edge when headers cannot be sent (C64).
- Cookie values, nonces, user ids and query values are never read into the decision beyond names and presence, and never written to the debug header or logs.
- The decision never depends on unkeyed request headers other than the bypass ones (`Cookie`, `Authorization`, the debug token), which all lead to PRIVATE. This prevents cache poisoning through `X-Forwarded-Host`, `Accept` or `User-Agent`.
- Content negotiation on request headers (serving a different body for a different `Accept`) must use a distinct URL; the plugin marks unsafe `Vary` PRIVATE (C51).
- The debug token is random, at least 32 bytes, compared in constant time, and debug responses are PRIVATE.
- Admin page and explain tool: capability checks, nonces, all output escaped, the URL input validated and limited to the site's own hosts. Live mode only requests URLs on the current network, which prevents the tool being used as a request proxy.
- No `eval`, no shell, no remote calls on the front end.

## 9. Performance budget

- Front-end decision: under 0.1 ms median and 0.5 ms at p99 on a typical PHP 8.2 host, measured around the plugin's hooks.
- Zero database queries on the front end beyond the autoloaded option.
- No file reads after load, no remote calls, no output buffering by default.
- Memory under 200 KB.
- The explain tool and settings page may do more work; they run only for administrators.

## 10. Compatibility

- PHP 8.1, 8.2, 8.3.
- WordPress 6.4 and later (relies on the 6.1 change that runs the main query before headers are sent).
- Single site and multisite (subdirectory, subdomain, domain mapped).
- Web servers: nginx with `fastcgi_cache` or `proxy_cache`; Apache and LiteSpeed (headers work; the nginx contract does not apply).
- CDNs and caches: Cloudflare (standard cache rules; APO uses its own logic and is out of scope), Fastly and Varnish (via `Surrogate-Control` and `s-maxage`), any RFC 9111 shared cache.
- Plugins: WooCommerce and Easy Digital Downloads (cart cookies, `DONOTCACHEPAGE`, commerce pages), page builders (preview arguments), SEO plugins (sitemap patterns), page-cache plugins (respect rule, C84), purge plugins (coexist).

## 11. Observability

- The debug header (C66) on every decided response when enabled, or on one request with the token.
- `wp edge-cache explain --live` compares simulated and emitted headers and, when present, prints the CDN status (`cf-cache-status`, `Age`) and the nginx cache status header.
- The nginx snippet includes an access-log format that records the page-cache status per request, so hit ratio is measured from logs.
- In debug mode, failures (C64, C77, C78) go to PHP's error log once per request with a fixed prefix.
- The `edge_cache_headers_decided` action lets operators feed decisions into their own metrics.

## 12. Acceptance tests

Tests run under PHPUnit 10 against a stub layer for WordPress functions and a fake header queue (no full WordPress install). Live-mode and nginx tests are documented manual checks.

| Test | Covers | Given / then |
|---|---|---|
| T1 | C1, C53 | Anonymous GET of a single post, status 200: one `Cache-Control` with public, max-age 0, s-maxage 1800, swr 9000, sie 86400. |
| T2 | C2 | Same: `max-age=0` present. With browser max-age filtered to 60: `max-age=60`. |
| T3 | C1 | swr and sie filtered to 0: those directives absent, max-age and s-maxage present. |
| T4 | C3, C36 | Logged-in user: `private, no-store, max-age=0`, reason `logged-in`. |
| T5 | C4 | CDN-Cache-Control on: PUBLIC gives `max-age=1800` plus stale directives; PRIVATE gives `no-store`; off: header absent. |
| T6 | C5 | Surrogate-Control on and off, same as T5. |
| T7 | C6 | `X-Accel-Expires` is T on PUBLIC, 0 on PRIVATE and RESPECT; absent when off. |
| T8 | C7 | Custom header `cache` on PUBLIC, `bypass` on PRIVATE and RESPECT; renamed header uses the new name. |
| T9 | C8 | A plugin queued `Cache-Control: public, max-age=10`: exactly one `Cache-Control` remains, the plugin's. |
| T10 | C9, C28, C30, C55 | 404 after core's no-cache helper: one public `Cache-Control` with s-maxage 60; no `Expires`, no `Pragma`. |
| T11 | C10, C14 | Feed with core `Last-Modified` and `ETag`: both kept, s-maxage 600. |
| T12 | C11 | Type filter maps a custom query var to `page`: TTL 3600 although the request looks like home. |
| T13 | C12 | Virtual robots.txt: s-maxage 3600. |
| T14 | C13 | Core sitemap and a path matching a sitemap pattern: s-maxage 300. |
| T15 | C14 | Comment feed and category feed: type `feed`. |
| T16 | C15 | Embed view: TTL equals singular post TTL. |
| T17 | C16, C79 | Search: PRIVATE, reason `ttl-zero`. With search TTL 120: PUBLIC s-maxage 120. |
| T18 | C17, C18 | Front page with posts, static front page, posts page: types `front_page`, `front_page`, `home`; s-maxage 300. |
| T19 | C19, C20, C21 | Post 1800, page 3600, attachment 3600. |
| T20 | C22 | Custom post type single: equals post TTL; per-type override applies. |
| T21 | C23, C24, C25, C26 | Category, tag, custom taxonomy, author, date and post-type archives each get their type and TTL. |
| T22 | C27 | Category page 3: category TTL; the TTL filter receives page number 3. |
| T23 | C29, C83 | Unclassified template: PUBLIC, type `other`, s-maxage 300. |
| T24 | C31 | Logged-in with core's no-cache queued: PRIVATE, one `Cache-Control`. |
| T25 | C32 | A plugin calls core's no-cache helper on a normal post: RESPECT; plugin's `Cache-Control` not sent; `X-Accel-Expires: 0` and `bypass` sent. |
| T26 | C33 | `DONOTCACHEPAGE` true with no queued header: PRIVATE; with a queued no-cache header: RESPECT. |
| T27 | C34 | Queued `no-store, no-cache` from a PHP session: RESPECT, reason `upstream`. Queued `private`: RESPECT. Queued `max-age=0` without s-maxage: RESPECT. |
| T28 | C35 | Queued `public, max-age=600`: overridden by default; with respect setting on: RESPECT. |
| T29 | C37 | Each default cookie prefix, with a garbage value, user not logged in: PRIVATE, reason names the prefix. A cookie `_ga`: PUBLIC. |
| T30 | C37, C66 | Debug header and logs never contain a cookie value (assert value string absent). |
| T31 | C38 | `comment_author_` cookie: PRIVATE. |
| T32 | C39 | Commerce condition callback true (cart page): PRIVATE, reason `commerce`. |
| T33 | C40 | `Authorization: Bearer x`: PRIVATE. |
| T34 | C41 | Each preview and builder argument: PRIVATE, reason `preview`. Customiser preview state: PRIVATE. |
| T35 | C42 | `unapproved` and `moderation-hash`: PRIVATE. |
| T36 | C43 | `_wpnonce` in the query: PRIVATE. |
| T37 | C44 | Post with a password, no cookie: PRIVATE, reason `password`. |
| T38 | C45 | Queried post with status private, draft or future shown to an anonymous visitor: PRIVATE. |
| T39 | C46 | POST, PUT, DELETE, OPTIONS: PRIVATE. HEAD: same headers as GET. |
| T40 | C47 | `Set-Cookie` queued before the decision: PRIVATE, reason names the cookie, not its value. |
| T41 | C48 | `wp-signup.php`, `wp-activate.php` and an operator path prefix: PRIVATE, reason `path`. |
| T42 | C49 | Admin, ajax, cron, XML-RPC and CLI contexts: no header added by the plugin. |
| T43 | C50, C66 | Request with valid debug token: PRIVATE, debug header shows would-be PUBLIC and forced PRIVATE. Wrong token: no debug header, normal decision. |
| T44 | C51 | Queued `Vary: User-Agent`: PRIVATE, reason `unsafe-vary:User-Agent`. With vary honoured: PUBLIC and `Vary` merged. |
| T45 | C51 | Queued `Vary: Accept-Encoding` from another plugin: merged, not duplicated. |
| T46 | C52, C58 | 410 by default: PRIVATE. With 410 added to the list: PUBLIC with the type TTL. |
| T47 | C54, C61 | Canonical 301 issued after the main decision: headers replaced, s-maxage 600, type unchanged in reasons, status 301 recorded. |
| T48 | C56 | 302 and 307 redirects: PRIVATE, reason `status:302` and `status:307`. |
| T49 | C57 | 500 and 503: PRIVATE. |
| T50 | C59 | Feed 304 path: same `Cache-Control` as its 200. |
| T51 | C60, C63 | Decision made inside `wp_headers` uses conditional tags set by the stubbed query; headers appear in the filtered array. |
| T52 | C60, C9 | After `send_headers`, the header queue contains one `Cache-Control` and no stale `Expires` or `Pragma`. |
| T53 | C62 | `Set-Cookie` queued between `wp_headers` and `template_include`: downgraded to PRIVATE. A new no-cache header: RESPECT. A status change to 500: PRIVATE. |
| T54 | C62 | Late check never upgrades: PRIVATE stays PRIVATE when the late state looks clean. |
| T55 | C64 | Headers already sent: no `header()` calls, one log line in debug mode, none otherwise. |
| T56 | C65 | Anonymous REST GET 200: PUBLIC s-maxage 60, type `rest`. |
| T57 | C65 | REST with cookie, `Authorization`, `X-WP-Nonce`, `_jsonp`, non-GET, error status, batch route, users route: PRIVATE or RESPECT as specified. |
| T58 | C65 | Per-namespace TTL filter applies; denylisted route is PRIVATE. |
| T59 | C66 | Debug mode on: header present with outcome, type, TTL, reasons and hook; contains no cookie value, user id, nonce or query value. Debug off: header absent. |
| T60 | C67 | `explain` for a post URL prints PUBLIC 1800 and the header set; exit 0. With `--logged-in`: PRIVATE. With `--cookie=wordpress_logged_in_x`: PRIVATE. |
| T61 | C67 | `explain --live` mismatch (stubbed HTTP response with a different header): exit 1 and a diff. Invalid URL: exit 2. |
| T62 | C68 | Explain on the admin page: rejected without capability, rejected without a valid nonce, URL on a foreign host rejected, output escaped. |
| T63 | C69, C74 | `nginx` output contains every default cookie prefix, path and query argument, and the custom header name. Adding a cookie prefix by filter adds it to the output. |
| T64 | C69, C76 | `cloudflare` output contains the same cookie prefixes and paths and the "bypass if no cache-control" setting. |
| T65 | C70, C71 | Multisite: network default TTL used when a site has none; site override wins; constant wins over both; filter wins last. |
| T66 | C72 | Network admin request: SKIP. Subdomain site with the same cookie prefix: PRIVATE. |
| T67 | C73 | `explain` with a URL on no network site: exit 2. |
| T68 | C75 | `config` warns when `X-Accel-Expires` is off (nginx would use its fixed ceiling). |
| T69 | C77 | A filter throws: PRIVATE, reason `error`, request completes. |
| T70 | C78 | TTL filter returns -5, "abc", 3.7, null: default TTL used; one notice in debug mode. |
| T71 | C80 | TTL of 10 years clamped to one year; swr of 30 days clamped to one week. |
| T72 | C81 | Settings option is a string, not an array: defaults used; admin warning shown. |
| T73 | C82 | Disabled by constant: no headers anywhere; explain says disabled. |
| T74 | section 7 | Posts per hour 0.5, 5, 25 select quiet, steady, busy; each type's TTL matches the table. |
| T75 | section 6 | Uninstall removes the site option on every site and the network option, and nothing else. |
| T76 | section 9 | Benchmark: 10,000 decisions over a stubbed request stay under the budget; zero stubbed database calls. |
| T77 | C84 | Documented manual check: page served by a page-cache plugin lacks the headers; README FAQ entry exists. |
| T78 | C74, C76 | Documented manual check on a test stack: anonymous post gets nginx HIT on the second request and `cf-cache-status: HIT` within the TTL; a logged-in request gets BYPASS at both. |

Every case C1 to C84 appears in at least one row above.

## 13. Open questions

Answered by the builder in `docs/ARCHITECTURE.md`.

- Q1. Should the posts-per-hour setting be measured automatically (a daily count of published posts over the last 7 days), or stay a number the operator types? Automatic is friendlier but adds a scheduled query.
- Q2. Should singular TTLs grow with post age (a post older than a week gets a day), and if so, should that be a default or a filter recipe in the docs?
- Q3. Should there be an opt-in strict mode that buffers output from `template_include` and makes a final decision in the buffer callback, catching cookies and status changes set by the theme while it prints? What does it cost, and does it work with every server API?
- Q4. Should 308 join the default cacheable list alongside 301?
- Q5. On RESPECT, should the plugin add `CDN-Cache-Control: no-store` even though it leaves `Cache-Control` alone, or is the bypass custom header enough?
- Q6. Is a separate `robots` TTL needed when a physical `robots.txt` exists, or should the settings page say the file wins and hide the field?
- Q7. Live explain needs a loopback request. How should it behave on hosts that block loopback (report clearly and fall back to simulation)?

## 14. Lessons from production

- **L1. No header means no caching.** Without any `Cache-Control`, Cloudflare marked every HTML request DYNAMIC. Send a header on every anonymous response, and configure the CDN to bypass when it is missing rather than guess.
- **L2. Bypass on cookie presence, not login state.** An invalid or expired login cookie authenticates as anonymous. If PHP checks only login state it will mark a half-authenticated response public while nginx bypasses on the cookie. PHP and nginx must use the same cookie list.
- **L3. Tell nginx the TTL in a header it actually reads.** A config that ignores `Cache-Control` from PHP (common, because core sends no-cache everywhere) also ignores `s-maxage`; every URL then gets the fixed `fastcgi_cache_valid` ceiling, whatever the comments say. Use `X-Accel-Expires`.
- **L4. One `Cache-Control` per response.** nginx `expires` plus `add_header Cache-Control` sends two conflicting headers; so does PHP appending to a header core already queued. Replace, never append.
- **L5. A cookie on an anonymous page kills caching.** A theme view counter set a cookie on every anonymous article view, so the busiest template was never cached at the edge. Find cookie-setting code with the debug header; never let a cookie-setting response be stored; count views with a beacon instead.
- **L6. Longer TTLs than instinct suggests.** At about 80 posts an hour a 60 s TTL bought no visible freshness and kept the origin rebuilding. Five minutes with stale-while-revalidate was right.
- **L7. Bound the staleness.** nginx `updating` with a long `inactive` window handed the first visitor after a quiet hour a page an hour old on a short-TTL listing. For low-traffic short-TTL URLs, skip `updating` and rely on the lock.
- **L8. Search is a cache-busting sink.** Every search URL is unique, and a faceted search cost seconds of CPU. Do not cache it by default, and rate-limit it at nginx.
- **L9. Virtual pages can look like home.** A custom endpoint that resolves through `index.php` matched the blog-home checks and got the wrong TTL. Map custom query vars to a type first.
- **L10. A CDN purge does not purge nginx.** The next CDN miss refills from nginx's copy. Purge inside out, or rely on TTLs.
- **L11. Browsers must revalidate.** Keep browser max-age at 0 for HTML so a reader who logs in never sees an anonymous copy from disk.
- **L12. Content negotiation needs its own URL.** The page-cache key ignores request headers. Serving a different body for a different `Accept` header under the same URL stores the wrong body for the next reader; redirect to a distinct URL instead.
- **L13. Random query strings bypass every cache.** Cache-busting bot fleets append unique query strings. Normalise the cache key at the CDN, and block the fleets before they reach PHP.
- **L14. Touch only the headers you own.** Core appends its own `Link` headers after the header array is flushed. Merge `Vary`, leave `Link` and other headers alone.
