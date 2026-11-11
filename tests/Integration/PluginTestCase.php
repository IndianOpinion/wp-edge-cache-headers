<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders\Tests\Integration;

use IndianOpinion\EdgeCacheHeaders\Tests\Stubs\WpStub;
use IndianOpinion\EdgeCacheHeaders\WordPress\FakeHeaderQueue;
use IndianOpinion\EdgeCacheHeaders\WordPress\Plugin;
use PHPUnit\Framework\TestCase;

abstract class PluginTestCase extends TestCase
{
    protected FakeHeaderQueue $queue;
    protected Plugin $plugin;
    protected string $context = 'front';
    protected string $logFile = '';

    protected function setUp(): void
    {
        WpStub::reset();
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/hello-world/', 'HTTP_HOST' => 'example.com'];
        $_GET = [];
        $_COOKIE = [];
        $this->queue = new FakeHeaderQueue();
        $this->plugin = new Plugin($this->queue, fn (): string => $this->context);
        Plugin::setInstance($this->plugin);
        $this->plugin->register();
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'ech');
        ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        Plugin::setInstance(null);
        if ($this->logFile !== '' && is_file($this->logFile)) {
            unlink($this->logFile);
        }
    }

    /**
     * @param array<string, string> $settings
     */
    protected function settings(array $settings): void
    {
        update_option('edge_cache_headers_settings', $settings);
        $this->plugin->reset();
    }

    /** Core's 404 handler: status, then the no-cache helper. */
    protected function coreHandle404(): void
    {
        $this->queue->code = 404;
        $this->callNocacheHelper();
    }

    /** What nocache_headers() does: filter, then header() for each. */
    protected function callNocacheHelper(): void
    {
        $headers = apply_filters('nocache_headers', [
            'Expires' => 'Wed, 11 Jan 1984 05:00:00 GMT',
            'Cache-Control' => 'no-cache, must-revalidate, max-age=0, no-store, private',
        ]);
        foreach ($headers as $name => $value) {
            $this->queue->set($name, $value);
        }
    }

    /**
     * WP::send_headers(): filter the array, header() each entry, then the action.
     *
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    protected function coreSendHeaders(array $headers = ['Content-Type' => 'text/html; charset=UTF-8']): array
    {
        $headers = apply_filters('wp_headers', $headers, null);
        foreach ($headers as $name => $value) {
            if (is_string($value) && $value !== '') {
                $this->queue->set($name, $value);
            }
        }
        do_action('send_headers', null);
        return $headers;
    }

    protected function templateInclude(): void
    {
        apply_filters('template_include', '/theme/single.php');
    }

    protected function single(string $type = 'post'): void
    {
        WpStub::conditions(['singular', 'single']);
        WpStub::$queriedObject = new \WP_Post($type);
    }

    protected function logLines(): int
    {
        $content = is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '';
        return substr_count($content, Plugin::LOG_PREFIX);
    }
}
