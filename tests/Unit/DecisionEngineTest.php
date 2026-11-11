<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders\Tests\Unit;

use IndianOpinion\EdgeCacheHeaders\Decision;
use IndianOpinion\EdgeCacheHeaders\DecisionEngine;
use IndianOpinion\EdgeCacheHeaders\Hooks;
use IndianOpinion\EdgeCacheHeaders\Outcome;
use IndianOpinion\EdgeCacheHeaders\Policy;
use IndianOpinion\EdgeCacheHeaders\Profile;
use IndianOpinion\EdgeCacheHeaders\RequestFacts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DecisionEngineTest extends TestCase
{
    private const POST = ['conditions' => ['singular', 'single'], 'postType' => 'post'];

    /**
     * @param array<string, mixed> $facts
     * @param array<string, mixed> $policy
     */
    private function decide(array $facts = self::POST, array $policy = [], ?Hooks $hooks = null): Decision
    {
        return (new DecisionEngine($hooks))->decide(new RequestFacts(...$facts), new Policy(...$policy));
    }

    public function testT1AnonymousPostIsPublicWithFullDirectiveSet(): void
    {
        $d = $this->decide();
        self::assertSame(Outcome::PUBLIC, $d->outcome);
        self::assertSame('singular_post', $d->type);
        self::assertSame(
            'public, max-age=0, s-maxage=1800, stale-while-revalidate=9000, stale-if-error=86400',
            $d->header('Cache-Control'),
        );
    }

    public function testT2BrowserMaxAge(): void
    {
        self::assertStringContainsString('max-age=0,', (string) $this->decide()->header('Cache-Control'));
        $d = $this->decide(self::POST, ['browserMaxAge' => 60]);
        self::assertStringStartsWith('public, max-age=60, s-maxage=1800', (string) $d->header('Cache-Control'));
    }

    public function testT3ZeroStaleDirectivesAreOmitted(): void
    {
        $hooks = new CallbackHooks([
            'stale_while_revalidate' => static fn (): int => 0,
            'stale_if_error' => static fn (): int => 0,
        ]);
        $d = $this->decide(self::POST, [], $hooks);
        self::assertSame('public, max-age=0, s-maxage=1800', $d->header('Cache-Control'));
    }

    public function testT4LoggedInIsPrivate(): void
    {
        $d = $this->decide(self::POST + ['loggedIn' => true]);
        self::assertSame(Outcome::PRIVATE, $d->outcome);
        self::assertSame('private, no-store, max-age=0', $d->header('Cache-Control'));
        self::assertSame(['logged-in'], $d->reasons);
    }

    public function testT5CdnCacheControl(): void
    {
        $on = ['sendCdnCacheControl' => true];
        self::assertSame(
            'max-age=1800, stale-while-revalidate=9000, stale-if-error=86400',
            $this->decide(self::POST, $on)->header('CDN-Cache-Control'),
        );
        self::assertSame('no-store', $this->decide(self::POST + ['loggedIn' => true], $on)->header('CDN-Cache-Control'));
        self::assertNull($this->decide()->header('CDN-Cache-Control'));
        self::assertNull($this->decide(self::POST + ['loggedIn' => true])->header('CDN-Cache-Control'));
    }

    public function testT6SurrogateControl(): void
    {
        $on = ['sendSurrogateControl' => true];
        self::assertSame(
            'max-age=1800, stale-while-revalidate=9000, stale-if-error=86400',
            $this->decide(self::POST, $on)->header('Surrogate-Control'),
        );
        self::assertSame('no-store', $this->decide(self::POST + ['loggedIn' => true], $on)->header('Surrogate-Control'));
        self::assertNull($this->decide()->header('Surrogate-Control'));
    }

    public function testT7XAccelExpires(): void
    {
        self::assertSame('1800', $this->decide()->header('X-Accel-Expires'));
        self::assertSame('0', $this->decide(self::POST + ['loggedIn' => true])->header('X-Accel-Expires'));
        $respect = $this->decide(self::POST + ['queuedCacheControl' => ['no-store']]);
        self::assertSame(Outcome::RESPECT, $respect->outcome);
        self::assertSame('0', $respect->header('X-Accel-Expires'));
        self::assertNull($this->decide(self::POST, ['sendXAccelExpires' => false])->header('X-Accel-Expires'));
        $off = ['sendXAccelExpires' => false];
        self::assertNull($this->decide(self::POST + ['loggedIn' => true], $off)->header('X-Accel-Expires'), 'absent on PRIVATE when off');
        self::assertNull($this->decide(self::POST + ['queuedCacheControl' => ['no-store']], $off)->header('X-Accel-Expires'), 'absent on RESPECT when off');
    }

    public function testT8CustomHeader(): void
    {
        self::assertSame('cache', $this->decide()->header('X-Edge-Cache'));
        self::assertSame('bypass', $this->decide(self::POST + ['loggedIn' => true])->header('X-Edge-Cache'));
        self::assertSame('bypass', $this->decide(self::POST + ['queuedCacheControl' => ['private']])->header('X-Edge-Cache'));
        $renamed = $this->decide(self::POST, ['customHeaderName' => 'X-Page-Cache']);
        self::assertSame('cache', $renamed->header('X-Page-Cache'));
        self::assertNull($renamed->header('X-Edge-Cache'));
        self::assertNull($this->decide(self::POST, ['sendCustomHeader' => false])->header('X-Edge-Cache'));
    }

    public function testT9UpstreamPublicHeaderIsReplaced(): void
    {
        $d = $this->decide(self::POST + ['queuedCacheControl' => ['public, max-age=10']]);
        self::assertSame(Outcome::PUBLIC, $d->outcome);
        self::assertStringContainsString('s-maxage=1800', (string) $d->header('Cache-Control'));
        self::assertContains('upstream-overridden', $d->reasons);
    }

    public function testT10NotFoundIsPublicAndRemovesExpiresAndPragma(): void
    {
        $d = $this->decide(['conditions' => ['404'], 'status' => 404, 'nocacheHelper' => RequestFacts::NOCACHE_CORE]);
        self::assertSame(Outcome::PUBLIC, $d->outcome);
        self::assertSame('not_found', $d->type);
        self::assertStringContainsString('s-maxage=60', (string) $d->header('Cache-Control'));
        self::assertSame(['Expires', 'Pragma'], $d->removeHeaders);
    }

    public function testT11FeedKeepsValidatorsAndUsesFeedTtl(): void
    {
        $d = $this->decide(['conditions' => ['feed']]);
        self::assertSame('feed', $d->type);
        self::assertStringContainsString('s-maxage=600', (string) $d->header('Cache-Control'));
        self::assertNotContains('Last-Modified', $d->removeHeaders);
        self::assertNotContains('ETag', $d->removeHeaders);
        self::assertArrayNotHasKey('Last-Modified', $d->headers);
    }

    public function testT12TypeFilterMapsCustomEndpoint(): void
    {
        $hooks = new CallbackHooks([
            'type' => static fn (string $type, RequestFacts $f): string => in_array('my_endpoint', $f->queryArgNames, true) ? 'page' : $type,
        ]);
        $d = $this->decide(['conditions' => ['home', 'front_page'], 'queryArgNames' => ['my_endpoint']], [], $hooks);
        self::assertSame('page', $d->type);
        self::assertSame(3600, $d->ttl);
    }

    public function testT13VirtualRobots(): void
    {
        $d = $this->decide(['conditions' => ['robots']]);
        self::assertSame('robots', $d->type);
        self::assertSame(3600, $d->ttl);
    }

    public function testT14Sitemaps(): void
    {
        self::assertSame(300, $this->decide(['conditions' => ['sitemap']])->ttl);
        $d = $this->decide(['conditions' => ['404'], 'path' => '/post-sitemap.xml']);
        self::assertSame('sitemap', $d->type);
        $d = $this->decide(['conditions' => ['home'], 'path' => '/news-sitemap.xml']);
        self::assertSame('sitemap', $d->type);
        self::assertSame(300, $d->ttl);
    }

    public function testT15CommentAndCategoryFeedsAreFeeds(): void
    {
        self::assertSame('feed', $this->decide(['conditions' => ['feed', 'singular', 'single']])->type);
        self::assertSame('feed', $this->decide(['conditions' => ['feed', 'category']])->type);
    }

    public function testT16EmbedUsesPostTtl(): void
    {
        $d = $this->decide(['conditions' => ['embed', 'singular', 'single']]);
        self::assertSame('embed', $d->type);
        self::assertSame(1800, $d->ttl);
    }

    public function testT17SearchIsPrivateUnlessTtlSet(): void
    {
        $d = $this->decide(['conditions' => ['search']]);
        self::assertSame(Outcome::PRIVATE, $d->outcome);
        self::assertSame(['ttl-zero'], $d->reasons);

        $d = $this->decide(['conditions' => ['search']], ['ttls' => ['search' => 120]]);
        self::assertSame(Outcome::PUBLIC, $d->outcome);
        self::assertStringContainsString('s-maxage=120', (string) $d->header('Cache-Control'));
    }

    public function testT18FrontPageAndHome(): void
    {
        self::assertSame('front_page', $this->decide(['conditions' => ['front_page', 'home']])->type);
        self::assertSame('front_page', $this->decide(['conditions' => ['front_page', 'page', 'singular']])->type);
        $home = $this->decide(['conditions' => ['home']]);
        self::assertSame('home', $home->type);
        self::assertSame(300, $home->ttl);
    }

    public function testT19SingularTtls(): void
    {
        self::assertSame(1800, $this->decide()->ttl);
        self::assertSame(3600, $this->decide(['conditions' => ['page', 'singular'], 'postType' => 'page'])->ttl);
        $att = $this->decide(['conditions' => ['attachment', 'single', 'singular'], 'postType' => 'attachment']);
        self::assertSame('attachment', $att->type);
        self::assertSame(3600, $att->ttl);
    }

    public function testT20CustomPostType(): void
    {
        $facts = ['conditions' => ['single', 'singular'], 'postType' => 'event'];
        $d = $this->decide($facts);
        self::assertSame('singular_other', $d->type);
        self::assertSame(1800, $d->ttl);
        self::assertSame(7200, $this->decide($facts, ['postTypeTtls' => ['event' => 7200]])->ttl);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: int}>
     */
    public static function archives(): iterable
    {
        yield 'category' => ['category', 'category', 600];
        yield 'tag' => ['tag', 'tag', 600];
        yield 'taxonomy' => ['tax', 'taxonomy', 600];
        yield 'author' => ['author', 'author', 900];
        yield 'date' => ['date', 'date', 900];
        yield 'post type archive' => ['post_type_archive', 'post_type_archive', 600];
    }

    #[DataProvider('archives')]
    public function testT21Archives(string $condition, string $type, int $ttl): void
    {
        $d = $this->decide(['conditions' => [$condition]]);
        self::assertSame($type, $d->type);
        self::assertSame($ttl, $d->ttl);
    }

    public function testT22PagedArchivePassesPageNumber(): void
    {
        $seen = null;
        $hooks = new CallbackHooks([
            'ttl' => static function (int $ttl, string $type, RequestFacts $f) use (&$seen): int {
                $seen = $f->paged;
                return $ttl;
            },
        ]);
        $d = $this->decide(['conditions' => ['category'], 'paged' => 3], [], $hooks);
        self::assertSame(600, $d->ttl);
        self::assertSame(3, $seen);
    }

    public function testT23UnknownTemplateIsPublicOther(): void
    {
        $d = $this->decide(['conditions' => []]);
        self::assertSame(Outcome::PUBLIC, $d->outcome);
        self::assertSame('other', $d->type);
        self::assertStringContainsString('s-maxage=300', (string) $d->header('Cache-Control'));
    }

    public function testT24LoggedInWithCoreNocacheIsPrivate(): void
    {
        $d = $this->decide(self::POST + ['loggedIn' => true, 'nocacheHelper' => RequestFacts::NOCACHE_CORE]);
        self::assertSame(Outcome::PRIVATE, $d->outcome);
        self::assertSame('private, no-store, max-age=0', $d->header('Cache-Control'));
    }

    public function testT25ThirdPartyNocacheHelperIsRespected(): void
    {
        $d = $this->decide(self::POST + ['nocacheHelper' => RequestFacts::NOCACHE_THIRD_PARTY]);
        self::assertSame(Outcome::RESPECT, $d->outcome);
        self::assertSame(['nocache-helper'], $d->reasons);
        self::assertNull($d->header('Cache-Control'));
        self::assertSame('0', $d->header('X-Accel-Expires'));
        self::assertSame('bypass', $d->header('X-Edge-Cache'));
        self::assertSame('no-store', $d->header('CDN-Cache-Control'), 'RESPECT always sends CDN-Cache-Control: no-store');
    }

    public function testT26DoNotCachePage(): void
    {
        $d = $this->decide(self::POST + ['doNotCachePage' => true]);
        self::assertSame(Outcome::PRIVATE, $d->outcome);
        self::assertSame(['donotcachepage'], $d->reasons);

        $d = $this->decide(self::POST + ['doNotCachePage' => true, 'queuedCacheControl' => ['no-cache']]);
        self::assertSame(Outcome::RESPECT, $d->outcome);
        self::assertContains('donotcachepage', $d->reasons);
    }

    public function testT27UpstreamNoCacheIsRespected(): void
    {
        foreach (['no-store, no-cache, must-revalidate', 'private', 'max-age=0'] as $value) {
            $d = $this->decide(self::POST + ['queuedCacheControl' => [$value]]);
            self::assertSame(Outcome::RESPECT, $d->outcome, $value);
            self::assertSame(['upstream'], $d->reasons);
        }
        self::assertSame(Outcome::PUBLIC, $this->decide(self::POST + ['queuedCacheControl' => ['max-age=0, s-maxage=60']])->outcome);
    }

    public function testT28UpstreamPublicRespectedWhenAsked(): void
    {
        $facts = self::POST + ['queuedCacheControl' => ['public, max-age=600']];
        self::assertSame(Outcome::PUBLIC, $this->decide($facts)->outcome);
        $d = $this->decide($facts, ['respectUpstreamPublic' => true]);
        self::assertSame(Outcome::RESPECT, $d->outcome);
        self::assertNull($d->header('Cache-Control'));
    }

    public function testT29EveryDefaultCookiePrefixWithGarbageIsPrivate(): void
    {
        foreach (Policy::DEFAULT_COOKIE_PREFIXES as $prefix) {
            $d = $this->decide(self::POST + ['cookieNames' => [$prefix . 'garbage']]);
            self::assertSame(Outcome::PRIVATE, $d->outcome, $prefix);
            self::assertSame(['cookie:' . $prefix], $d->reasons);
        }
        self::assertSame(Outcome::PUBLIC, $this->decide(self::POST + ['cookieNames' => ['_ga']])->outcome);
    }

    public function testT30DebugHeaderNeverHoldsCookieValuesOrFullNames(): void
    {
        $d = $this->decide(self::POST + ['cookieNames' => ['wordpress_logged_in_secretvalue123']], ['debug' => true]);
        $debug = (string) $d->header(Policy::DEBUG_HEADER);
        self::assertStringNotContainsString('secretvalue123', $debug);
        self::assertStringContainsString('cookie:wordpress_logged_in_', $debug);
    }

    public function testT31CommentCookie(): void
    {
        $d = $this->decide(self::POST + ['cookieNames' => ['comment_author_abc']]);
        self::assertSame(Outcome::PRIVATE, $d->outcome);
    }

    public function testT32Commerce(): void
    {
        $d = $this->decide(['conditions' => ['page', 'singular'], 'neverCacheMatched' => true]);
        self::assertSame(Outcome::PRIVATE, $d->outcome);
        self::assertSame(['commerce'], $d->reasons);
    }

    public function testT33Authorization(): void
    {
        $d = $this->decide(self::POST + ['hasAuthorization' => true]);
        self::assertSame(['authorization'], $d->reasons);
    }

    public function testT34PreviewArguments(): void
    {
        foreach (Policy::PREVIEW_QUERY_ARGS as $arg) {
            $d = $this->decide(self::POST + ['queryArgNames' => [$arg]]);
            self::assertSame(Outcome::PRIVATE, $d->outcome, $arg);
            self::assertSame(['preview'], $d->reasons, $arg);
        }
        $d = $this->decide(['conditions' => ['singular', 'single', 'customize_preview']]);
        self::assertSame(['preview'], $d->reasons);
    }

    public function testT35Moderation(): void
    {
        foreach (['unapproved', 'moderation-hash'] as $arg) {
            self::assertSame(['moderation'], $this->decide(self::POST + ['queryArgNames' => [$arg]])->reasons);
        }
    }

    public function testT36Nonce(): void
    {
        self::assertSame(['nonce'], $this->decide(self::POST + ['queryArgNames' => ['_wpnonce']])->reasons);
    }

    public function testT37PasswordProtected(): void
    {
        $d = $this->decide(self::POST + ['postHasPassword' => true]);
        self::assertSame(['password'], $d->reasons);
    }

    public function testT38NonPublicStatus(): void
    {
        foreach (['private', 'draft', 'future', 'pending', 'trash'] as $status) {
            $d = $this->decide(self::POST + ['postStatus' => $status]);
            self::assertSame(['non-public-status'], $d->reasons, $status);
        }
        self::assertSame(Outcome::PUBLIC, $this->decide(self::POST + ['postStatus' => 'publish'])->outcome);
    }

    public function testT39Methods(): void
    {
        foreach (['POST', 'PUT', 'DELETE', 'OPTIONS', 'PATCH'] as $method) {
            self::assertSame(['method'], $this->decide(self::POST + ['method' => $method])->reasons, $method);
        }
        self::assertSame($this->decide()->headers, $this->decide(self::POST + ['method' => 'HEAD'])->headers);
    }

    public function testT40SetCookieNamesTheCookieNotTheValue(): void
    {
        $d = $this->decide(self::POST + ['setCookieNames' => ['views']], ['debug' => true]);
        self::assertSame(['set-cookie:views'], $d->reasons);
    }

    public function testT41Paths(): void
    {
        foreach (['/wp-signup.php', '/wp-activate.php', '/wp-login.php'] as $path) {
            self::assertSame(['path'], $this->decide(['path' => $path])->reasons, $path);
        }
        $d = $this->decide(['path' => '/members/area/'], ['bypassPaths' => array_merge(Policy::DEFAULT_PATHS, ['/members/'])]);
        self::assertSame(['path'], $d->reasons);
    }

    public function testT42OutOfScopeContextsSendNothing(): void
    {
        foreach (['admin', 'ajax', 'cron', 'xmlrpc', 'cli', 'none'] as $context) {
            $d = $this->decide(['context' => $context]);
            self::assertSame(Outcome::SKIP, $d->outcome, $context);
            self::assertSame([], $d->headers);
        }
    }

    public function testT43DebugToken(): void
    {
        $d = $this->decide(self::POST + ['debugTokenValid' => true]);
        self::assertSame(Outcome::PRIVATE, $d->outcome);
        self::assertSame(Outcome::PUBLIC, $d->wouldBe);
        self::assertSame(['debug-token'], $d->reasons);
        $debug = (string) $d->header(Policy::DEBUG_HEADER);
        self::assertStringContainsString('outcome=PRIVATE; would-be=PUBLIC', $debug);

        $wrong = $this->decide(self::POST + ['debugTokenValid' => false]);
        self::assertSame(Outcome::PUBLIC, $wrong->outcome);
        self::assertNull($wrong->header(Policy::DEBUG_HEADER));
    }

    public function testT44UnsafeVary(): void
    {
        $d = $this->decide(self::POST + ['queuedVary' => ['User-Agent']]);
        self::assertSame(Outcome::PRIVATE, $d->outcome);
        self::assertSame(['unsafe-vary:User-Agent'], $d->reasons);

        $d = $this->decide(self::POST + ['queuedVary' => ['User-Agent']], ['varyIsHonoured' => true]);
        self::assertSame(Outcome::PUBLIC, $d->outcome);
        self::assertSame('User-Agent, Accept-Encoding', $d->header('Vary'));
    }

    public function testT45AcceptEncodingVaryIsMergedNotDuplicated(): void
    {
        $d = $this->decide(self::POST + ['queuedVary' => ['accept-encoding']]);
        self::assertSame(Outcome::PUBLIC, $d->outcome);
        self::assertSame('accept-encoding', $d->header('Vary'));
    }

    public function testT46GoneIsPrivateUnlessListed(): void
    {
        $d = $this->decide(self::POST + ['status' => 410]);
        self::assertSame(['status:410'], $d->reasons);
        $d = $this->decide(self::POST + ['status' => 410], ['cacheableStatuses' => [200, 301, 308, 404, 410]]);
        self::assertSame(Outcome::PUBLIC, $d->outcome);
        self::assertSame(1800, $d->ttl);
    }

    public function testT47PermanentRedirectsUseRedirectTtl(): void
    {
        foreach ([301, 308] as $status) {
            $d = $this->decide(self::POST + ['status' => $status]);
            self::assertSame(Outcome::PUBLIC, $d->outcome);
            self::assertSame('singular_post', $d->type);
            self::assertSame($status, $d->status);
            self::assertStringContainsString('s-maxage=600', (string) $d->header('Cache-Control'));
        }
    }

    public function testT48TemporaryRedirectsArePrivate(): void
    {
        foreach ([302, 303, 307] as $status) {
            self::assertSame(['status:' . $status], $this->decide(self::POST + ['status' => $status])->reasons);
        }
    }

    public function testT49ServerErrorsArePrivateEvenIfListed(): void
    {
        foreach ([500, 503] as $status) {
            $d = $this->decide(self::POST + ['status' => $status], ['cacheableStatuses' => [200, 503]]);
            self::assertSame(Outcome::PRIVATE, $d->outcome);
            self::assertSame(['status:' . $status], $d->reasons);
        }
    }

    public function testT50NotModifiedCarriesTheSameCacheControl(): void
    {
        $ok = $this->decide(['conditions' => ['feed']]);
        $notModified = $this->decide(['conditions' => ['feed'], 'status' => 304]);
        self::assertSame($ok->header('Cache-Control'), $notModified->header('Cache-Control'));
    }

    public function testT56AnonymousRestGet(): void
    {
        $d = $this->decide(['context' => 'rest', 'restRoute' => '/wp/v2/posts', 'restNamespace' => 'wp/v2']);
        self::assertSame(Outcome::PUBLIC, $d->outcome);
        self::assertSame('rest', $d->type);
        self::assertStringContainsString('s-maxage=60', (string) $d->header('Cache-Control'));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: Outcome}>
     */
    public static function restBypass(): iterable
    {
        $base = ['context' => 'rest', 'restRoute' => '/wp/v2/posts', 'restNamespace' => 'wp/v2'];
        yield 'cookie' => [$base + ['cookieNames' => ['wordpress_logged_in_x']], Outcome::PRIVATE];
        yield 'authorization' => [$base + ['hasAuthorization' => true], Outcome::PRIVATE];
        yield 'rest nonce' => [$base + ['hasRestNonce' => true], Outcome::PRIVATE];
        yield 'jsonp' => [$base + ['queryArgNames' => ['_jsonp']], Outcome::PRIVATE];
        yield 'post' => [$base + ['method' => 'POST'], Outcome::PRIVATE];
        yield 'error status' => [$base + ['status' => 404], Outcome::PRIVATE];
        yield 'batch' => [['context' => 'rest', 'restRoute' => '/batch/v1'], Outcome::PRIVATE];
        yield 'users' => [['context' => 'rest', 'restRoute' => '/wp/v2/users/1'], Outcome::PRIVATE];
        yield 'core nocache sent' => [$base + ['nocacheHelper' => RequestFacts::NOCACHE_THIRD_PARTY], Outcome::RESPECT];
    }

    /**
     * @param array<string, mixed> $facts
     */
    #[DataProvider('restBypass')]
    public function testT57RestBypass(array $facts, Outcome $expected): void
    {
        self::assertSame($expected, $this->decide($facts)->outcome);
    }

    public function testT58RestTtlFilterAndDenylist(): void
    {
        $hooks = new CallbackHooks([
            'rest_ttl' => static fn (int $ttl, ?string $route, ?string $ns): int => $ns === 'myapp/v1' ? 900 : $ttl,
        ]);
        $d = $this->decide(['context' => 'rest', 'restRoute' => '/myapp/v1/things', 'restNamespace' => 'myapp/v1'], [], $hooks);
        self::assertSame(900, $d->ttl);

        $d = $this->decide(
            ['context' => 'rest', 'restRoute' => '/myapp/v1/secret'],
            ['restRouteDenylist' => ['/myapp/v1/secret']],
        );
        self::assertSame(['rest-denylist'], $d->reasons);
    }

    public function testT59DebugHeaderContent(): void
    {
        $d = $this->decide(
            self::POST + ['cookieNames' => ['_ga', 'wp-postpass_hashvalue'], 'queryArgNames' => ['utm_source']],
            ['debug' => true],
        );
        $debug = (string) $d->header(Policy::DEBUG_HEADER);
        self::assertSame(
            'outcome=PRIVATE; type=singular_post; ttl=1800; swr=9000; sie=86400; status=200; hook=wp_headers; reasons=cookie:wp-postpass_',
            $debug,
        );
        self::assertStringNotContainsString('hashvalue', $debug);
        self::assertNull($this->decide()->header(Policy::DEBUG_HEADER));
    }

    public function testT69ThrowingFilterFailsClosed(): void
    {
        foreach (['ttl', 'type', 'stale_if_error', 'decision', 'headers'] as $name) {
            $hooks = new CallbackHooks([$name => static function (): never {
                throw new \RuntimeException('boom');
            }]);
            $d = $this->decide(self::POST, [], $hooks);
            self::assertSame(Outcome::PRIVATE, $d->outcome, $name);
            self::assertContains('error', $d->reasons, $name);
            self::assertSame('private, no-store, max-age=0', $d->header('Cache-Control'), $name);
        }
    }

    public function testT70InvalidFilterValuesUseDefaults(): void
    {
        foreach ([-5, 'abc', 3.7, null] as $bad) {
            $hooks = new CallbackHooks(['ttl' => static fn (): mixed => $bad]);
            $d = $this->decide(self::POST, [], $hooks);
            self::assertSame(1800, $d->ttl);
            self::assertSame(Outcome::PUBLIC, $d->outcome);
            self::assertContains('ttl: invalid value ignored', $d->notices);
        }
        $hooks = new CallbackHooks(['ttl' => static fn (): string => '120']);
        self::assertSame(120, $this->decide(self::POST, [], $hooks)->ttl);
    }

    public function testT71Clamping(): void
    {
        $hooks = new CallbackHooks([
            'ttl' => static fn (): int => 10 * Policy::ONE_YEAR,
            'stale_while_revalidate' => static fn (): int => 30 * 86400,
        ]);
        $d = $this->decide(self::POST, [], $hooks);
        self::assertSame(Policy::ONE_YEAR, $d->ttl);
        self::assertSame(Policy::ONE_WEEK, $d->staleWhileRevalidate);
    }

    public function testT73DisabledSendsNothing(): void
    {
        $d = $this->decide(self::POST, ['enabled' => false]);
        self::assertSame(Outcome::SKIP, $d->outcome);
        self::assertSame(['disabled'], $d->reasons);
        self::assertSame([], $d->headers);
    }

    /**
     * @return iterable<string, array{0: float, 1: string}>
     */
    public static function rates(): iterable
    {
        yield 'quiet' => [0.5, Profile::QUIET];
        yield 'steady' => [5.0, Profile::STEADY];
        yield 'busy' => [25.0, Profile::BUSY];
    }

    #[DataProvider('rates')]
    public function testT74ProfilesFromPostsPerHour(float $rate, string $profile): void
    {
        self::assertSame($profile, Profile::fromPostsPerHour($rate));
        $policy = Policy::forProfile($profile);
        $engine = new DecisionEngine();
        $table = Profile::ttls($profile);
        $cases = [
            'front_page' => ['conditions' => ['front_page']],
            'singular_post' => self::POST,
            'page' => ['conditions' => ['page', 'singular']],
            'category' => ['conditions' => ['category']],
            'author' => ['conditions' => ['author']],
            'date' => ['conditions' => ['date']],
            'feed' => ['conditions' => ['feed']],
            'sitemap' => ['conditions' => ['sitemap']],
            'robots' => ['conditions' => ['robots']],
            'not_found' => ['conditions' => ['404'], 'status' => 404],
            'other' => ['conditions' => []],
        ];
        foreach ($cases as $type => $facts) {
            $d = $engine->decide(new RequestFacts(...$facts), $policy);
            self::assertSame($table[$type], $d->ttl, $profile . ' ' . $type);
        }
        self::assertSame(
            $table['rest'],
            $engine->decide(new RequestFacts(context: 'rest', restRoute: '/wp/v2/posts'), $policy)->ttl,
        );
    }

    public function testDefaultTableMatchesThePrd(): void
    {
        self::assertSame(['front_page' => 900, 'feed' => 1800, 'robots' => 86400], array_intersect_key(
            Profile::ttls(Profile::QUIET),
            ['front_page' => 0, 'feed' => 0, 'robots' => 0],
        ));
        self::assertSame(120, Profile::ttls(Profile::BUSY)['sitemap']);
        self::assertSame(600, Profile::ttls(Profile::BUSY)['author']);
        self::assertSame(Profile::STEADY, Profile::fromPostsPerHour(null));
        self::assertSame(Profile::STEADY, Profile::fromPostsPerHour(1.0));
        self::assertSame(Profile::BUSY, Profile::fromPostsPerHour(20.0));
    }

    public function testLateCheckOnlyDowngrades(): void
    {
        $engine = new DecisionEngine();
        $policy = new Policy();
        $public = $engine->decide(new RequestFacts(...self::POST), $policy);

        $late = $engine->lateCheck($public, new RequestFacts(...self::POST + ['setCookieNames' => ['views']]), $policy, 'template_include');
        self::assertSame(Outcome::PRIVATE, $late->outcome);
        self::assertSame('template_include', $late->hook);

        $same = $engine->lateCheck($public, new RequestFacts(...self::POST + ['status' => 404]), $policy, 'template_include');
        self::assertSame($public, $same);

        $private = $engine->decide(new RequestFacts(...self::POST + ['loggedIn' => true]), $policy);
        self::assertSame($private, $engine->lateCheck($private, new RequestFacts(...self::POST), $policy, 'template_include'));
    }

    public function testDecisionFilterCanChangeTheDecision(): void
    {
        $hooks = new CallbackHooks([
            'decision' => static fn (Decision $d): Decision => $d->with(['ttl' => 42]),
            'headers' => static fn (array $h): array => $h + ['X-Extra' => 'yes', "Bad\nName" => 'x', 'X-Evil' => "a\r\nb"],
        ]);
        $d = $this->decide(self::POST, [], $hooks);
        self::assertStringContainsString('s-maxage=42', (string) $d->header('Cache-Control'));
        self::assertSame('yes', $d->header('X-Extra'));
        self::assertNull($d->header('X-Evil'));
    }

    public function testBenchmarkStaysInsideBudget(): void
    {
        $engine = new DecisionEngine();
        $policy = new Policy();
        $facts = new RequestFacts(...self::POST + ['cookieNames' => ['_ga', '_gid'], 'queryArgNames' => ['utm_source']]);
        // Time batches of 100 decisions and take percentiles over batch means, so a
        // single preemption on a busy CI runner does not count as a slow decision.
        $means = [];
        for ($batch = 0; $batch < 100; $batch++) {
            $start = hrtime(true);
            for ($i = 0; $i < 100; $i++) {
                $engine->decide($facts, $policy);
            }
            $means[] = (hrtime(true) - $start) / 1e6 / 100;
        }
        sort($means);
        self::assertLessThan(0.1, $means[50], sprintf('median %.4f ms', $means[50]));
        self::assertLessThan(0.5, $means[98], sprintf('p99 %.4f ms', $means[98]));
    }
}
