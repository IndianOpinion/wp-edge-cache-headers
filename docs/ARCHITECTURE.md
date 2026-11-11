# Architecture

This document describes how wp-edge-cache-headers 0.1.0 is built and records every decision taken on the open questions in [PRD.md](PRD.md). Case numbers (C1 to C84) and test numbers (T1 to T78) refer to the PRD.

## Shape

Two layers with one rule between them: the engine never calls WordPress.

```
            WordPress request
                   |
   +---------------v----------------+     WordPress layer (src/WordPress)
   | Plugin: hooks, state, logging  |
   | Settings -> PolicyFactory      |---> Policy (TTLs, lists, switches)
   | FactsCollector                 |---> RequestFacts (names and flags only)
   +---------------+----------------+
                   |
   +---------------v----------------+     Pure core (src/)
   | DecisionEngine                 |
   |   classify -> respect/private  |
   |   -> status -> TTL -> headers  |---> Decision (outcome, type, TTL, reasons, header map)
   +---------------+----------------+
                   |
   +---------------v----------------+
   | HeaderApplier                  |---> wp_headers array, then the PHP header queue
   +--------------------------------+
```

### Pure core (`src/`, no WordPress calls)

| Class | Role |
|---|---|
| `RequestFacts` | Everything known about one request: context, method, status, cookie and query argument names, conditional tags that are true, queried post type, status and password flag, queued `Cache-Control`, `Set-Cookie` names and `Vary` names, no-cache helper ownership, REST route. Never values. |
| `Policy` | Resolved configuration: TTL table, per post type TTLs, stale windows, lists, header switches, debug, strict mode, plus errors and notices met while building it. |
| `Profile` | The quiet, steady and busy TTL tables (PRD section 7) and the posts-per-hour thresholds. |
| `DecisionEngine` | `decide(facts, policy, hook)` and `lateCheck(previous, facts, policy, hook)`. Calls extension points through the `Hooks` interface. |
| `Decision` | Read-only result. `with()` returns a modified copy. |
| `Outcome` | PUBLIC, PRIVATE, RESPECT, SKIP, with a strictness rank used by the late check. |
| `CacheControl` | Header parsing helpers (no-cache detection, cookie names from `Set-Cookie`, `Vary` names). |
| `DebugToken` | Random 32-byte tokens, SHA-256 storage form, constant-time checks. |
| `Config\NginxGenerator`, `Config\CloudflareGenerator` | Build the nginx snippet and the Cloudflare rule from the same `Policy` lists the engine uses (C69). |
| `Explain\LiveComparison` | Compares a simulated decision with a live response's debug header. |
| `RateAdvisor` | Posts counted over a window to a posts-per-hour suggestion. |

Because the engine is pure, most tests exercise it directly with plain objects, and `examples/standalone-engine.php` runs it with plain PHP.

### WordPress layer (`src/WordPress/`)

| Class | Role |
|---|---|
| `Plugin` | Registers hooks, holds the request's decision, applies headers, logs in debug mode. |
| `Settings` | Loads the site option and the network option, applies constants, sanitises input, records the source of every value. |
| `PolicyFactory` | Settings plus settings-level filters to a `Policy`. A throwing filter is recorded as an error. |
| `FactsCollector` | Reads conditional tags, `$_SERVER`, `$_GET` and `$_COOKIE` keys, and the header queue. Accepts synthetic inputs so the explain tool can reuse it. |
| `NocacheObserver` | Watches core's no-cache helper through the `nocache_headers` filter and decides whether a call is core's own (logged-in, or a 404 before the main decision) or a third party's. |
| `HeaderQueue`, `PhpHeaderQueue`, `FakeHeaderQueue` | The response header queue. PHP's functions in production; an in-memory queue for tests and for the explain tool, so a simulation never touches the real response. |
| `HeaderApplier` | Writes a decision into the `wp_headers` array or the queue. Replaces, never appends; removes only `Expires` and `Pragma`; on a downgrade to RESPECT removes only its own `Cache-Control`. |
| `Explainer`, `WpQueryResolver`, `WpHttpClient` | The explain tool (C67, C68). |
| `Cli\Command` | `wp edge-cache explain|config|nginx|cloudflare|token|suggest-rate`. |
| `Admin\SettingsPage` | Settings page per site, network defaults page, explain form. |
| `Uninstaller` | Deletes the two options and nothing else. |

## Decision order

Inside `DecisionEngine::decide()`:

