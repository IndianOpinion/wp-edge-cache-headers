<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders\Tests\Integration;

use IndianOpinion\EdgeCacheHeaders\Outcome;
use IndianOpinion\EdgeCacheHeaders\Policy;
use IndianOpinion\EdgeCacheHeaders\Tests\Stubs\WpStub;
use IndianOpinion\EdgeCacheHeaders\WordPress\Admin\SettingsPage;
use IndianOpinion\EdgeCacheHeaders\WordPress\Cli\Command;
use IndianOpinion\EdgeCacheHeaders\WordPress\ExplainFailed;
use IndianOpinion\EdgeCacheHeaders\WordPress\Explainer;
use IndianOpinion\EdgeCacheHeaders\WordPress\ExplainOptions;
use IndianOpinion\EdgeCacheHeaders\WordPress\ExplainResult;
use IndianOpinion\EdgeCacheHeaders\WordPress\HttpClient;
use IndianOpinion\EdgeCacheHeaders\WordPress\LoopbackFailed;
use IndianOpinion\EdgeCacheHeaders\WordPress\QueryResolver;
use IndianOpinion\EdgeCacheHeaders\WordPress\Settings;

final class ExplainTest extends PluginTestCase
{
    /** @var array<string, string>|null */
    public ?array $liveResponse = null;
    public ?string $liveError = null;
    /** @var array<string, string> */
    public array $sentHeaders = [];

