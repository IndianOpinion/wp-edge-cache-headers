=== Edge Cache Headers ===
Contributors: indianopinion
Tags: cache-control, cloudflare, nginx, cdn, cache
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Per-template Cache-Control so Cloudflare and nginx can cache anonymous HTML, and never a logged-in, preview, cart or cookie-setting page.

== Description ==

WordPress sends no Cache-Control header on a normal anonymous page, so a CDN treats it as dynamic and an nginx page cache falls back to one blunt TTL. This plugin decides once per request, in PHP, and sends one correct header set:

* A TTL per template type (front page, posts, pages, archives, feeds, sitemaps, robots.txt, REST and more), tuned to how many posts an hour the site publishes.
* stale-while-revalidate and stale-if-error, so readers never wait for a rebuild.
* X-Accel-Expires for nginx and an optional CDN-Cache-Control and Surrogate-Control.
* Private, no-store for logged-in visitors, any login, comment or cart cookie (valid or not), Authorization headers, previews and page builders, nonces, password-protected posts, non-GET methods, responses that set a cookie, and unsafe Vary.
* A debug header and a WP-CLI and wp-admin explain tool: "what header does this URL get, and why?"
* Generated nginx fastcgi_cache snippet and Cloudflare cache rule from the same lists, so the three layers agree.

Works as a normal plugin and as a must-use plugin, on single sites and multisite.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

== Installation ==

1. Upload the plugin to wp-content/plugins and activate it, or install it as a must-use plugin with the loader in examples/mu-plugin-loader.php.
2. Run `wp edge-cache suggest-rate --apply` or set posts per hour under Settings, Edge cache headers.
3. Install the nginx snippet from `wp edge-cache nginx` and the Cloudflare rule from `wp edge-cache cloudflare`.
4. Check a page with `wp edge-cache explain https://example.com/a-post/`.

== Frequently Asked Questions ==

= Why is the browser max-age 0? =

So a reader who logs in never sees an anonymous copy from their own browser cache. Shared caches still hold the page for s-maxage.

= Does it purge? =

No. It sends headers; expiry plus stale-while-revalidate is the model. Purge plugins can coexist.

= A page-cache plugin serves my pages and the headers are missing. =

Page-cache plugins that serve before WordPress loads skip this plugin. Its headers appear only on responses WordPress renders.

= How do I remove its data? =

Deleting the plugin removes its options. For a must-use install, delete edge_cache_headers_settings and edge_cache_headers_network by hand.

== Changelog ==

= 0.1.0 =
* First release.
