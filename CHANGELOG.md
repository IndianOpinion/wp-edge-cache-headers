# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-11-11

### Added

- Pure decision engine (`DecisionEngine`): request facts and policy in, a `Decision` with its header set out. No WordPress calls.
- Outcomes PUBLIC, PRIVATE, RESPECT and SKIP, with an ordered list of reasons.
- Per-template TTLs for 20 template types plus redirects, in quiet, steady and busy profiles chosen from posts per hour.
- `stale-while-revalidate` (default 5 x TTL) and `stale-if-error` (default one day), clamped to one week; TTLs clamped to one year.
- `X-Accel-Expires` and an `X-Edge-Cache: cache|bypass` signal header (on by default), `CDN-Cache-Control` and `Surrogate-Control` (off by default). `CDN-Cache-Control: no-store` is always sent on RESPECT.
- Bypass on login state, cookie prefixes (valid or not), commerce pages, `Authorization`, previews and page builders, moderation views, nonces, passwords, non-public statuses, methods, `Set-Cookie`, login paths and unsafe `Vary`.
- Cacheable statuses 200, 301, 308 and 404 by default; 5xx never cacheable.
- Hook contract on `wp_headers`, `send_headers`, `wp_redirect_status`, `template_include` and `rest_pre_serve_request`, with recognition of core's own no-cache calls.
- Opt-in strict mode that buffers output and makes a final late check.
- REST API support with per-route TTLs and a route denylist.
- Debug header and per-request debug token stored as a hash.
- `wp edge-cache explain|config|nginx|cloudflare|token|suggest-rate`, with a live explain mode that falls back to simulation when loopback requests are blocked.
- Settings page per site, network defaults page and an explain form in wp-admin.
- Generated nginx `fastcgi_cache` snippet and Cloudflare cache rule.
- Must-use loader, uninstall cleanup, filters for every value and list, and the `edge_cache_headers_decided` action.

[Unreleased]: https://github.com/IndianOpinion/wp-edge-cache-headers/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/IndianOpinion/wp-edge-cache-headers/releases/tag/v0.1.0
