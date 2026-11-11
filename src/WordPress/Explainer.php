<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders\WordPress;

use IndianOpinion\EdgeCacheHeaders\Decision;
use IndianOpinion\EdgeCacheHeaders\DebugToken;
use IndianOpinion\EdgeCacheHeaders\Explain\LiveComparison;
use IndianOpinion\EdgeCacheHeaders\Outcome;
use IndianOpinion\EdgeCacheHeaders\Policy;
use IndianOpinion\EdgeCacheHeaders\RequestFacts;

/**
 * "What header would this URL get, and why?"
 *
 * Simulated mode resolves the URL in-process and runs the same engine.
 * Live mode also sends a HEAD request with a one-time debug token and
 * compares; when the loopback fails it falls back to simulation and says so.
 */
final class Explainer
{
    public const LIVE_TOKEN_SECONDS = 120;

    private readonly HttpClient $http;
    private readonly QueryResolver $resolver;

    public function __construct(
        private readonly Plugin $plugin,
        ?HttpClient $http = null,
        ?QueryResolver $resolver = null,
    ) {
        $this->http = $http ?? new WpHttpClient();
        $this->resolver = $resolver ?? new WpQueryResolver();
    }

    /**
     * @throws ExplainFailed On an invalid URL, a foreign host or no matching network site.
     */
    public function explain(string $url, ExplainOptions $options): ExplainResult
    {
        $parts = $this->validate($url);
        if ($options->live && $options->loggedIn) {
            throw new ExplainFailed('--live cannot reproduce a logged-in visitor; drop --logged-in or --live.');
        }

        $switched = false;
        if (function_exists('is_multisite') && is_multisite()) {
            $site = get_site_by_path($parts['host'], $parts['path']);
            if (!$site instanceof \WP_Site) {
                throw new ExplainFailed(sprintf('No site in this network matches %s.', $url));
            }
            if ((int) $site->blog_id !== get_current_blog_id()) {
                switch_to_blog((int) $site->blog_id);
                $switched = true;
            }
        }
        $this->plugin->reset();

        try {
            $result = $this->simulate($url, $parts, $options);
            if ($options->live) {
                $result = $this->live($result, $options);
            }
            return $result;
        } finally {
            if ($switched) {
                restore_current_blog();
            }
            $this->plugin->reset();
        }
    }

    /**
     * @return array{host: string, path: string, query: string}
     */
    public function validate(string $url): array
    {
        if (strlen($url) > 2048 || preg_match('/[\s\x00-\x1f]/', $url) === 1) {
            throw new ExplainFailed('That is not a valid URL.');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new ExplainFailed('Give a full URL, for example https://example.com/a-post/.');
        }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new ExplainFailed('Only http and https URLs can be explained.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new ExplainFailed('URLs with credentials are not accepted.');
        }
        $host = strtolower($parts['host']);
        if (!$this->hostAllowed($host)) {
            throw new ExplainFailed(sprintf('%s is not a host of this site or network.', $host));
        }
        return ['host' => $host, 'path' => $parts['path'] ?? '/', 'query' => $parts['query'] ?? ''];
    }

