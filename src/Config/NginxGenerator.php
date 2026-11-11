<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders\Config;

use IndianOpinion\EdgeCacheHeaders\Policy;

/**
 * Builds the nginx `fastcgi_cache` maps and location fragment from the same
 * lists the plugin uses, so PHP and nginx bypass on exactly the same things.
 */
final class NginxGenerator
{
    /**
     * @param string $basePath Path WordPress lives under, '' for the domain root (for example '/blog').
     * @param bool $subdirectoryNetwork True on a subdirectory multisite: one optional site segment is allowed.
     */
    public function generate(Policy $policy, string $basePath = '', bool $subdirectoryNetwork = false, string $version = ''): string
    {
        $base = rtrim($basePath, '/');
        $site = $subdirectoryNetwork ? '(/[^/]+)?' : '';
        $headerVar = $policy->customHeaderVariable();

        $cookies = implode('|', array_map([self::class, 'quote'], $policy->bypassCookiePrefixes));
        $args = implode('|', array_map([self::class, 'quote'], $policy->bypassQueryArgs));
        $paths = implode('|', array_map(
            [self::class, 'quote'],
            array_merge(Policy::SKIP_PATHS, $policy->bypassPaths),
        ));

        $lines = [];
        $lines[] = '# wp-edge-cache-headers ' . ($version !== '' ? $version . ' ' : '') . 'nginx snippet.';
        $lines[] = '# Generated from the plugin settings. Regenerate after changing cookie, path or query lists.';
        $lines[] = '';
        $lines[] = '# ---- Part 1: http {} context ----';
        $lines[] = '';
        $lines[] = '# Requests carrying any of these cookies, valid or not, are never served from or stored in the cache.';
        $lines[] = 'map $http_cookie $edge_cache_cookie {';
        $lines[] = '    default 0;';
        $lines[] = '    "~*(^|;\s*)(' . $cookies . ')" 1;';
        $lines[] = '}';
        $lines[] = '';
        $lines[] = '# Login, account, admin, cron and XML-RPC paths.';
        $lines[] = 'map $request_uri $edge_cache_uri {';
        $lines[] = '    default 0;';
        $lines[] = '    "~*^' . self::quote($base) . $site . '(' . $paths . ')" 1;';
        $lines[] = '}';
        $lines[] = '';
        $lines[] = '# Preview, page-builder, moderation and nonce query arguments.';
        $lines[] = 'map $args $edge_cache_args {';
        $lines[] = '    default 0;';
        $lines[] = '    "~*(^|&)(' . $args . ')(=|&|$)" 1;';
        $lines[] = '}';
        $lines[] = '';
        $lines[] = 'map $request_method $edge_cache_method {';
        $lines[] = '    default 1;';
        $lines[] = '    GET 0;';
        $lines[] = '    HEAD 0;';
        $lines[] = '}';
        $lines[] = '';
        $lines[] = '# One switch. Any Authorization header or debug token also makes the string differ from "0000".';
        $lines[] = 'map "$edge_cache_cookie$edge_cache_uri$edge_cache_args$edge_cache_method$http_authorization'
            . '$http_' . str_replace('-', '_', strtolower(Policy::DEBUG_TOKEN_HEADER)) . '" $edge_cache_skip {';
        $lines[] = '    default 1;';
        $lines[] = '    "0000" 0;';
        $lines[] = '}';
        $lines[] = '';
        $lines[] = '# PHP decided to bypass: never store, even if the maps above missed the case.';
        $lines[] = 'map $upstream_http_' . $headerVar . ' $edge_cache_no_store {';
        $lines[] = '    default 0;';
        $lines[] = '    bypass 1;';
        $lines[] = '}';
        $lines[] = '';
        $lines[] = '# Page-cache status per request, for hit-ratio measurement from the access log.';
        $lines[] = "log_format edge_cache '\$remote_addr [\$time_local] \"\$request\" \$status "
            . "cache=\$upstream_cache_status rt=\$request_time';";
        $lines[] = '';
        $lines[] = '# ---- Part 2: inside the PHP location (location ~ \.php$ { ... }) ----';
        $lines[] = '';
        $lines[] = '# fastcgi_cache WORDPRESS;   # the keys_zone name from your fastcgi_cache_path line';
        $lines[] = 'fastcgi_cache_key "$scheme$request_method$host$request_uri";';
        $lines[] = 'fastcgi_cache_bypass $edge_cache_skip;';
        $lines[] = 'fastcgi_no_cache $edge_cache_skip $edge_cache_no_store $upstream_http_set_cookie;';
        $lines[] = '# Fallback only: X-Accel-Expires from PHP sets the real per-URL TTL.';
        $lines[] = 'fastcgi_cache_valid 200 301 308 404 10m;';
        $lines[] = '# Do not add "fastcgi_ignore_headers X-Accel-Expires": it is how PHP tells nginx the TTL.';
        $lines[] = '# fastcgi_ignore_headers Cache-Control Expires;   # only if nginx should rely on X-Accel-Expires alone';
        $lines[] = 'fastcgi_cache_use_stale updating error timeout http_500 http_503;';
        $lines[] = 'fastcgi_cache_background_update on;';
        $lines[] = 'fastcgi_cache_lock on;';
        $lines[] = 'fastcgi_cache_lock_timeout 5s;';
        $lines[] = 'fastcgi_hide_header ' . $policy->customHeaderName . ';';
        $lines[] = 'add_header X-Cache-Status $upstream_cache_status always;';
        $lines[] = '# access_log /var/log/nginx/access.log edge_cache;';
        $lines[] = '';
        return implode("\n", $lines);
    }

    /**
     * Escape a literal for a PCRE pattern inside a double-quoted nginx string.
     */
    public static function quote(string $literal): string
    {
        return str_replace('"', '\\"', preg_quote($literal, '#'));
    }
}
