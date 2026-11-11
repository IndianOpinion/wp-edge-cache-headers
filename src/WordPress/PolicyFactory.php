<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders\WordPress;

use IndianOpinion\EdgeCacheHeaders\CacheControl;
use IndianOpinion\EdgeCacheHeaders\Hooks;
use IndianOpinion\EdgeCacheHeaders\Policy;
use IndianOpinion\EdgeCacheHeaders\Profile;

/**
 * Builds the request Policy from Settings and the settings-level filters.
 * A filter that throws is recorded as an error, which makes the response PRIVATE.
 */
final class PolicyFactory
{
    /** @var list<string> */
    private array $errors = [];

    /** @var list<string> */
    private array $notices = [];

    public function __construct(private readonly Hooks $hooks)
    {
    }

    public function build(Settings $settings): Policy
    {
        $this->errors = [];
        $this->notices = $settings->notices;
        $v = $settings->values;

        $enabled = $this->bool('enabled', $settings->enabled);

        $ttls = Profile::ttls($settings->profile());
        $overrides = is_array($v['ttls']) ? $v['ttls'] : [];
        foreach ($overrides as $type => $ttl) {
            $ttls[(string) $type] = (int) $ttl;
        }
        foreach (['embed', 'singular_other'] as $follower) {
            if (!isset($overrides[$follower]) && isset($overrides['singular_post'])) {
                $ttls[$follower] = $ttls['singular_post'];
            }
        }
        $filtered = $this->filter('ttls', $ttls);
        if (is_array($filtered)) {
            foreach ($filtered as $type => $ttl) {
                if (is_string($type) && is_int($ttl) && $ttl >= 0) {
                    $ttls[$type] = $ttl;
                } else {
                    $this->notices[] = 'ttls: invalid entry ignored';
                }
            }
        } else {
            $this->notices[] = 'ttls: invalid value ignored';
        }

        $postTypeTtls = [];
        foreach ((is_array($v['post_type_ttls']) ? $v['post_type_ttls'] : []) as $type => $ttl) {
            $postTypeTtls[(string) $type] = (int) $ttl;
        }

        $statuses = $this->intList('cacheable_statuses', Policy::DEFAULT_CACHEABLE_STATUSES);

        $cookies = $this->stringList(
            'bypass_cookie_prefixes',
            array_merge(Policy::DEFAULT_COOKIE_PREFIXES, self::strings($v['extra_cookie_prefixes'])),
        );
        $queryArgs = $this->stringList(
            'bypass_query_args',
            array_merge(Policy::defaultQueryArgs(), self::strings($v['extra_query_args'])),
        );
        $paths = $this->stringList(
            'bypass_paths',
            array_merge(Policy::DEFAULT_PATHS, self::strings($v['extra_paths'])),
        );
        $sitemaps = $this->stringList('sitemap_patterns', Policy::DEFAULT_SITEMAP_PATTERNS);
        $vary = $this->stringList('vary', ['Accept-Encoding']);
        $denylist = $this->stringList('rest_route_denylist', Policy::DEFAULT_REST_DENYLIST);

        $headerName = $this->filter('custom_header_name', (string) $v['custom_header_name']);
        if (!is_string($headerName) || !CacheControl::isHeaderName($headerName)) {
            $this->notices[] = 'custom_header_name: invalid value ignored';
            $headerName = Policy::DEFAULT_CUSTOM_HEADER;
        }

        $browser = $this->filter('browser_max_age', (int) $v['browser_max_age']);
        if (!is_int($browser) || $browser < 0) {
            $this->notices[] = 'browser_max_age: invalid value ignored';
            $browser = (int) $v['browser_max_age'];
        }

        return new Policy(
            ttls: $ttls,
            postTypeTtls: $postTypeTtls,
            enabled: $enabled,
            browserMaxAge: min($browser, Policy::ONE_YEAR),
            staleWhileRevalidate: $v['stale_while_revalidate'] === null ? null : (int) $v['stale_while_revalidate'],
            staleIfError: (int) $v['stale_if_error'],
            cacheableStatuses: $statuses,
            bypassCookiePrefixes: $cookies,
            bypassQueryArgs: $queryArgs,
            bypassPaths: $paths,
            sitemapPatterns: $sitemaps,
            respectUpstreamPublic: $this->bool('respect_upstream_public', (bool) $v['respect_upstream_public']),
            vary: $vary,
            varyIsHonoured: $this->bool('vary_is_honoured', (bool) $v['vary_is_honoured']),
            restRouteDenylist: $denylist,
            customHeaderName: $headerName,
            sendCdnCacheControl: $this->bool('send_cdn_cache_control', (bool) $v['send_cdn_cache_control']),
            sendSurrogateControl: $this->bool('send_surrogate_control', (bool) $v['send_surrogate_control']),
            sendXAccelExpires: $this->bool('send_x_accel_expires', (bool) $v['send_x_accel_expires']),
            sendCustomHeader: $this->bool('send_custom_header', (bool) $v['send_custom_header']),
            debug: $this->bool('debug', (bool) $v['debug']),
            strictMode: $this->bool('strict_mode', (bool) $v['strict_mode']),
            errors: $this->errors,
            notices: $this->notices,
        );
    }

    private function filter(string $name, mixed $value): mixed
    {
        try {
            return $this->hooks->filter($name, $value);
        } catch (\Throwable $e) {
            $this->errors[] = $name . ': ' . get_class($e);
            return $value;
        }
    }

    private function bool(string $name, bool $default): bool
    {
        $value = $this->filter($name, $default);
        if (is_bool($value)) {
            return $value;
        }
        $this->notices[] = $name . ': invalid value ignored';
        return $default;
    }

    /**
     * @param list<int> $default
     * @return list<int>
     */
    private function intList(string $name, array $default): array
    {
        $value = $this->filter($name, $default);
        if (!is_array($value)) {
            $this->notices[] = $name . ': invalid value ignored';
            return $default;
        }
        $out = [];
        foreach ($value as $item) {
            if (is_int($item) && $item >= 100 && $item <= 599) {
                $out[] = $item;
            } else {
                $this->notices[] = $name . ': invalid entry ignored';
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * @param list<string> $default
     * @return list<string>
     */
    private function stringList(string $name, array $default): array
    {
        $value = $this->filter($name, array_values(array_unique($default)));
        if (!is_array($value)) {
            $this->notices[] = $name . ': invalid value ignored';
            return array_values(array_unique($default));
        }
        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '' && preg_match('/[\s"\'\\\\]/', $item) !== 1) {
                $out[] = $item;
            } else {
                $this->notices[] = $name . ': invalid entry ignored';
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
