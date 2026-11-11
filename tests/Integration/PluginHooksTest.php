<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders\Tests\Integration;

use IndianOpinion\EdgeCacheHeaders\Decision;
use IndianOpinion\EdgeCacheHeaders\Outcome;
use IndianOpinion\EdgeCacheHeaders\Tests\Stubs\WpStub;

final class PluginHooksTest extends PluginTestCase
{
    public function testT51DecisionInsideWpHeadersUsesConditionalTags(): void
    {
        $this->single();
        $headers = apply_filters('wp_headers', ['Content-Type' => 'text/html'], null);
        self::assertSame('public, max-age=0, s-maxage=1800, stale-while-revalidate=9000, stale-if-error=86400', $headers['Cache-Control']);
        self::assertSame('1800', $headers['X-Accel-Expires']);
        self::assertSame('text/html', $headers['Content-Type']);
        $decision = $this->plugin->currentDecision();
        self::assertInstanceOf(Decision::class, $decision);
        self::assertSame('wp_headers', $decision->hook);
        self::assertSame('singular_post', $decision->type);
    }

    public function testT52OneCacheControlAfterSendHeaders(): void
    {
        $this->single();
        $this->queue->add('Cache-Control', 'public, max-age=10');
        $this->queue->set('Pragma', 'no-cache');
        $this->queue->set('Expires', 'Wed, 11 Jan 1984 05:00:00 GMT');
        $this->coreSendHeaders();
        self::assertCount(1, $this->queue->values('Cache-Control'));
        self::assertStringContainsString('s-maxage=1800', $this->queue->values('Cache-Control')[0]);
        self::assertSame([], $this->queue->values('Expires'));
        self::assertSame([], $this->queue->values('Pragma'));
    }

    public function testT10Core404NoCacheIsOwned(): void
    {
        WpStub::conditions(['404']);
        $this->coreHandle404();
        $this->coreSendHeaders();
        self::assertSame(Outcome::PUBLIC, $this->plugin->currentDecision()?->outcome);
        self::assertSame(['public, max-age=0, s-maxage=60, stale-while-revalidate=300, stale-if-error=86400'], $this->queue->values('Cache-Control'));
        self::assertSame([], $this->queue->values('Expires'));
        self::assertSame([], $this->queue->values('Pragma'));
    }

    public function testT24LoggedInWithCoreNocache(): void
    {
        $this->single();
        WpStub::$loggedIn = true;
        $this->callNocacheHelper();
        $this->coreSendHeaders();
        self::assertSame(['private, no-store, max-age=0'], $this->queue->values('Cache-Control'));
        self::assertSame(['logged-in'], $this->plugin->currentDecision()?->reasons);
    }

    public function testT25ThirdPartyNocacheHelperIsRespected(): void
    {
        $this->single();
        $this->callNocacheHelper();
        $this->coreSendHeaders();
        $d = $this->plugin->currentDecision();
        self::assertSame(Outcome::RESPECT, $d?->outcome);
        self::assertSame(['no-cache, must-revalidate, max-age=0, no-store, private'], $this->queue->values('Cache-Control'));
        self::assertSame(['0'], $this->queue->values('X-Accel-Expires'));
        self::assertSame(['bypass'], $this->queue->values('X-Edge-Cache'));
        self::assertSame(['no-store'], $this->queue->values('CDN-Cache-Control'));
    }

    public function testT42OutOfScopeContextsAreUntouched(): void
    {
        foreach (['admin', 'ajax', 'cron', 'xmlrpc', 'cli'] as $context) {
            $this->context = $context;
            $this->plugin->reset();
            $in = ['Content-Type' => 'text/html'];
            self::assertSame($in, apply_filters('wp_headers', $in, null), $context);
            do_action('send_headers', null);
            self::assertNull($this->plugin->currentDecision(), $context);
        }
        self::assertSame([], $this->queue->lines());
    }

    public function testT47CanonicalRedirectAfterMainDecision(): void
    {
        $this->single();
        $this->coreSendHeaders();
        $status = apply_filters('wp_redirect_status', 301, 'https://example.com/hello-world/');
        self::assertSame(301, $status);
        $d = $this->plugin->currentDecision();
        self::assertSame('wp_redirect_status', $d?->hook);
        self::assertSame(301, $d?->status);
        self::assertSame('singular_post', $d?->type);
        self::assertCount(1, $this->queue->values('Cache-Control'));
        self::assertStringContainsString('s-maxage=600', $this->queue->values('Cache-Control')[0]);

        apply_filters('wp_redirect_status', 302, 'https://example.com/login/');
        self::assertSame(['private, no-store, max-age=0'], $this->queue->values('Cache-Control'));
        self::assertSame(['status:302'], $this->plugin->currentDecision()?->reasons);
    }

