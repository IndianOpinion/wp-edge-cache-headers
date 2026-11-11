<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders\WordPress;

use IndianOpinion\EdgeCacheHeaders\CacheControl;
use IndianOpinion\EdgeCacheHeaders\DebugToken;
use IndianOpinion\EdgeCacheHeaders\Policy;
use IndianOpinion\EdgeCacheHeaders\RequestFacts;

/**
 * Gathers RequestFacts from WordPress conditional tags, the request and the
 * header queue. Reads cookie and query names only, never values.
 */
final class FactsCollector
{
    /** @var array<string, mixed> */
    private array $server;
    /** @var array<mixed> */
    private array $get;
    /** @var array<mixed> */
    private array $cookie;

    /**
     * @param array<string, mixed>|null $server Defaults to $_SERVER.
     * @param array<mixed>|null $get Defaults to $_GET.
     * @param array<mixed>|null $cookie Defaults to $_COOKIE.
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly NocacheObserver $observer,
        ?array $server = null,
        ?array $get = null,
        ?array $cookie = null,
        private readonly ?bool $loggedInOverride = null,
    ) {
        $this->server = $server ?? $_SERVER;
        $this->get = $get ?? $_GET;
        $this->cookie = $cookie ?? $_COOKIE;
    }

    /**
     * Which part of WordPress is serving this request.
     */
    public static function currentContext(): string
    {
        if (defined('WP_CLI') && constant('WP_CLI')) {
            return RequestFacts::CONTEXT_CLI;
        }
        if (defined('XMLRPC_REQUEST') && constant('XMLRPC_REQUEST')) {
            return RequestFacts::CONTEXT_XMLRPC;
        }
        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            return RequestFacts::CONTEXT_CRON;
        }
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return RequestFacts::CONTEXT_AJAX;
        }
        if (defined('REST_REQUEST') && constant('REST_REQUEST')) {
            return RequestFacts::CONTEXT_REST;
        }
        if (is_admin()) {
            return RequestFacts::CONTEXT_ADMIN;
        }
        return RequestFacts::CONTEXT_FRONT;
    }

    /**
     * Facts for a front-end request.
     *
     * @param array<string, mixed> $arrayHeaders The `wp_headers` array, when collecting inside that filter.
     * @param string|null $ownCacheControl Cache-Control this plugin queued earlier, ignored as upstream.
     */
    public function front(
        Policy $policy,
        HeaderQueue $queue,
        array $arrayHeaders = [],
        ?string $ownCacheControl = null,
        string $context = RequestFacts::CONTEXT_FRONT,
        ?int $status = null,
    ): RequestFacts {
        $errors = [];
        $lines = $queue->lines();
        foreach ($arrayHeaders as $name => $value) {
            if (is_string($name) && is_scalar($value) && (string) $value !== '') {
                $lines[] = $name . ': ' . $value;
            }
        }

        $conditions = $this->conditions();
        $status ??= $queue->status();
        if ($status === 200 && in_array('404', $conditions, true)) {
            $status = 404;
        }

        $post = function_exists('get_queried_object') ? get_queried_object() : null;
        $singular = in_array('singular', $conditions, true);
        $postType = null;
        $hasPassword = false;
        $postStatus = null;
        if ($singular && $post instanceof \WP_Post) {
            $postType = $post->post_type;
            $hasPassword = $post->post_password !== '';
            $postStatus = $post->post_status;
        } elseif (in_array('post_type_archive', $conditions, true)) {
            $queried = get_query_var('post_type');
            $postType = is_array($queried) ? (string) reset($queried) : (string) $queried;
        }

        $paged = max(1, (int) get_query_var('paged'));

        return new RequestFacts(
            context: $context,
            method: $this->method(),
            status: $status,
            loggedIn: $this->loggedIn(),
            cookieNames: $this->cookieNames(),
            queryArgNames: $this->queryArgNames(),
            hasAuthorization: $this->hasAuthorization(),
            hasRestNonce: false,
            debugTokenValid: $this->debugTokenValid(),
            path: $this->path(),
            conditions: $conditions,
            postType: $postType,
            paged: $paged,
            postHasPassword: $hasPassword,
            postStatus: $postStatus,
            doNotCachePage: defined('DONOTCACHEPAGE') && (bool) constant('DONOTCACHEPAGE'),
            neverCacheMatched: $this->neverCache($errors),
            queuedCacheControl: $this->upstreamCacheControl($lines, $ownCacheControl),
            nocacheHelper: $this->observer->kind(),
            setCookieNames: CacheControl::cookieNames(CacheControl::values($lines, 'Set-Cookie')),
            queuedVary: CacheControl::varyNames(CacheControl::values($lines, 'Vary')),
            errors: $errors,
        );
    }

    /**
     * Facts for a REST response about to be served.
     */
    public function rest(
        HeaderQueue $queue,
        string $method,
        string $route,
        int $status,
        ?string $ownCacheControl = null,
    ): RequestFacts {
        $lines = $queue->lines();
        $segments = explode('/', trim($route, '/'));
        $namespace = count($segments) >= 2 ? $segments[0] . '/' . $segments[1] : ($segments[0] ?? '');
        return new RequestFacts(
            context: RequestFacts::CONTEXT_REST,
            method: $method,
            status: $status,
            loggedIn: $this->loggedIn(),
            cookieNames: $this->cookieNames(),
            queryArgNames: $this->queryArgNames(),
            hasAuthorization: $this->hasAuthorization(),
            hasRestNonce: $this->header('X-WP-Nonce') !== null,
            debugTokenValid: $this->debugTokenValid(),
            path: $this->path(),
            doNotCachePage: defined('DONOTCACHEPAGE') && (bool) constant('DONOTCACHEPAGE'),
            queuedCacheControl: $this->upstreamCacheControl($lines, $ownCacheControl),
            nocacheHelper: $this->observer->kind(),
            setCookieNames: CacheControl::cookieNames(CacheControl::values($lines, 'Set-Cookie')),
            queuedVary: CacheControl::varyNames(CacheControl::values($lines, 'Vary')),
            restRoute: $route,
            restNamespace: $namespace,
        );
    }

    /**
     * @return list<string>
     */
    public function conditions(): array
    {
        $map = [
            'robots' => 'is_robots',
            'feed' => 'is_feed',
            'embed' => 'is_embed',
            'search' => 'is_search',
            'front_page' => 'is_front_page',
            'home' => 'is_home',
            'singular' => 'is_singular',
            'single' => 'is_single',
            'page' => 'is_page',
            'attachment' => 'is_attachment',
            'category' => 'is_category',
            'tag' => 'is_tag',
            'tax' => 'is_tax',
            'author' => 'is_author',
            'date' => 'is_date',
            'post_type_archive' => 'is_post_type_archive',
            '404' => 'is_404',
            'customize_preview' => 'is_customize_preview',
        ];
        $out = [];
        global $wp_query;
        $hasQuery = isset($wp_query) && $wp_query instanceof \WP_Query;
        foreach ($map as $name => $function) {
            // PHP turns the key '404' into an int; conditions are strings.
            $name = (string) $name;
            if ($name !== 'customize_preview' && !$hasQuery) {
                continue;
            }
            if (function_exists($function) && $function()) {
                $out[] = $name;
            }
        }
        if ($hasQuery && function_exists('get_query_var') && (string) get_query_var('sitemap') !== '') {
            $out[] = 'sitemap';
        }
        return $out;
    }

    public function method(): string
    {
        $method = $this->server['REQUEST_METHOD'] ?? 'GET';
        return is_string($method) && preg_match('/^[A-Za-z]{1,16}$/', $method) === 1 ? strtoupper($method) : 'GET';
    }

    public function loggedIn(): bool
    {
        if ($this->loggedInOverride !== null) {
            return $this->loggedInOverride;
        }
        return function_exists('is_user_logged_in') && is_user_logged_in();
    }

    /**
     * @return list<string>
     */
    public function cookieNames(): array
    {
        return array_values(array_map('strval', array_keys($this->cookie)));
    }

    /**
     * @return list<string>
     */
    public function queryArgNames(): array
    {
        return array_values(array_map('strval', array_keys($this->get)));
    }

    public function hasAuthorization(): bool
    {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'PHP_AUTH_USER', 'PHP_AUTH_DIGEST'] as $key) {
            if (isset($this->server[$key]) && $this->server[$key] !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * Path relative to the site root, without the query string.
     */
    public function path(): string
    {
        $uri = $this->server['REQUEST_URI'] ?? '/';
        $path = is_string($uri) ? (string) parse_url($uri, PHP_URL_PATH) : '/';
        $home = function_exists('home_url') ? (string) parse_url(home_url('/'), PHP_URL_PATH) : '/';
        $base = rtrim($home, '/');
        if ($base !== '' && str_starts_with($path, $base . '/')) {
            $path = substr($path, strlen($base));
        } elseif ($base !== '' && $path === $base) {
            $path = '/';
        }
        return $path === '' ? '/' : $path;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $this->server[$key] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }

    public function debugTokenValid(): bool
    {
        $presented = $this->header(Policy::DEBUG_TOKEN_HEADER);
        if ($presented === null) {
            return false;
        }
        if ($this->settings->constantDebugToken !== null) {
            return DebugToken::matchesPlain($presented, $this->settings->constantDebugToken);
        }
        $v = $this->settings->values;
        if (is_string($v['debug_token_hash']) && DebugToken::matchesHash($presented, $v['debug_token_hash'])) {
            return true;
        }
        $expires = is_int($v['live_token_expires']) ? $v['live_token_expires'] : 0;
        return $expires >= time()
            && is_string($v['live_token_hash'])
            && DebugToken::matchesHash($presented, $v['live_token_hash']);
    }

    /**
     * Run the never-cache conditions (commerce pages by default).
     *
     * @param list<string> $errors
     */
    private function neverCache(array &$errors): bool
    {
        try {
            $conditions = apply_filters(WordPressHooks::PREFIX . 'never_cache_conditions', self::defaultNeverCacheConditions());
        } catch (\Throwable $e) {
            $errors[] = 'never_cache_conditions: ' . get_class($e);
            return false;
        }
        if (!is_array($conditions)) {
            return false;
        }
        foreach ($conditions as $condition) {
            if (!is_callable($condition)) {
                continue;
            }
            try {
                if ($condition()) {
                    return true;
                }
            } catch (\Throwable $e) {
                $errors[] = 'never_cache_conditions: ' . get_class($e);
            }
        }
        return false;
    }

    /**
     * Commerce pages when WooCommerce or Easy Digital Downloads is active.
     *
     * @return list<callable(): bool>
     */
    public static function defaultNeverCacheConditions(): array
    {
        $out = [];
        foreach (['is_cart', 'is_checkout', 'is_account_page', 'edd_is_checkout', 'edd_is_success_page'] as $function) {
            if (function_exists($function)) {
                $out[] = static fn (): bool => (bool) $function();
            }
        }
        return $out;
    }

    /**
     * Cache-Control values that came from someone other than this plugin and
     * core's owned no-cache calls.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    private function upstreamCacheControl(array $lines, ?string $own): array
    {
        $out = [];
        foreach (CacheControl::values($lines, 'Cache-Control') as $value) {
            if ($value === $own || $this->observer->isOwnedValue($value)) {
                continue;
            }
            $out[] = $value;
        }
        return array_values(array_unique($out));
    }
}