1. Disabled by constant or filter: SKIP, reason `disabled` (C82).
2. Context other than front end or REST: SKIP (C49).
3. Classify the type in PRD order (C12 to C29). The `type` filter receives the built-in type and the facts and may return another (C11).
4. RESPECT reasons: third-party no-cache helper (C32), `DONOTCACHEPAGE` with a queued header (C33), queued no-cache `Cache-Control` (C34), queued cacheable header with the respect switch on (C35).
5. PRIVATE reasons, all checked before any TTL is used: `DONOTCACHEPAGE` alone, logged in, cookie prefixes, never-cache conditions, `Authorization`, preview, moderation and nonce arguments, customiser preview, REST nonce, `_jsonp` and route denylist, password, non-public status, method, `Set-Cookie`, path prefixes, unsafe `Vary` (C36 to C51).
6. Status: 304 is treated as 200 (C59). REST is cacheable only on 200. Otherwise the status must be in the cacheable list, and 5xx never is (C52 to C58).
7. TTL: REST TTL then `rest_ttl`; 3xx uses the `redirect` TTL; 404 uses `not_found`; `singular_other` may use a per post type TTL; then the `ttl` filter. Stale windows default to 5 x TTL and 86400. Every filtered value is validated (non-negative int or a string of digits) and clamped to a year or a week (C78, C80). TTL 0 adds `ttl-zero` (C79).
8. Any error (a filter threw, a never-cache condition threw, a settings filter threw) makes the outcome PRIVATE with reason `error` (C77). Otherwise RESPECT beats PRIVATE beats PUBLIC.
9. A valid debug token forces PRIVATE and records the would-be outcome (C50).
10. The `decision` filter may replace the decision; the header map is built; the `headers` filter may change it. Header names are validated and values with line breaks are dropped.

## Hook timeline

| Hook | Priority | What happens |
|---|---|---|
| `nocache_headers` filter | `PHP_INT_MAX` | Observe the helper; record core-owned `Cache-Control` values. |
| `wp_headers` filter | 999 | Main decision. Headers merged into core's array (C60). |
| `send_headers` action | 999 | Re-apply the decision to the PHP queue: one `Cache-Control`, no stale `Expires` or `Pragma` (C8, C9). |
| `wp_redirect_status` filter | 999 | Re-decide with the redirect status (C54, C56, C61). |
| `template_include` filter | `PHP_INT_MAX` | Late check: only downgrades (C62). Strict mode starts its buffer here. |
| output buffer callback | n/a | Strict mode only: one more late check before the first byte leaves. |
| `rest_pre_serve_request` filter | 999 | REST decision after the server has queued its headers (C65). |

If output has already started at any of these points, the plugin sends nothing, logs the file and line once in debug mode, and lets nginx and Cloudflare fall back (C64).

## Data

- `edge_cache_headers_settings`: one autoloaded option per site. Only values that differ from the inherited defaults are stored, so a later change to a network default reaches every site that did not override it.
- `edge_cache_headers_network`: network defaults on multisite.
- The debug token is stored as a SHA-256 hash inside the site option. The one-time live-explain token is stored the same way with a 120 second expiry and cleared as soon as the request returns. No tables, transients, post meta, files or logs of its own.

## Decisions on the open questions

**Q1. Posts per hour: measured or typed?** Typed. The operator sets the number (or a profile) in the settings page, a constant or the option. `wp edge-cache suggest-rate [--days=7] [--post-type=post] [--apply]` counts published posts in the window and suggests a value, and `--apply` saves it. No scheduled query runs on the site.

**Q2. TTL growth with post age.** Not in 0.1.0. See "Not yet" below. The `ttl` filter receives the facts, so an operator can already lengthen TTLs for old posts with a few lines of their own.

**Q3. Strict mode.** Opt-in, off by default (setting `strict_mode`, filter `edge_cache_headers_strict_mode`, constant `EDGE_CACHE_HEADERS_STRICT`). When on and the decision is PUBLIC at `template_include`, the plugin starts an output buffer whose callback runs one more late check before the first byte is sent, catching cookies, no-cache headers, `Vary` and status changes made while the theme prints. Costs: the whole page is held in memory until the end of the request, time to first byte becomes time to last byte for PHP, and anything that flushes early (a theme calling `flush()`, streaming responses) sends headers before the callback runs, in which case the check cannot act. It works on PHP-FPM, mod_php and LiteSpeed SAPIs, which all buffer headers until the first output.

**Q4. 308.** Cacheable by default, like 301. The default cacheable list is 200, 301, 308, 404. Both use the `redirect` TTL.

