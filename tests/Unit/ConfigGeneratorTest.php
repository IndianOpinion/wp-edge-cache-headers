<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders\Tests\Unit;

use IndianOpinion\EdgeCacheHeaders\Config\CloudflareGenerator;
use IndianOpinion\EdgeCacheHeaders\Config\NginxGenerator;
use IndianOpinion\EdgeCacheHeaders\Policy;
use PHPUnit\Framework\TestCase;

final class ConfigGeneratorTest extends TestCase
{
    public function testT63NginxContainsEveryListAndTheCustomHeader(): void
    {
        $out = (new NginxGenerator())->generate(new Policy());
        foreach (Policy::DEFAULT_COOKIE_PREFIXES as $prefix) {
            self::assertStringContainsString(NginxGenerator::quote($prefix), $out, $prefix);
        }
        foreach (array_merge(Policy::DEFAULT_PATHS, Policy::SKIP_PATHS) as $path) {
            self::assertStringContainsString(NginxGenerator::quote($path), $out, $path);
        }
        foreach (Policy::defaultQueryArgs() as $arg) {
            self::assertStringContainsString(NginxGenerator::quote($arg), $out, $arg);
        }
        self::assertStringContainsString('$upstream_http_x_edge_cache', $out);
        self::assertStringContainsString('fastcgi_hide_header X-Edge-Cache;', $out);
        self::assertStringContainsString('fastcgi_cache_key "$scheme$request_method$host$request_uri";', $out);
        self::assertStringContainsString('fastcgi_no_cache $edge_cache_skip $edge_cache_no_store $upstream_http_set_cookie;', $out);
        self::assertStringContainsString('fastcgi_cache_use_stale updating error timeout http_500 http_503;', $out);
        self::assertStringContainsString('$http_x_edge_cache_debug_token', $out);
        self::assertStringContainsString('log_format edge_cache', $out);
    }

    public function testT63AddedCookiePrefixAppears(): void
    {
        $policy = new Policy(
            bypassCookiePrefixes: array_merge(Policy::DEFAULT_COOKIE_PREFIXES, ['my_session_']),
            customHeaderName: 'X-Page-Cache',
        );
        $out = (new NginxGenerator())->generate($policy, '/blog', true);
        self::assertStringContainsString('my_session_', $out);
        self::assertStringContainsString('$upstream_http_x_page_cache', $out);
        self::assertStringContainsString('"~*^/blog(/[^/]+)?(', $out);
    }

    public function testT64CloudflareMatchesTheSameLists(): void
    {
        $out = (new CloudflareGenerator())->generate(new Policy(), ['example.com', 'www.example.com']);
        foreach (Policy::DEFAULT_COOKIE_PREFIXES as $prefix) {
            self::assertStringContainsString('not http.cookie contains "' . $prefix . '"', $out);
        }
        foreach (array_merge(Policy::DEFAULT_PATHS, Policy::SKIP_PATHS) as $path) {
            self::assertStringContainsString('not http.request.uri.path contains "' . $path . '"', $out);
        }
        self::assertStringContainsString('http.host in {"example.com" "www.example.com"}', $out);
        self::assertStringContainsString('Use cache-control header if present, bypass cache if not', $out);
        self::assertStringContainsString('Browser TTL: Respect origin', $out);
        self::assertStringContainsString('purge clears Cloudflare only', $out);
    }

    public function testCloudflareEscapesLiterals(): void
    {
        $expr = (new CloudflareGenerator())->expression(new Policy(bypassPaths: ['/a"b']), []);
        self::assertStringContainsString('"/a\\"b"', $expr);
    }
}
