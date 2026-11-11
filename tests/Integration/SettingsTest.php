<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders\Tests\Integration;

use IndianOpinion\EdgeCacheHeaders\Outcome;
use IndianOpinion\EdgeCacheHeaders\Tests\Stubs\WpStub;
use IndianOpinion\EdgeCacheHeaders\WordPress\Admin\SettingsPage;
use IndianOpinion\EdgeCacheHeaders\WordPress\Settings;
use IndianOpinion\EdgeCacheHeaders\WordPress\Uninstaller;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class SettingsTest extends PluginTestCase
{
    private function sitemapTtl(): int
    {
        $this->plugin->reset();
        WpStub::conditions([]);
        WpStub::$queryVars['sitemap'] = 'posts';
        apply_filters('wp_headers', [], null);
        return (int) $this->plugin->currentDecision()?->ttl;
    }

    public function testT65NetworkThenSiteThenFilter(): void
    {
        WpStub::$multisite = true;
        update_site_option(Settings::NETWORK_OPTION, ['profile' => 'quiet']);
        self::assertSame(1800, $this->sitemapTtl(), 'network default used when the site has none');

        update_option(Settings::OPTION, ['profile' => 'busy']);
        self::assertSame(120, $this->sitemapTtl(), 'site override wins');

        add_filter('edge_cache_headers_ttls', static fn (array $t): array => ['sitemap' => 77] + $t);
        self::assertSame(77, $this->sitemapTtl(), 'filter wins last');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testT65ConstantWinsOverNetworkAndSite(): void
    {
        WpStub::$multisite = true;
        update_site_option(Settings::NETWORK_OPTION, ['profile' => 'quiet']);
        update_option(Settings::OPTION, ['profile' => 'busy']);
        define('EDGE_CACHE_HEADERS_PROFILE', 'steady');
        self::assertSame(300, $this->sitemapTtl());
        self::assertTrue($this->plugin->settings()->locked('profile'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testT73DisabledByConstant(): void
    {
        define('EDGE_CACHE_HEADERS_DISABLED', true);
        $this->single();
        $headers = $this->coreSendHeaders();
        self::assertArrayNotHasKey('Cache-Control', $headers);
        self::assertSame(Outcome::SKIP, $this->plugin->currentDecision()?->outcome);
        self::assertSame([], $this->queue->values('X-Edge-Cache'));
    }

    public function testT66NetworkAdminSkipAndSubdomainSiteCookie(): void
    {
        WpStub::$multisite = true;
        $this->context = 'admin';
        $this->single();
        self::assertSame([], apply_filters('wp_headers', [], null));

        $this->context = 'front';
        switch_to_blog(2);
        WpStub::$home = 'https://two.example.com/';
        $this->plugin->reset();
        $_COOKIE = ['wordpress_logged_in_abc' => 'x'];
        apply_filters('wp_headers', [], null);
        self::assertSame(Outcome::PRIVATE, $this->plugin->currentDecision()?->outcome);
    }

    public function testT72CorruptOptionUsesDefaultsAndWarns(): void
    {
        update_option(Settings::OPTION, 'not an array');
        $this->plugin->reset();
        self::assertTrue($this->plugin->settings()->corrupt);
        $this->single();
        apply_filters('wp_headers', [], null);
        self::assertSame(1800, $this->plugin->currentDecision()?->ttl);

        ob_start();
        (new SettingsPage())->corruptNotice();
        $html = (string) ob_get_clean();
        self::assertStringContainsString('corrupt', $html);
    }

    public function testT75UninstallRemovesOnlyOurOptions(): void
    {
        WpStub::$multisite = true;
        WpStub::$sites = [new \WP_Site('1', 'example.com'), new \WP_Site('2', 'two.example.com')];
        WpStub::$options = [
            1 => [Settings::OPTION => ['debug' => true], 'blogname' => 'One'],
            2 => [Settings::OPTION => ['debug' => true], 'blogname' => 'Two'],
        ];
        WpStub::$siteOptions = [Settings::NETWORK_OPTION => [], 'site_name' => 'Net'];
        Uninstaller::run();
        self::assertSame([1 => ['blogname' => 'One'], 2 => ['blogname' => 'Two']], WpStub::$options);
        self::assertSame(['site_name' => 'Net'], WpStub::$siteOptions);
    }

    public function testUninstallSingleSite(): void
    {
        WpStub::$options = [1 => [Settings::OPTION => [], 'blogname' => 'One']];
        Uninstaller::run();
        self::assertSame([1 => ['blogname' => 'One']], WpStub::$options);
    }

    public function testSanitizeDropsUnknownAndInvalidValues(): void
    {
        $notices = [];
        $clean = Settings::sanitize([
            'profile' => 'enormous',
            'posts_per_hour' => '12.5',
            'ttls' => ['singular_post' => '900', 'bad type!' => 5, 'page' => -1],
            'custom_header_name' => "X-Bad\r\nInjected: yes",
            'extra_paths' => "/members/\nnot-a-path\n/shop/",
            'debug' => '1',
            'something_else' => 1,
        ], $notices);
        self::assertSame([
            'posts_per_hour' => 12.5,
            'ttls' => ['singular_post' => 900],
            'extra_paths' => ['/members/', '/shop/'],
            'debug' => true,
        ], $clean);
        self::assertContains('setting profile: invalid value ignored', $notices);
        self::assertContains('setting custom_header_name: invalid value ignored', $notices);
    }

    public function testSettingsFlowIntoTheDecision(): void
    {
        $this->settings([
            'ttls' => ['singular_post' => 1200],
            'send_cdn_cache_control' => true,
            'extra_cookie_prefixes' => ['my_session_'],
        ]);
        $this->single();
        $_COOKIE = ['my_session_x' => '1'];
        apply_filters('wp_headers', [], null);
        self::assertSame(['cookie:my_session_'], $this->plugin->currentDecision()?->reasons);
        self::assertSame(1200, $this->plugin->currentDecision()?->ttl);

        $this->plugin->reset();
        $_COOKIE = [];
        WpStub::conditions(['embed', 'singular']);
        apply_filters('wp_headers', [], null);
        self::assertSame(1200, $this->plugin->currentDecision()?->ttl, 'embed follows the singular post override');
    }

    public function testThrowingSettingsFilterFailsClosed(): void
    {
        add_filter('edge_cache_headers_bypass_cookie_prefixes', static function (): array {
            throw new \RuntimeException('bad filter');
        });
        $this->single();
        apply_filters('wp_headers', [], null);
        self::assertSame(['error'], $this->plugin->currentDecision()?->reasons);
    }

    public function testAdminSaveNeedsCapabilityAndNonceAndStoresOnlyOverrides(): void
    {
        $page = new SettingsPage();
        WpStub::$caps = [];
        self::assertStringContainsString('permission', $page->handleSave(['_wpnonce' => 'x'], false));
        WpStub::$caps = ['manage_options' => true];
        WpStub::$nonceValid = false;
        self::assertStringContainsString('expired', $page->handleSave(['_wpnonce' => 'x'], false));
        WpStub::$nonceValid = true;

        $html = $page->handleSave([
            '_wpnonce' => 'x',
            'profile' => 'busy',
            'ttls' => ['page' => '7200', 'home' => ''],
            'send_x_accel_expires' => '1',
            'send_custom_header' => '1',
            'custom_header_name' => 'X-Edge-Cache',
            'rotate_token' => '1',
        ], false);
        self::assertStringContainsString('Settings saved', $html);
        self::assertMatchesRegularExpression('#<code>[a-f0-9]{64}</code>#', $html);
        $stored = get_option(Settings::OPTION);
        self::assertIsArray($stored);
        self::assertSame('busy', $stored['profile']);
        self::assertSame(['page' => 7200], $stored['ttls']);
        self::assertArrayNotHasKey('send_x_accel_expires', $stored, 'defaults are not stored');
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $stored['debug_token_hash']);
    }

    public function testNetworkSaveNeedsNetworkCapability(): void
    {
        WpStub::$multisite = true;
        WpStub::$caps = ['manage_options' => true];
        self::assertStringContainsString('permission', (new SettingsPage())->handleSave(['_wpnonce' => 'x'], true));
        WpStub::$caps = ['manage_network_options' => true];
        (new SettingsPage())->handleSave(['_wpnonce' => 'x', 'profile' => 'quiet'], true);
        self::assertSame('quiet', WpStub::$siteOptions[Settings::NETWORK_OPTION]['profile']);
    }

    public function testSettingsPageRendersEscaped(): void
    {
        $this->settings(['extra_paths' => ['/members/']]);
        ob_start();
        (new SettingsPage())->render();
        $html = (string) ob_get_clean();
        self::assertStringContainsString('name="_wpnonce"', $html);
        self::assertStringContainsString('/members/', $html);
        self::assertStringContainsString('Explain a URL', $html);
    }
}
