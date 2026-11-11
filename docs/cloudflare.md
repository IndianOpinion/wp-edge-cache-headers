# Cloudflare cache rule

Cloudflare does not cache HTML by default, whatever the origin says. One cache rule makes WordPress HTML eligible, excludes the same paths and cookies as the plugin and nginx, and tells Cloudflare to follow the origin's `Cache-Control`. Generate it for your own settings with:

```sh
wp edge-cache cloudflare
```

## Rule settings

In the dashboard: Caching, Cache Rules, Create rule.

| Setting | Value | Why |
|---|---|---|
| When incoming requests match | the expression below (Edit expression) | Same exclusions as the plugin and nginx. |
| Cache eligibility | Eligible for cache | HTML is not cached without this. |
| Edge TTL | Use cache-control header if present, bypass cache if not (API: `edge_ttl.mode` = `bypass_by_default`) | A response without the plugin's header (output started early, plugin disabled) bypasses instead of being cached with a guess. Do not pick "use default Cloudflare caching behavior if not" (`respect_origin`). |
| Browser TTL | Respect origin (API: `browser_ttl.mode` = `respect_origin`) | Browser `max-age` stays 0 for HTML, so a reader who logs in never sees an anonymous copy from disk. |

## Expression (default lists)

```text
(http.host in {"example.com" "www.example.com"}
 and not (http.request.uri.path contains "/wp-admin/")
 and not (http.request.uri.path contains "/wp-cron.php")
 and not (http.request.uri.path contains "/xmlrpc.php")
 and not (http.request.uri.path contains "/wp-login.php")
 and not (http.request.uri.path contains "/wp-signup.php")
 and not (http.request.uri.path contains "/wp-activate.php")
 and not (http.cookie contains "wordpress_logged_in_")
 and not (http.cookie contains "wordpress_sec_")
 and not (http.cookie contains "wp-postpass_")
 and not (http.cookie contains "comment_author_")
 and not (http.cookie contains "woocommerce_items_in_cart")
 and not (http.cookie contains "woocommerce_cart_hash")
 and not (http.cookie contains "wp_woocommerce_session_")
 and not (http.cookie contains "edd_items_in_cart")
 and not (http.cookie contains "wp-resetpass-")
 and not any(http.request.uri.args.names[*] in {"preview" "preview_id" "preview_nonce" "customize_changeset_uuid" "customize_theme" "customize_messenger_channel" "elementor-preview" "elementor_library" "fl_builder" "et_fb" "et_pb_preview" "ct_builder" "oxygen_iframe" "bricks" "bricks_preview" "unapproved" "moderation-hash" "_wpnonce"})
 and not any(http.request.headers["authorization"][*] ne "")
 and not any(http.request.headers["x-edge-cache-debug-token"][*] ne ""))
```

Paste it with "Edit expression" (parentheses work there, not in the Expression Builder) and check that the editor accepts it before saving. The path clauses use `contains` rather than "starts with", so subdirectory installs and network sites are covered without changes.

Fields used, all from the public Rules language field reference:

| Field | Type | Note |
|---|---|---|
| `http.host` | String | Listed for Cache Rules as "Hostname". |
| `http.request.uri.path` | String | Listed for Cache Rules as "URI Path". |
| `http.cookie` | String | The whole `Cookie` header. Listed for Cache Rules as "Cookie". |
| `http.request.uri.args.names` | Array of String | Argument names, case kept as sent. |
| `http.request.headers` | Map of Array of String | Keys are lower-case header names. Listed for Cache Rules as "Request Headers". |

Each `not (...)` is parenthesised so the negation is unambiguous. The two `any(...)` clauses follow the documented `any(field[*] ...)` form; if your editor rejects one, drop that clause: the origin already answers those requests with `private, no-store`.

## How it fits with the plugin

- Cloudflare takes the edge TTL from `s-maxage`. It also reads `stale-while-revalidate` and `stale-if-error`, with caveats from its Origin Cache Control page: it advises against combining `s-maxage` with `stale-while-revalidate`, it ignores both stale directives when Always Online is on, and `stale-if-error` applies only to 5xx answers from the origin. If you want Cloudflare to serve stale while it revalidates, turn on `CDN-Cache-Control`: Cloudflare reads it ahead of `Cache-Control`, and the plugin puts `max-age=T` plus the stale directives in it, without `s-maxage`. The cache rule's own "Serve stale content while revalidating" setting must stay on. nginx honours both directives either way.
- `CDN-Cache-Control` (off by default) is read ahead of `Cache-Control` when present. On RESPECT the plugin always sends `CDN-Cache-Control: no-store`, so Cloudflare never stores a response another component marked no-cache.
- Cloudflare never caches a response that sets a cookie. The plugin also marks such a response PRIVATE.
- Cloudflare ignores most `Vary` values on HTML. The plugin marks a response PRIVATE when another component adds `Vary` on anything except `Accept-Encoding`, unless you declare that your caches honour it.

## Notes

- A Cloudflare purge clears Cloudflare only. The next miss refills from nginx. Purge nginx first, or rely on TTLs.
- Normalise query strings (`utm_*`, `fbclid`, random cache-busters) in Cloudflare's cache-key settings or a Transform Rule, not in the plugin. Block cache-busting bot fleets before they reach PHP.
- Automatic Platform Optimization (APO) uses its own logic and is out of scope.
- A broader rule elsewhere in the zone ("cache everything") can override this one. Keep this rule the only one that makes HTML eligible.

## Manual check (acceptance test T78)

```sh
curl -sI https://example.com/hello-world/ | grep -i cf-cache-status   # MISS, then HIT within the TTL
curl -sI -H 'Cookie: wordpress_logged_in_x=1' https://example.com/hello-world/ | grep -i cf-cache-status   # DYNAMIC or BYPASS
```