    public function testT53LateSetCookieDowngrades(): void
    {
        $this->single();
        $this->coreSendHeaders();
        $this->queue->add('Set-Cookie', 'views=12345; path=/');
        $this->templateInclude();
        self::assertSame(Outcome::PRIVATE, $this->plugin->currentDecision()?->outcome);
        self::assertSame(['set-cookie:views'], $this->plugin->currentDecision()?->reasons);
        self::assertSame(['private, no-store, max-age=0'], $this->queue->values('Cache-Control'));
    }

    public function testT53LateNoCacheHeaderGivesRespect(): void
    {
        $this->single();
        $this->coreSendHeaders();
        $this->queue->add('Cache-Control', 'no-store');
        $this->templateInclude();
        self::assertSame(Outcome::RESPECT, $this->plugin->currentDecision()?->outcome);
        self::assertSame(['no-store'], $this->queue->values('Cache-Control'), 'only the other component\'s header stays');
        self::assertSame(['0'], $this->queue->values('X-Accel-Expires'));
    }

    public function testLateCacheableHeaderFromAnotherPluginIsReplacedAgain(): void
    {
        $this->single();
        $this->coreSendHeaders();
        $own = $this->queue->values('Cache-Control');
        // A plugin calls header('Cache-Control: ...') at template_redirect, replacing ours.
        $this->queue->set('Cache-Control', 'public, max-age=10');
        $this->templateInclude();
        self::assertSame(Outcome::PUBLIC, $this->plugin->currentDecision()?->outcome);
        self::assertSame($own, $this->queue->values('Cache-Control'), 'exactly one Cache-Control, the plugin\'s');
    }

    public function testErrorQueryVarStatusIsUsedAtWpHeaders(): void
    {
        // Core sets the status from the `error` query var only after wp_headers.
        $wp = new \stdClass();
        $wp->query_vars = ['error' => '500'];
        apply_filters('wp_headers', [], $wp);
        self::assertSame(Outcome::PRIVATE, $this->plugin->currentDecision()?->outcome);
        self::assertSame(['status:500'], $this->plugin->currentDecision()?->reasons);
    }

    public function testRedirectWithEmptyLocationIsIgnored(): void
    {
        $this->single();
        $this->coreSendHeaders();
        apply_filters('wp_redirect_status', 302, '');
        self::assertSame('wp_headers', $this->plugin->currentDecision()?->hook);
        self::assertSame(Outcome::PUBLIC, $this->plugin->currentDecision()?->outcome);
    }

    public function testT53LateStatusChangeDowngrades(): void
    {
        $this->single();
        $this->coreSendHeaders();
        $this->queue->code = 500;
        $this->templateInclude();
        self::assertSame(['status:500'], $this->plugin->currentDecision()?->reasons);
    }

    public function testT54LateCheckNeverUpgrades(): void
    {
        $this->single();
        WpStub::$loggedIn = true;
        $this->coreSendHeaders();
        WpStub::$loggedIn = false;
        $this->templateInclude();
        self::assertSame(Outcome::PRIVATE, $this->plugin->currentDecision()?->outcome);
        self::assertSame('wp_headers', $this->plugin->currentDecision()?->hook);
    }

    public function testT55HeadersAlreadySent(): void
    {
        $this->single();
        $this->queue->isSent = true;
        $this->queue->sentFile = '/theme/functions.php';
        $this->queue->sentLine = 3;
        $this->coreSendHeaders();
        self::assertNull($this->plugin->currentDecision());
        self::assertSame([], array_filter($this->queue->calls, static fn (string $c): bool => str_contains($c, 'X-Edge')));
        self::assertSame(0, $this->logLines(), 'no log line when debug is off');

        $this->settings(['debug' => true]);
        $this->coreSendHeaders();
        $this->coreSendHeaders();
        self::assertSame(1, $this->logLines(), 'one log line per request in debug mode');
        self::assertStringContainsString('/theme/functions.php:3', (string) file_get_contents($this->logFile));
    }