    private function explainer(): Explainer
    {
        $resolver = new class implements QueryResolver {
            public function resolve(string $url, string $requestUri, string $host, array $get): array
            {
                $path = (string) parse_url($requestUri, PHP_URL_PATH);
                $status = 200;
                if ($path === '/about/') {
                    WpStub::conditions(['page', 'singular']);
                    WpStub::$queriedObject = new \WP_Post('page');
                } elseif ($path === '/missing/') {
                    WpStub::conditions(['404']);
                    $status = 404;
                } else {
                    WpStub::conditions(['single', 'singular']);
                    WpStub::$queriedObject = new \WP_Post('post');
                }
                return ['status' => $status, 'redirect' => null, 'restore' => static function (): void {
                    WpStub::conditions([]);
                }];
            }
        };
        $test = $this;
        $http = new class ($test) implements HttpClient {
            public function __construct(private ExplainTest $test)
            {
            }

            public function head(string $url, array $headers): array
            {
                $this->test->sentHeaders = $headers;
                if ($this->test->liveError !== null) {
                    throw new LoopbackFailed($this->test->liveError);
                }
                return $this->test->liveResponse ?? [];
            }
        };
        return new Explainer($this->plugin, $http, $resolver);
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     * @return array{0: int|null, 1: string}
     */
    private function cli(string $method, array $args = [], array $assoc = []): array
    {
        $command = new Command($this->explainer());
        try {
            $command->{$method}($args, $assoc);
        } catch (\WpCliHalt $e) {
            // halt() ends the command.
        }
        return [WpStub::$haltCode, implode("\n", WpStub::$output)];
    }

    public function testT60ExplainPostUrl(): void
    {
        [$code, $out] = $this->cli('explain', ['https://example.com/hello-world/']);
        self::assertSame(0, $code);
        self::assertStringContainsString("outcome\tPUBLIC", $out);
        self::assertStringContainsString("ttl\t1800", $out);
        self::assertStringContainsString('Cache-Control: public, max-age=0, s-maxage=1800', $out);
        self::assertStringContainsString("mode\tsimulated", $out);
    }

    public function testT60LoggedInAndCookieOptions(): void
    {
        [, $out] = $this->cli('explain', ['https://example.com/hello-world/'], ['logged-in' => true]);
        self::assertStringContainsString("outcome\tPRIVATE", $out);

        WpStub::$output = [];
        [, $out] = $this->cli('explain', ['https://example.com/hello-world/'], ['cookie' => 'wordpress_logged_in_x']);
        self::assertStringContainsString("outcome\tPRIVATE", $out);
        self::assertStringContainsString('cookie:wordpress_logged_in_', $out);
    }

    public function testExplainSimulatesMethodHeadersAndContexts(): void
    {
        $explainer = $this->explainer();
        $d = $explainer->explain('https://example.com/hello-world/', new ExplainOptions('POST'))->decision;
        self::assertSame(['method'], $d->reasons);
        $d = $explainer->explain('https://example.com/hello-world/', new ExplainOptions(headers: ['Authorization' => 'Bearer x']))->decision;
        self::assertSame(['authorization'], $d->reasons);
        self::assertSame(Outcome::SKIP, $explainer->explain('https://example.com/wp-admin/', new ExplainOptions())->decision->outcome);
        $rest = $explainer->explain('https://example.com/wp-json/wp/v2/posts', new ExplainOptions())->decision;
        self::assertSame('rest', $rest->type);
        self::assertSame(Outcome::PUBLIC, $rest->outcome);
        $missing = $explainer->explain('https://example.com/missing/', new ExplainOptions())->decision;
        self::assertSame('not_found', $missing->type, json_encode($missing->toArray()));
        self::assertSame('page', $explainer->explain('https://example.com/about/', new ExplainOptions())->decision->type);
    }

    public function testHostHeaderCannotBeSimulated(): void
    {
        $this->expectException(ExplainFailed::class);
        new ExplainOptions(headers: ['Host' => 'elsewhere.example']);
    }

    public function testT61LiveMismatchExitsOne(): void
    {
        $this->liveResponse = [
            'x-edge-cache-debug' => 'outcome=PRIVATE; would-be=PUBLIC; type=page; ttl=3600; swr=18000; sie=86400; status=200; hook=wp_headers; reasons=debug-token',
            'cf-cache-status' => 'MISS',
        ];
        [$code, $out] = $this->cli('explain', ['https://example.com/hello-world/'], ['live' => true]);
        self::assertSame(1, $code);
        self::assertStringContainsString('type: simulated singular_post, live page', $out);
        self::assertStringContainsString('Live cf-cache-status: MISS', $out);
        self::assertArrayHasKey(Policy::DEBUG_TOKEN_HEADER, $this->sentHeaders);
        self::assertSame(64, strlen($this->sentHeaders[Policy::DEBUG_TOKEN_HEADER]));
        $stored = get_option(Settings::OPTION);
        self::assertIsArray($stored);
        self::assertSame('', $stored['live_token_hash'], 'one-time token is cleared after use');
    }

    public function testT61LiveMatchExitsZero(): void
    {
        $this->liveResponse = [
            'x-edge-cache-debug' => 'outcome=PRIVATE; would-be=PUBLIC; type=singular_post; ttl=1800; swr=9000; sie=86400; status=200; hook=wp_headers; reasons=debug-token',
        ];
        [$code, $out] = $this->cli('explain', ['https://example.com/hello-world/'], ['live' => true]);
        self::assertSame(0, $code);
        self::assertStringContainsString("mode\tlive", $out);
    }

    public function testLoopbackBlockedFallsBackToSimulation(): void
    {
        $this->liveError = 'cURL error 7: connection refused';
        $result = $this->explainer()->explain('https://example.com/hello-world/', new ExplainOptions(live: true));
        self::assertSame(ExplainResult::MODE_SIMULATED, $result->mode);
        self::assertSame(0, $result->exitCode());
        self::assertStringContainsString('connection refused', implode(' ', $result->notes));
    }

    public function testT61InvalidUrlExitsTwo(): void
    {
        foreach (['not a url', 'ftp://example.com/', 'https://user:pw@example.com/', 'https://evil.example.net/'] as $url) {
            WpStub::$haltCode = null;
            [$code] = $this->cli('explain', [$url]);
            self::assertSame(2, $code, $url);
        }
    }

    public function testT67UrlOnNoNetworkSiteExitsTwo(): void
    {
        WpStub::$multisite = true;
        WpStub::$sites = [new \WP_Site('1', 'example.com')];
        [$code, $out] = $this->cli('explain', ['https://other.example.org/']);
        self::assertSame(2, $code);
        self::assertStringContainsString('not a host of this site or network', $out);

        WpStub::$sites = [new \WP_Site('1', 'example.com', '/blog/')];
        WpStub::$haltCode = null;
        [$code, $out] = $this->cli('explain', ['https://example.com/other/']);
        self::assertSame(2, $code);
        self::assertStringContainsString('No site in this network matches', $out);
    }

    public function testExplainOnSecondNetworkSiteSwitchesBlog(): void
    {
        WpStub::$multisite = true;
        WpStub::$sites = [new \WP_Site('1', 'example.com'), new \WP_Site('2', 'two.example.com')];
        WpStub::$options[2] = [Settings::OPTION => ['ttls' => ['singular_post' => 999]]];
        $d = $this->explainer()->explain('https://two.example.com/hello-world/', new ExplainOptions())->decision;
        self::assertSame(999, $d->ttl);
        self::assertSame(1, get_current_blog_id());
    }

    public function testLiveCannotSimulateLoggedIn(): void
    {
        $this->expectException(ExplainFailed::class);
        $this->explainer()->explain('https://example.com/', new ExplainOptions(loggedIn: true, live: true));
    }

    public function testT62AdminExplainChecks(): void
    {
        $page = new SettingsPage($this->explainer());
        $post = ['_wpnonce' => 'n', 'explain_url' => 'https://example.com/hello-world/'];

        WpStub::$caps = [];
        self::assertStringContainsString('permission', $page->explainResponse($post));

        WpStub::$caps = ['manage_options' => true];
        WpStub::$nonceValid = false;
        self::assertStringContainsString('expired', $page->explainResponse($post));

        WpStub::$nonceValid = true;
        self::assertStringContainsString('not a host', $page->explainResponse(['explain_url' => 'https://attacker.example/'] + $post));

        $html = $page->explainResponse(['explain_url' => 'https://example.com/<script>alert(1)</script>/'] + $post);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);

        $html = $page->explainResponse($post);
        self::assertStringContainsString('PUBLIC', $html);
        self::assertStringContainsString('s-maxage=1800', $html);
    }

