<?php

/**
 * Filter recipes. Copy what you need into a must-use plugin of your own.
 *
 * @package wp-edge-cache-headers
 */

declare(strict_types=1);

use IndianOpinion\EdgeCacheHeaders\Decision;
use IndianOpinion\EdgeCacheHeaders\RequestFacts;

if (!defined('ABSPATH')) {
    exit;
}

// A custom endpoint served through index.php looks like the blog home. Map it first.
add_filter('edge_cache_headers_type', static function (string $type, RequestFacts $facts): string {
    return get_query_var('my_dashboard') !== '' ? 'page' : $type;
}, 10, 2);

// Deep archive pages change rarely: give page 5 and beyond an hour.
add_filter('edge_cache_headers_ttl', static function (int $ttl, string $type, RequestFacts $facts): int {
    return $facts->paged >= 5 && in_array($type, ['category', 'tag', 'taxonomy'], true) ? 3600 : $ttl;
}, 10, 3);

// A membership plugin uses its own session cookie.
add_filter('edge_cache_headers_bypass_cookie_prefixes', static function (array $prefixes): array {
    $prefixes[] = 'my_members_session';
    return $prefixes;
});

// Never cache a route of your own REST namespace, and give the rest five minutes.
add_filter('edge_cache_headers_rest_route_denylist', static function (array $routes): array {
    $routes[] = '/myapp/v1/me';
    return $routes;
});
add_filter('edge_cache_headers_rest_ttl', static function (int $ttl, ?string $route, ?string $namespace): int {
    return $namespace === 'myapp/v1' ? 300 : $ttl;
}, 10, 3);

// Cache 410 Gone like a 404.
add_filter('edge_cache_headers_cacheable_statuses', static function (array $statuses): array {
    $statuses[] = 410;
    return $statuses;
});

// Send every decision to your own metrics.
add_action('edge_cache_headers_decided', static function (Decision $decision): void {
    // my_metrics_increment('edge_cache.' . strtolower($decision->outcome->value) . '.' . $decision->type);
});