    public function testStrictModeCatchesCookiesSetWhileThePagePrints(): void
    {
        $this->settings(['strict_mode' => true]);
        $this->single();
        $this->coreSendHeaders();
        $level = ob_get_level();
        $this->templateInclude();
        self::assertSame($level + 1, ob_get_level(), 'strict mode starts a buffer');
        $this->queue->add('Set-Cookie', 'theme_counter=1');
        echo 'page body';
        $body = (string) ob_get_clean();
        self::assertSame('page body', $body);
        self::assertSame('strict-buffer', $this->plugin->currentDecision()?->hook);
        self::assertSame(['private, no-store, max-age=0'], $this->queue->values('Cache-Control'));
    }

    public function testStrictModeIsOffByDefault(): void
    {
        $this->single();
        $this->coreSendHeaders();
        $level = ob_get_level();
        $this->templateInclude();
        self::assertSame($level, ob_get_level());
    }

    public function testRestPreServe(): void
    {
        $this->context = 'rest';
        $served = apply_filters('rest_pre_serve_request', false, new \WP_HTTP_Response(200), new \WP_REST_Request('GET', '/wp/v2/posts'), null);
        self::assertFalse($served);
        self::assertStringContainsString('s-maxage=60', $this->queue->values('Cache-Control')[0]);

        $_SERVER['HTTP_X_WP_NONCE'] = 'abc';
        apply_filters('rest_pre_serve_request', false, new \WP_HTTP_Response(200), new \WP_REST_Request('GET', '/wp/v2/posts'), null);
        self::assertSame(['private, no-store, max-age=0'], $this->queue->values('Cache-Control'));
        self::assertSame(['nonce'], $this->plugin->currentDecision()?->reasons);
    }

    public function testCookiesAndQueryArgsComeFromTheRequest(): void
    {
        $this->single();
        $_COOKIE = ['wordpress_logged_in_deadbeef' => 'garbage-value'];
        $_GET = ['preview' => 'true'];
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer x';
        $this->coreSendHeaders();
        self::assertSame(['cookie:wordpress_logged_in_', 'authorization', 'preview'], $this->plugin->currentDecision()?->reasons);
    }

    public function testDebugTokenFromSettingsHash(): void
    {
        $token = str_repeat('ab', 32);
        $this->settings(['debug_token_hash' => hash('sha256', $token)]);
        $this->single();
        $_SERVER['HTTP_X_EDGE_CACHE_DEBUG_TOKEN'] = $token;
        $this->coreSendHeaders();
        self::assertSame(Outcome::PRIVATE, $this->plugin->currentDecision()?->outcome);
        self::assertStringContainsString('would-be=PUBLIC', $this->queue->values('X-Edge-Cache-Debug')[0]);

        $this->plugin->reset();
        $this->queue = new \IndianOpinion\EdgeCacheHeaders\WordPress\FakeHeaderQueue();
        $_SERVER['HTTP_X_EDGE_CACHE_DEBUG_TOKEN'] = 'wrong';
        $headers = apply_filters('wp_headers', [], null);
        self::assertArrayNotHasKey('X-Edge-Cache-Debug', $headers);
    }

    public function testCommerceConditionsAndThrowingCondition(): void
    {
        $this->single();
        add_filter('edge_cache_headers_never_cache_conditions', static fn (array $c): array => [static fn (): bool => true]);
        $this->coreSendHeaders();
        self::assertSame(['commerce'], $this->plugin->currentDecision()?->reasons);

        WpStub::$filters = [];
        foreach (['Cache-Control', 'X-Accel-Expires', 'X-Edge-Cache'] as $name) {
            $this->queue->remove($name);
        }
        $this->plugin->reset();
        $this->plugin->register();
        add_filter('edge_cache_headers_never_cache_conditions', static fn (array $c): array => [static function (): bool {
            throw new \LogicException('broken');
        }]);
        $this->coreSendHeaders();
        self::assertSame(['error'], $this->plugin->currentDecision()?->reasons);
    }

    public function testDecidedActionFiresOncePerDecision(): void
    {
        $seen = [];
        add_action('edge_cache_headers_decided', static function (Decision $d) use (&$seen): void {
            $seen[] = $d->hook;
        });
        $this->single();
        $this->coreSendHeaders();
        $this->templateInclude();
        self::assertSame(['wp_headers'], $seen);
    }

    public function testT76NoDatabaseWorkAndFastHooks(): void
    {
        $this->single();
        $start = hrtime(true);
        for ($i = 0; $i < 1000; $i++) {
            $this->plugin->reset();
            apply_filters('wp_headers', [], null);
        }
        $perRequestMs = (hrtime(true) - $start) / 1e6 / 1000;
        self::assertSame(0, WpStub::$dbCalls);
        self::assertLessThan(1.0, $perRequestMs, 'settings load, policy build and decision per request');
    }
}