    public function testT73ExplainReportsDisabled(): void
    {
        add_filter('edge_cache_headers_enabled', '__return_false_stub');
        $result = $this->explainer()->explain('https://example.com/hello-world/', new ExplainOptions());
        self::assertSame(Outcome::SKIP, $result->decision->outcome);
        self::assertContains('The plugin is disabled by constant or filter.', $result->notes);
    }

    public function testT68ConfigWarnsWhenXAccelExpiresIsOff(): void
    {
        $this->settings(['send_x_accel_expires' => false]);
        [, $out] = $this->cli('config');
        self::assertStringContainsString('Warning: X-Accel-Expires is off', $out);
        self::assertStringContainsString(Settings::OPTION, $out);
        self::assertStringContainsString("send_x_accel_expires\tfalse\tsite", $out);
    }

    public function testNginxAndCloudflareCommands(): void
    {
        $this->settings(['extra_cookie_prefixes' => ['my_session_']]);
        [, $nginx] = $this->cli('nginx');
        self::assertStringContainsString('my_session_', $nginx);
        WpStub::$output = [];
        [, $cf] = $this->cli('cloudflare');
        self::assertStringContainsString('not (http.cookie contains "my_session_")', $cf);
        self::assertStringContainsString('http.host in {"example.com"}', $cf);
    }

    public function testTokenCommandStoresOnlyAHash(): void
    {
        [, $out] = $this->cli('token');
        preg_match('/^[a-f0-9]{64}$/m', $out, $m);
        self::assertNotEmpty($m);
        $stored = get_option(Settings::OPTION);
        self::assertIsArray($stored);
        self::assertSame(hash('sha256', $m[0]), $stored['debug_token_hash']);
        self::assertStringNotContainsString($m[0], (string) json_encode($stored));
    }

    public function testSuggestRate(): void
    {
        WpStub::$queryVars['__found_posts'] = 13272;
        [, $out] = $this->cli('suggestRate', [], ['days' => 7, 'apply' => true]);
        self::assertStringContainsString('79.00 posts an hour, profile "busy"', $out);
        $stored = get_option(Settings::OPTION);
        self::assertIsArray($stored);
        self::assertSame(79.0, $stored['posts_per_hour']);
    }
}
