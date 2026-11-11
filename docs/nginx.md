# nginx fastcgi_cache contract

The plugin decides in PHP. nginx must agree with it: same bypass cookies, same paths, same query arguments, and it must read the TTL PHP sends. Generate the snippet for your own settings with:

```sh
wp edge-cache nginx > /etc/nginx/snippets/edge-cache.conf.txt
```

Then copy part 1 into `http {}` and part 2 into the PHP `location`. The generated text below uses the default lists.

## What each part does

| Line | Why |
|---|---|
| `fastcgi_cache_key "$scheme$request_method$host$request_uri"` | Key on the URL only, never on request headers. Content negotiation needs its own URL. |
| `map $http_cookie $edge_cache_cookie` | Bypass on cookie presence, valid or not. An expired login cookie authenticates as anonymous in PHP; nginx and PHP must use the same list. |
| `map $request_uri $edge_cache_uri` | Admin, login, signup, activation, cron and XML-RPC. |
| `map $args $edge_cache_args` | Preview, page-builder, moderation and nonce arguments. |
| `map $request_method` | Only GET and HEAD are served from or stored in the cache. |
| `$http_authorization`, `$http_x_edge_cache_debug_token` | Any value makes the combined string differ from `0000`, so the request bypasses. The debug token bypass is what makes `wp edge-cache explain --live` reach PHP. |
| `fastcgi_no_cache ... $edge_cache_no_store $upstream_http_set_cookie` | Never store a response PHP marked `bypass`, or one that sets a cookie. |
| `X-Accel-Expires` (sent by PHP) | nginx reads it ahead of `Cache-Control` and `fastcgi_cache_valid`, and strips it from the response. It is the per-URL TTL. |
| `fastcgi_cache_valid 200 301 308 404 10m` | Fallback for responses without `X-Accel-Expires` (for example when output started before the plugin could send headers). |
| `fastcgi_cache_use_stale updating error timeout http_500 http_503` plus `fastcgi_cache_background_update on` | Readers never wait for a rebuild; an origin error serves the last good copy. |
| `fastcgi_cache_lock on` | One request rebuilds a missing page; the others wait for it. |
| `fastcgi_hide_header X-Edge-Cache` | The bypass signal is for nginx, not for clients. |
| `add_header X-Cache-Status $upstream_cache_status always` and `log_format edge_cache` | See HIT, MISS, BYPASS, STALE and UPDATING per request, and measure hit ratio from the access log. |

## Traps

- **Do not ignore `X-Accel-Expires`.** If nginx has `fastcgi_ignore_headers Cache-Control` (common, because core sends no-cache on many screens) and the plugin's `X-Accel-Expires` is off, every URL gets the fixed `fastcgi_cache_valid` TTL, whatever the plugin decides. `wp edge-cache config` warns about this.
- **One `Cache-Control` per response.** Do not add `expires` or `add_header Cache-Control` for PHP locations. The plugin already sends exactly one.
- **Bound the staleness.** `updating` with a long `inactive` window can hand the first visitor after a quiet hour a page an hour old. For low-traffic, short-TTL URLs, drop `updating` from `fastcgi_cache_use_stale` and rely on the lock.
- **Search.** Search is not cached by default. Rate-limit it with `limit_req` on `$arg_s`, because every search URL is unique.
- **Purge inside out.** A CDN purge does not touch nginx; the next CDN miss refills from nginx's old copy. Purge nginx first, or rely on TTLs.
- **Subdirectory installs and networks.** `wp edge-cache nginx` adds the install path and, on a subdirectory network, an optional site segment to the path map.

## Generated snippet (default lists)

```nginx
# wp-edge-cache-headers 0.1.0 nginx snippet.
# Generated from the plugin settings. Regenerate after changing cookie, path or query lists.

# ---- Part 1: http {} context ----

# Requests carrying any of these cookies, valid or not, are never served from or stored in the cache.
map $http_cookie $edge_cache_cookie {
    default 0;
    "~*(^|;\s*)(wordpress_logged_in_|wordpress_sec_|wp\-postpass_|comment_author_|woocommerce_items_in_cart|woocommerce_cart_hash|wp_woocommerce_session_|edd_items_in_cart|wp\-resetpass\-)" 1;
}

# Login, account, admin, cron and XML-RPC paths.
map $request_uri $edge_cache_uri {
    default 0;
    "~*^(/wp\-admin/|/wp\-cron\.php|/xmlrpc\.php|/wp\-login\.php|/wp\-signup\.php|/wp\-activate\.php)" 1;
}

# Preview, page-builder, moderation and nonce query arguments.
map $args $edge_cache_args {
    default 0;
    "~*(^|&)(preview|preview_id|preview_nonce|customize_changeset_uuid|customize_theme|customize_messenger_channel|elementor\-preview|elementor_library|fl_builder|et_fb|et_pb_preview|ct_builder|oxygen_iframe|bricks|bricks_preview|unapproved|moderation\-hash|_wpnonce)(=|&|$)" 1;
}

map $request_method $edge_cache_method {
    default 1;
    GET 0;
    HEAD 0;
}

# One switch. Any Authorization header or debug token also makes the string differ from "0000".
map "$edge_cache_cookie$edge_cache_uri$edge_cache_args$edge_cache_method$http_authorization$http_x_edge_cache_debug_token" $edge_cache_skip {
    default 1;
    "0000" 0;
}

# PHP decided to bypass: never store, even if the maps above missed the case.
map $upstream_http_x_edge_cache $edge_cache_no_store {
    default 0;
    bypass 1;
}

# Page-cache status per request, for hit-ratio measurement from the access log.
log_format edge_cache '$remote_addr [$time_local] "$request" $status cache=$upstream_cache_status rt=$request_time';

# ---- Part 2: inside the PHP location (location ~ \.php$ { ... }) ----

# fastcgi_cache WORDPRESS;   # the keys_zone name from your fastcgi_cache_path line
fastcgi_cache_key "$scheme$request_method$host$request_uri";
fastcgi_cache_bypass $edge_cache_skip;
fastcgi_no_cache $edge_cache_skip $edge_cache_no_store $upstream_http_set_cookie;
# Fallback only: X-Accel-Expires from PHP sets the real per-URL TTL.
fastcgi_cache_valid 200 301 308 404 10m;
# Do not add "fastcgi_ignore_headers X-Accel-Expires": it is how PHP tells nginx the TTL.
# fastcgi_ignore_headers Cache-Control Expires;   # only if nginx should rely on X-Accel-Expires alone
fastcgi_cache_use_stale updating error timeout http_500 http_503;
fastcgi_cache_background_update on;
fastcgi_cache_lock on;
fastcgi_cache_lock_timeout 5s;
fastcgi_hide_header X-Edge-Cache;
add_header X-Cache-Status $upstream_cache_status always;
# access_log /var/log/nginx/access.log edge_cache;
```

## Manual check (acceptance test T78)

On a test stack with the snippet in place:

```sh
curl -sI https://example.com/hello-world/ | grep -i x-cache-status   # MISS
curl -sI https://example.com/hello-world/ | grep -i x-cache-status   # HIT
curl -sI -H 'Cookie: wordpress_logged_in_x=1' https://example.com/hello-world/ | grep -i x-cache-status   # BYPASS
```