**Q5. `CDN-Cache-Control` on RESPECT.** Yes. When the plugin leaves another component's no-cache `Cache-Control` in place, it always sends `CDN-Cache-Control: no-store`, whatever the `send_cdn_cache_control` switch says. A CDN that reads that header first must never store a response another component marked no-cache.

**Q6. Robots TTL with a physical file.** The `robots` TTL applies only when WordPress serves the virtual `robots.txt`. A physical file is served by the web server and never reaches PHP. The settings page says so next to the TTL fields.

**Q7. Live explain when loopback is blocked.** The live mode tries a HEAD request with a one-time debug token. If the request fails (blocked loopback, DNS, TLS, timeout), the tool falls back to the simulated explanation, reports mode `simulated` and adds a note with the error. A response without the debug header (served by a cache that ignores the token, or the plugin did not run) is reported as a mismatch with that explanation. `--live` cannot be combined with `--logged-in`.

## Other decisions

- **Template type filter receives the built-in type.** C11 asks for the operator map to win over built-in checks. Computing the built-in type first and passing it to the filter gives the same result and lets the filter fall through by returning what it was given.
- **Debug header grammar.** `outcome=...; would-be=...; type=...; ttl=...; swr=...; sie=...; status=...; hook=...; reasons=a,b`. Reasons hold cookie prefixes from the list, never the full cookie name. The live comparison parses this same header.
- **Reason for an upstream header that was overridden.** A PUBLIC decision that replaced another component's cacheable `Cache-Control` carries the reason `upstream-overridden`, so the debug header shows it.
- **Sitemap patterns** are globs (`*` and `?` within one path segment) matched against the path relative to the site root.
- **Path prefixes** are matched case-insensitively against the path relative to the site root, so they work on subdirectory installs and network sites.
- **REST route denylist** entries are route prefixes. Defaults: `/batch/v1`, `/wp/v2/users`, `/wp/v2/settings`, `/wp/v2/application-passwords`, `/jwt-auth/`, `/oauth1`, `/oauth2`, `/wp-site-health/`.
- **5xx is never cacheable**, even when a filter adds it to the list, so `stale-if-error` can serve the last good copy.
- **No-cache helper ownership.** A call while the visitor is logged in, or while the main query is a 404 before the main decision, is core's. Any other call is a third party's. A plugin that calls the helper on a 404 page before the main decision is therefore treated as core; the outcome is still never PUBLIC for a logged-in visitor.
- **The `decision` filter can upgrade.** It is the operator's last word. The README warns that returning PUBLIC for an identified visitor defeats the plugin.
- **Explain's 404 rule.** The in-process explain does not call core's `handle_404()` (it sends headers). It mirrors it: no posts, not search, robots, favicon, home or sitemap, and not an archive whose term or author exists, means 404. A canonical redirect is detected with `redirect_canonical( $url, false )` and reported as a simulated 301.
- **`X-Accel-Expires` switch covers every outcome.** When it is off the header is absent on PUBLIC, PRIVATE and RESPECT alike (C6, T7). nginx then falls back to `Cache-Control` and the bypass signal header.
- **Status from the `error` query var.** Core sets the status for a request its parser marked as an error (`?error=500`) only after `wp_headers`, so the main decision reads the status from the `WP` object core passes to that filter.
- **Late check restores the header it owns.** If the late check keeps PUBLIC but another component has replaced the plugin's `Cache-Control` with `header()` since `send_headers`, the plugin's headers are applied again, so exactly one `Cache-Control` leaves PHP (C8).
- **Redirects with an empty location** are ignored: core sends nothing for them, so the decision stays as it was.
- **Plugin header has no `Network: true`.** The plugin can be network-activated or activated per site (C70).
- **Explain from wp-admin** resolves the query inside an admin request, where WordPress includes some non-public statuses in queries. WP-CLI is the reference tool.

## Not yet

- TTL growth with post age (Q2).
- Purging, cache key normalisation and static assets stay non-goals.
- An admin-side live hit-ratio view. The nginx access-log format and the `edge_cache_headers_decided` action are the supported ways to measure.

## Testing

PHPUnit 10 runs against `tests/stubs/`, a small stand-in for the WordPress functions and classes the plugin calls, and a fake header queue. Unit tests cover the engine, generators and helpers; integration tests drive the real hooks in the order core fires them. Tests that need constants run in separate processes. T77 and T78 are manual checks documented in the README and in `docs/nginx.md` and `docs/cloudflare.md`.
