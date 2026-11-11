<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders\Config;

use IndianOpinion\EdgeCacheHeaders\Policy;

/**
 * Builds the Cloudflare cache rule expression and settings that match the
 * plugin's bypass lists.
 */
final class CloudflareGenerator
{
    /** Dashboard wording; the API mode is `bypass_by_default`. */
    public const EDGE_TTL = 'Use cache-control header if present, bypass cache if not';
    /** Dashboard wording; the API mode is `respect_origin`. */
    public const BROWSER_TTL = 'Respect origin';

    /**
     * @param list<string> $hosts Host names the rule applies to.
     */
    public function expression(Policy $policy, array $hosts): string
    {
        $clauses = [];
        $hosts = array_values(array_filter($hosts, static fn (string $h): bool => $h !== ''));
        if ($hosts !== []) {
            $clauses[] = 'http.host in {' . implode(' ', array_map([self::class, 'literal'], $hosts)) . '}';
        }
        foreach (array_merge(Policy::SKIP_PATHS, $policy->bypassPaths) as $path) {
            // "contains" rather than "starts with" so a subdirectory install or network site is covered too.
            $clauses[] = 'not (http.request.uri.path contains ' . self::literal($path) . ')';
        }
        foreach ($policy->bypassCookiePrefixes as $prefix) {
            $clauses[] = 'not (http.cookie contains ' . self::literal($prefix) . ')';
        }
        if ($policy->bypassQueryArgs !== []) {
            $clauses[] = 'not any(http.request.uri.args.names[*] in {'
                . implode(' ', array_map([self::class, 'literal'], $policy->bypassQueryArgs)) . '})';
        }
        $clauses[] = 'not any(http.request.headers["authorization"][*] ne "")';
        $clauses[] = 'not any(http.request.headers["' . strtolower(Policy::DEBUG_TOKEN_HEADER) . '"][*] ne "")';
        return '(' . implode("\n and ", $clauses) . ')';
    }

    /**
     * @param list<string> $hosts
     */
    public function generate(Policy $policy, array $hosts, string $version = ''): string
    {
        $lines = [];
        $lines[] = '# wp-edge-cache-headers ' . ($version !== '' ? $version . ' ' : '') . 'Cloudflare cache rule.';
        $lines[] = '# Caching > Cache Rules > Create rule. Paste the expression with "Edit expression".';
        $lines[] = '';
        $lines[] = 'Rule name: WordPress HTML (wp-edge-cache-headers)';
        $lines[] = '';
        $lines[] = 'When incoming requests match (custom filter expression):';
        $lines[] = $this->expression($policy, $hosts);
        $lines[] = '';
        $lines[] = 'Then:';
        $lines[] = '  Cache eligibility: Eligible for cache';
        $lines[] = '  Edge TTL: ' . self::EDGE_TTL . ' (API: edge_ttl.mode bypass_by_default)';
        $lines[] = '  Browser TTL: ' . self::BROWSER_TTL . ' (API: browser_ttl.mode respect_origin)';
        $lines[] = '';
        $lines[] = 'Notes:';
        $lines[] = '  - A Cloudflare purge clears Cloudflare only. The next miss refills from nginx. Purge nginx first, or rely on TTLs.';
        $lines[] = '  - Normalise query strings (utm_*, fbclid) with Cloudflare cache-key settings or a Transform Rule, not here.';
        $lines[] = '  - Requests that do not match keep the default behaviour: HTML is not cached.';
        $lines[] = '  - Cloudflare advises against s-maxage with stale-while-revalidate. To have Cloudflare serve stale while it';
        $lines[] = '    revalidates, turn on CDN-Cache-Control: it carries max-age plus the stale directives, without s-maxage.';
        $lines[] = '';
        return implode("\n", $lines);
    }

    private static function literal(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
}