    private function hostAllowed(string $host): bool
    {
        if (function_exists('is_multisite') && is_multisite()) {
            return get_sites(['domain' => $host, 'number' => 1, 'fields' => 'ids']) !== [];
        }
        foreach ([home_url('/'), site_url('/')] as $known) {
            if (strtolower((string) parse_url($known, PHP_URL_HOST)) === $host) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array{host: string, path: string, query: string} $parts
     */
    private function simulate(string $url, array $parts, ExplainOptions $options): ExplainResult
    {
        $get = [];
        parse_str($parts['query'], $get);
        $requestUri = $parts['path'] . ($parts['query'] !== '' ? '?' . $parts['query'] : '');

        $server = ['REQUEST_METHOD' => $options->method, 'REQUEST_URI' => $requestUri, 'HTTP_HOST' => $parts['host']];
        foreach ($options->headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $cookies = array_fill_keys($options->cookies, '');

        $plugin = $this->plugin;
        $policy = $plugin->policy();
        $collector = new FactsCollector($plugin->settings(), new NocacheObserver(), $server, $get, $cookies, $options->loggedIn);
        $queue = new FakeHeaderQueue();
        $notes = ['Cookies, headers and redirects added while the theme prints are not simulated.'];

        $relative = $collector->path();
        $context = $this->contextFor($relative, $get);

        if ($context === RequestFacts::CONTEXT_REST) {
            $route = isset($get['rest_route']) && is_string($get['rest_route'])
                ? '/' . ltrim($get['rest_route'], '/')
                : '/' . ltrim((string) substr($relative, strlen('/wp-json')), '/');
            $facts = $collector->rest($queue, $options->method, $route, 200);
            $notes[] = 'REST responses are assumed to return 200; errors from the endpoint are not simulated.';
        } elseif ($context !== RequestFacts::CONTEXT_FRONT) {
            $facts = new RequestFacts(context: $context, method: $options->method, path: $relative);
        } else {
            $resolved = $this->resolver->resolve($url, $requestUri, $parts['host'], $get);
            try {
                $queue->code = $resolved['status'];
                $facts = $collector->front($policy, $queue);
                if ($resolved['redirect'] !== null) {
                    $notes[] = 'WordPress would answer with a canonical 301 to ' . $resolved['redirect'] . '.';
                }
            } finally {
                ($resolved['restore'])();
            }
        }

        $decision = $plugin->engine()->decide($facts, $policy, 'explain');
        if ($decision->outcome === Outcome::SKIP && in_array('disabled', $decision->reasons, true)) {
            $notes[] = 'The plugin is disabled by constant or filter.';
        }
        if (!$policy->sendXAccelExpires) {
            $notes[] = 'X-Accel-Expires is off: nginx will use its fastcgi_cache_valid TTL for every URL unless it reads Cache-Control.';
        }
        return new ExplainResult($url, $decision, ExplainResult::MODE_SIMULATED, $notes);
    }

    private function live(ExplainResult $simulated, ExplainOptions $options): ExplainResult
    {
        $notes = $simulated->notes;
        $raw = get_option(Settings::OPTION, []);
        if (!is_array($raw)) {
            $notes[] = 'Live mode skipped: the settings option is corrupt. Showing the simulated decision only.';
            return new ExplainResult($simulated->url, $simulated->decision, ExplainResult::MODE_SIMULATED, $notes);
        }

        $token = DebugToken::generate();
        $raw['live_token_hash'] = DebugToken::hash($token);
        $raw['live_token_expires'] = time() + self::LIVE_TOKEN_SECONDS;
        update_option(Settings::OPTION, $raw, true);

        $headers = $options->headers;
        $headers[Policy::DEBUG_TOKEN_HEADER] = $token;
        if ($options->cookies !== []) {
            $headers['Cookie'] = implode('; ', array_map(static fn (string $n): string => $n . '=1', $options->cookies));
        }

        try {
            $live = $this->http->head($simulated->url, $headers);
        } catch (LoopbackFailed $e) {
            $notes[] = 'Live request failed (' . $e->getMessage() . '). The host may block loopback requests. '
                . 'Showing the simulated decision only.';
            return new ExplainResult($simulated->url, $simulated->decision, ExplainResult::MODE_SIMULATED, $notes);
        } finally {
            $after = get_option(Settings::OPTION, []);
            if (is_array($after)) {
                $after['live_token_hash'] = '';
                $after['live_token_expires'] = 0;
                update_option(Settings::OPTION, $after, true);
            }
        }

        $comparison = LiveComparison::compare($simulated->decision, $live);
        return new ExplainResult($simulated->url, $simulated->decision, ExplainResult::MODE_LIVE, $notes, $comparison, $live);
    }

    /**
     * @param array<mixed> $get
     */
    private function contextFor(string $path, array $get): string
    {
        $lower = strtolower($path);
        if (str_starts_with($lower, '/wp-admin/admin-ajax.php')) {
            return RequestFacts::CONTEXT_AJAX;
        }
        if ($lower === '/wp-admin' || str_starts_with($lower, '/wp-admin/')) {
            return RequestFacts::CONTEXT_ADMIN;
        }
        if (str_starts_with($lower, '/wp-cron.php')) {
            return RequestFacts::CONTEXT_CRON;
        }
        if (str_starts_with($lower, '/xmlrpc.php')) {
            return RequestFacts::CONTEXT_XMLRPC;
        }
        if ($lower === '/wp-json' || str_starts_with($lower, '/wp-json/') || isset($get['rest_route'])) {
            return RequestFacts::CONTEXT_REST;
        }
        return RequestFacts::CONTEXT_FRONT;
    }

    /**
     * Header lines for display: the exact set the decision would send.
     *
     * @return list<string>
     */
    public static function headerLines(Decision $decision): array
    {
        $lines = [];
        foreach ($decision->headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        foreach ($decision->removeHeaders as $name) {
            $lines[] = '(removed) ' . $name;
        }
        return $lines;
    }
}
