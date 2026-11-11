<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders\Tests\Unit;

use IndianOpinion\EdgeCacheHeaders\CacheControl;
use IndianOpinion\EdgeCacheHeaders\DebugToken;
use IndianOpinion\EdgeCacheHeaders\DecisionEngine;
use IndianOpinion\EdgeCacheHeaders\Explain\LiveComparison;
use IndianOpinion\EdgeCacheHeaders\Policy;
use IndianOpinion\EdgeCacheHeaders\Profile;
use IndianOpinion\EdgeCacheHeaders\RateAdvisor;
use IndianOpinion\EdgeCacheHeaders\RequestFacts;
use PHPUnit\Framework\TestCase;

final class SupportTest extends TestCase
{
    public function testNoCacheDetection(): void
    {
        self::assertTrue(CacheControl::isNoCache('no-cache, must-revalidate, max-age=0, no-store, private'));
        self::assertTrue(CacheControl::isNoCache('max-age=0'));
        self::assertTrue(CacheControl::isNoCache('public, s-maxage=0'));
        self::assertFalse(CacheControl::isNoCache('public, max-age=600'));
        self::assertFalse(CacheControl::isNoCache('max-age=0, s-maxage=60'));
    }

    public function testCookieNamesDropValues(): void
    {
        self::assertSame(['views', 'a'], CacheControl::cookieNames(['views=123; path=/', 'a=b', 'views=9']));
        self::assertSame(['User-Agent', 'Accept-Encoding'], CacheControl::varyNames(['User-Agent, Accept-Encoding', 'accept-encoding']));
    }

    public function testDebugTokenIsLongRandomAndHashed(): void
    {
        $token = DebugToken::generate();
        self::assertSame(64, strlen($token));
        self::assertNotSame($token, DebugToken::generate());
        $hash = DebugToken::hash($token);
        self::assertTrue(DebugToken::matchesHash($token, $hash));
        self::assertFalse(DebugToken::matchesHash('wrong', $hash));
        self::assertFalse(DebugToken::matchesHash('', $hash));
        self::assertFalse(DebugToken::matchesHash($token, ''));
        self::assertTrue(DebugToken::matchesPlain($token, $token));
        self::assertFalse(DebugToken::matchesPlain('short', 'short'));
    }

    public function testRateAdvisor(): void
    {
        self::assertSame(['posts_per_hour' => 0.5, 'profile' => Profile::QUIET], RateAdvisor::suggest(84, 168.0));
        self::assertSame(Profile::BUSY, RateAdvisor::suggest(13272, 168.0)['profile']);
        $this->expectException(\InvalidArgumentException::class);
        RateAdvisor::suggest(1, 0.0);
    }

    public function testT61LiveComparisonMismatchAndMatch(): void
    {
        $engine = new DecisionEngine();
        $simulated = $engine->decide(new RequestFacts(conditions: ['single'], postType: 'post'), new Policy());
        $liveDecision = $engine->decide(new RequestFacts(conditions: ['single'], postType: 'post', debugTokenValid: true), new Policy());

        $match = LiveComparison::compare($simulated, [
            'X-Edge-Cache-Debug' => (string) $liveDecision->header(Policy::DEBUG_HEADER),
            'CF-Cache-Status' => 'DYNAMIC',
        ]);
        self::assertTrue($match->matches);
        self::assertSame(['cf-cache-status' => 'DYNAMIC'], $match->observed);

        $other = $engine->decide(new RequestFacts(conditions: ['page', 'singular'], debugTokenValid: true), new Policy());
        $mismatch = LiveComparison::compare($simulated, ['x-edge-cache-debug' => (string) $other->header(Policy::DEBUG_HEADER)]);
        self::assertFalse($mismatch->matches);
        self::assertContains('type: simulated singular_post, live page', $mismatch->differences);

        $missing = LiveComparison::compare($simulated, ['cache-control' => 'public']);
        self::assertFalse($missing->matches);
    }
}
