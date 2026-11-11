<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders;

/**
 * The pure decision engine: request facts and policy in, a Decision with its
 * header set out. No WordPress calls; extension points go through Hooks.
 *
 * Order of work:
 *   1. Out of scope (disabled, admin, cron, CLI ...): SKIP.
 *   2. Classify the template type (filter `type` may override).
 *   3. Collect RESPECT reasons (someone else said no-cache) and PRIVATE reasons
 *      (every bypass condition). All are checked before any TTL is used.
 *   4. Check the status against the cacheable list.
 *   5. Choose the TTL and stale windows (filters validated and clamped).
 *   6. Errors force PRIVATE. A valid debug token forces PRIVATE.
 *   7. Filter `decision`, build headers, filter `headers`.
 */
final class DecisionEngine
{
    private const PUBLIC_STATUSES = ['publish', 'inherit'];

    private Hooks $hooks;

    /** @var array<string, string> Sitemap glob to compiled regex. */
    private static array $globCache = [];

    /** @var list<string> */
    private array $errors = [];

    /** @var list<string> */
    private array $notices = [];

    public function __construct(?Hooks $hooks = null)
    {
        $this->hooks = $hooks ?? new NullHooks();
    }

    public function decide(RequestFacts $facts, Policy $policy, string $hook = 'wp_headers'): Decision
    {
        $this->errors = array_merge($policy->errors, $facts->errors);
        $this->notices = $policy->notices;

        if (!$policy->enabled) {
            return Decision::skip($hook, 'disabled', $facts->status);
        }
        if ($facts->context !== RequestFacts::CONTEXT_FRONT && $facts->context !== RequestFacts::CONTEXT_REST) {
            return Decision::skip($hook, 'context:' . $facts->context, $facts->status);
        }

        $type = $this->classify($facts, $policy);
        $status = $facts->status === 304 ? 200 : $facts->status;

        $respect = $this->respectReasons($facts, $policy);
        $private = $this->privateReasons($facts, $policy);

        if (!$this->statusIsCacheable($status, $facts, $policy)) {
            $private[] = 'status:' . $facts->status;
        }

        [$ttl, $swr, $sie] = $this->lifetimes($type, $status, $facts, $policy);
        if ($ttl === 0) {
            $private[] = 'ttl-zero';
        }

        $informational = [];
        if ($respect === [] && $facts->queuedCacheControl !== []) {
            $informational[] = 'upstream-overridden';
        }

        if ($this->errors !== []) {
            $outcome = Outcome::PRIVATE;
            $reasons = array_merge($respect, $private, ['error']);
        } elseif ($respect !== []) {
            $outcome = Outcome::RESPECT;
            $reasons = array_merge($respect, $private);
        } elseif ($private !== []) {
            $outcome = Outcome::PRIVATE;
            $reasons = $private;
        } else {
            $outcome = Outcome::PUBLIC;
            $reasons = $informational;
        }

        $wouldBe = null;
        if ($facts->debugTokenValid) {
            $wouldBe = $outcome;
            $outcome = Outcome::PRIVATE;
            $reasons[] = 'debug-token';
        }

        $decision = new Decision(
            outcome: $outcome,
            type: $type,
            ttl: $ttl,
            browserMaxAge: min(max(0, $policy->browserMaxAge), Policy::ONE_YEAR),
            staleWhileRevalidate: $swr,
            staleIfError: $sie,
            status: $facts->status,
            reasons: array_values(array_unique($reasons)),
            hook: $hook,
            wouldBe: $wouldBe,
        );

        $decision = $this->filterDecision($decision, $facts);
        return $this->withHeaders($decision, $facts, $policy);
    }

    /**
     * Re-check a PUBLIC decision against later facts. Only ever downgrades.
     */
    public function lateCheck(Decision $previous, RequestFacts $late, Policy $policy, string $hook): Decision
    {
        if ($previous->outcome !== Outcome::PUBLIC) {
            return $previous;
        }
        $fresh = $this->decide($late, $policy, $hook);
        if ($fresh->outcome->strictness() > $previous->outcome->strictness()) {
            return $fresh;
        }
        return $previous;
    }

    /**
     * Build the header map for a decision. Public so callers that change a
     * decision (filters, tests) can rebuild headers consistently.
     */
    public function headersFor(Decision $decision, RequestFacts $facts, Policy $policy): Decision
    {
        $headers = [];
        $remove = [];
        $ttl = $decision->ttl;
        $swr = $decision->staleWhileRevalidate;
        $sie = $decision->staleIfError;
        $stale = [];
        if ($swr > 0) {
            $stale[] = 'stale-while-revalidate=' . $swr;
        }
        if ($sie > 0) {
            $stale[] = 'stale-if-error=' . $sie;
        }

        $customName = CacheControl::isHeaderName($policy->customHeaderName)
            ? $policy->customHeaderName
            : Policy::DEFAULT_CUSTOM_HEADER;

        switch ($decision->outcome) {
            case Outcome::SKIP:
                return $decision->with(['headers' => [], 'removeHeaders' => []]);

            case Outcome::PUBLIC:
                $headers['Cache-Control'] = implode(', ', array_merge(
                    ['public', 'max-age=' . $decision->browserMaxAge, 's-maxage=' . $ttl],
                    $stale,
                ));
                $shared = implode(', ', array_merge(['max-age=' . $ttl], $stale));
                if ($policy->sendCdnCacheControl) {
                    $headers['CDN-Cache-Control'] = $shared;
                }
                if ($policy->sendSurrogateControl) {
                    $headers['Surrogate-Control'] = $shared;
                }
                if ($policy->sendXAccelExpires) {
                    $headers['X-Accel-Expires'] = (string) $ttl;
                }
                if ($policy->sendCustomHeader) {
                    $headers[$customName] = 'cache';
                }
                $vary = $this->varyList($facts, $policy);
                if ($vary !== []) {
                    $headers['Vary'] = implode(', ', $vary);
                }
                $remove = ['Expires', 'Pragma'];
                break;

            case Outcome::PRIVATE:
            case Outcome::RESPECT:
                if ($decision->outcome === Outcome::PRIVATE) {
                    $headers['Cache-Control'] = 'private, no-store, max-age=0';
                }
                // On RESPECT the CDN header is always sent, so a CDN that reads it
                // first never stores a response another component marked no-cache.
                if ($policy->sendCdnCacheControl || $decision->outcome === Outcome::RESPECT) {
                    $headers['CDN-Cache-Control'] = 'no-store';
                }
                if ($policy->sendSurrogateControl) {
                    $headers['Surrogate-Control'] = 'no-store';
                }
                if ($policy->sendXAccelExpires) {
                    $headers['X-Accel-Expires'] = '0';
                }
                if ($policy->sendCustomHeader) {
                    $headers[$customName] = 'bypass';
                }
                break;
        }

        if ($policy->debug || $facts->debugTokenValid) {
            $headers[Policy::DEBUG_HEADER] = self::debugValue($decision);
        }

        return $decision->with([
            'headers' => $headers,
            'removeHeaders' => $remove,
            'notices' => array_values(array_unique(array_merge($decision->notices, $this->notices))),
        ]);
    }

    /**
     * The debug header value. Names and numbers only, never values.
     */
    public static function debugValue(Decision $decision): string
    {
        $parts = ['outcome=' . $decision->outcome->value];
        if ($decision->wouldBe !== null) {
            $parts[] = 'would-be=' . $decision->wouldBe->value;
        }
        $reasons = array_map(
            static fn (string $r): string => (string) preg_replace('/[^A-Za-z0-9_.:\-]/', '', $r),
            $decision->reasons,
        );
        array_push(
            $parts,
            'type=' . preg_replace('/[^a-z0-9_]/', '', $decision->type),
            'ttl=' . $decision->ttl,
            'swr=' . $decision->staleWhileRevalidate,
            'sie=' . $decision->staleIfError,
            'status=' . $decision->status,
            'hook=' . preg_replace('/[^a-z0-9_\-]/', '', $decision->hook),
            'reasons=' . ($reasons === [] ? 'none' : implode(',', $reasons)),
        );
        return implode('; ', $parts);
    }

    /**
     * Parse a debug header value back into fields.
     *
     * @return array<string, string>
     */
    public static function parseDebugValue(string $value): array
    {
        $out = [];
        foreach (explode(';', $value) as $part) {
            $pieces = explode('=', trim($part), 2);
            if (count($pieces) === 2) {
                $out[$pieces[0]] = $pieces[1];
            }
        }
        return $out;
    }

    private function classify(RequestFacts $facts, Policy $policy): string
    {
        $builtIn = $this->builtInType($facts, $policy);
        $filtered = $this->safeFilter('type', $builtIn, $facts);
        if (is_string($filtered) && preg_match('/^[a-z0-9_]{1,40}$/', $filtered) === 1) {
            return $filtered;
        }
        if ($filtered !== $builtIn) {
            $this->notices[] = 'type: invalid value ignored';
        }
        return $builtIn;
    }

    private function builtInType(RequestFacts $facts, Policy $policy): string
    {
        if ($facts->context === RequestFacts::CONTEXT_REST) {
            return 'rest';
        }
        if ($facts->is('robots')) {
            return 'robots';
        }
        if ($facts->is('sitemap') || $this->matchesSitemap($facts->path, $policy->sitemapPatterns)) {
            return 'sitemap';
        }
        if ($facts->is('feed')) {
            return 'feed';
        }
        if ($facts->is('embed')) {
            return 'embed';
        }
        if ($facts->is('search')) {
            return 'search';
        }
        if ($facts->is('front_page')) {
            return 'front_page';
        }
        if ($facts->is('home')) {
            return 'home';
        }
        if ($facts->is('attachment')) {
            return 'attachment';
        }
        if ($facts->is('page')) {
            return 'page';
        }
        if ($facts->is('single') || $facts->is('singular')) {
            return ($facts->postType === null || $facts->postType === 'post') ? 'singular_post' : 'singular_other';
        }
        if ($facts->is('category')) {
            return 'category';
        }
        if ($facts->is('tag')) {
            return 'tag';
        }
        if ($facts->is('tax')) {
            return 'taxonomy';
        }
        if ($facts->is('author')) {
            return 'author';
        }
        if ($facts->is('date')) {
            return 'date';
        }
        if ($facts->is('post_type_archive')) {
            return 'post_type_archive';
        }
        if ($facts->is('404')) {
            return 'not_found';
        }
        return 'other';
    }

    /**
     * @param list<string> $patterns
     */
    private function matchesSitemap(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $regex = self::$globCache[$pattern]
                ??= '#^' . str_replace(['\*', '\?'], ['[^/]*', '[^/]'], preg_quote($pattern, '#')) . '$#i';
            if (preg_match($regex, $path) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<string>
     */
    private function respectReasons(RequestFacts $facts, Policy $policy): array
    {
        $reasons = [];
        if ($facts->nocacheHelper === RequestFacts::NOCACHE_THIRD_PARTY) {
            $reasons[] = 'nocache-helper';
        }
        $queued = $facts->queuedCacheControl;
        if ($facts->doNotCachePage && ($queued !== [] || $reasons !== [])) {
            $reasons[] = 'donotcachepage';
        }
        $noCache = false;
        foreach ($queued as $value) {
            if (CacheControl::isNoCache($value)) {
                $noCache = true;
            }
        }
        if ($noCache) {
            $reasons[] = 'upstream';
        } elseif ($queued !== [] && $policy->respectUpstreamPublic) {
            $reasons[] = 'upstream-public';
        }
        return $reasons;
    }

    /**
     * @return list<string>
     */
    private function privateReasons(RequestFacts $facts, Policy $policy): array
    {
        $reasons = [];
        if ($facts->doNotCachePage && $facts->queuedCacheControl === [] && $facts->nocacheHelper !== RequestFacts::NOCACHE_THIRD_PARTY) {
            $reasons[] = 'donotcachepage';
        }
        if ($facts->loggedIn) {
            $reasons[] = 'logged-in';
        }
        foreach ($policy->bypassCookiePrefixes as $prefix) {
            if ($prefix === '') {
                continue;
            }
            foreach ($facts->cookieNames as $name) {
                if (str_starts_with($name, $prefix)) {
                    $reasons[] = 'cookie:' . $prefix;
                    break;
                }
            }
        }
        if ($facts->neverCacheMatched) {
            $reasons[] = 'commerce';
        }
        if ($facts->hasAuthorization) {
            $reasons[] = 'authorization';
        }
        foreach ($facts->queryArgNames as $arg) {
            if (in_array($arg, $policy->bypassQueryArgs, true)) {
                $reasons[] = self::queryArgReason($arg);
            }
        }
        if ($facts->is('customize_preview')) {
            $reasons[] = 'preview';
        }
        if ($facts->context === RequestFacts::CONTEXT_REST) {
            if ($facts->hasRestNonce) {
                $reasons[] = 'nonce';
            }
            if (in_array('_jsonp', $facts->queryArgNames, true)) {
                $reasons[] = 'jsonp';
            }
            if ($facts->restRoute !== null && $this->routeDenied($facts->restRoute, $policy->restRouteDenylist)) {
                $reasons[] = 'rest-denylist';
            }
        }
        if ($facts->postHasPassword) {
            $reasons[] = 'password';
        }
        if ($facts->postStatus !== null && !in_array($facts->postStatus, self::PUBLIC_STATUSES, true)) {
            $reasons[] = 'non-public-status';
        }
        if ($facts->method !== 'GET' && $facts->method !== 'HEAD') {
            $reasons[] = 'method';
        }
        foreach ($facts->setCookieNames as $name) {
            $reasons[] = 'set-cookie:' . CacheControl::safeToken($name);
        }
        $path = strtolower($facts->path);
        foreach ($policy->bypassPaths as $prefix) {
            if ($prefix !== '' && str_starts_with($path, strtolower($prefix))) {
                $reasons[] = 'path';
                break;
            }
        }
        if (!$policy->varyIsHonoured) {
            foreach ($facts->queuedVary as $name) {
                if (strcasecmp($name, 'Accept-Encoding') !== 0) {
                    $reasons[] = 'unsafe-vary:' . CacheControl::safeToken($name === '*' ? 'star' : $name);
                }
            }
        }
        return array_values(array_unique($reasons));
    }

    private static function queryArgReason(string $arg): string
    {
        if (in_array($arg, Policy::MODERATION_QUERY_ARGS, true)) {
            return 'moderation';
        }
        if (in_array($arg, Policy::NONCE_QUERY_ARGS, true)) {
            return 'nonce';
        }
        return 'preview';
    }

    /**
     * @param list<string> $denylist Route prefixes.
     */
    private function routeDenied(string $route, array $denylist): bool
    {
        $route = strtolower($route);
        foreach ($denylist as $prefix) {
            if ($prefix !== '' && str_starts_with($route, strtolower($prefix))) {
                return true;
            }
        }
        return false;
    }

    private function statusIsCacheable(int $status, RequestFacts $facts, Policy $policy): bool
    {
        if ($facts->context === RequestFacts::CONTEXT_REST) {
            return $status === 200;
        }
        if ($status >= 500) {
            return false;
        }
        return in_array($status, $policy->cacheableStatuses, true);
    }

    /**
     * @return array{0: int, 1: int, 2: int} TTL, stale-while-revalidate, stale-if-error.
     */
    private function lifetimes(string $type, int $status, RequestFacts $facts, Policy $policy): array
    {
        $ttls = $policy->ttls;
        if ($facts->context === RequestFacts::CONTEXT_REST) {
            $base = $ttls['rest'];
            $base = $this->validInt(
                'rest_ttl',
                $this->safeFilter('rest_ttl', $base, $facts->restRoute, $facts->restNamespace),
                $base,
            );
        } elseif ($status >= 300 && $status < 400) {
            $base = $ttls['redirect'];
        } elseif ($status === 404 || $type === 'not_found') {
            $base = $ttls['not_found'];
        } elseif ($type === 'singular_other' && $facts->postType !== null && isset($policy->postTypeTtls[$facts->postType])) {
            $base = $policy->postTypeTtls[$facts->postType];
        } else {
            $base = $ttls[$type] ?? $ttls['other'];
        }
        $base = max(0, $base);

        $ttl = $this->validInt('ttl', $this->safeFilter('ttl', $base, $type, $facts), $base);
        $ttl = $this->clamp('ttl', $ttl, Policy::ONE_YEAR);

        $swrDefault = min($policy->staleWhileRevalidate ?? $ttl * Policy::DEFAULT_SWR_FACTOR, Policy::ONE_WEEK);
        $swr = $this->validInt(
            'stale_while_revalidate',
            $this->safeFilter('stale_while_revalidate', $swrDefault, $ttl, $type),
            $swrDefault,
        );
        $swr = $this->clamp('stale_while_revalidate', $swr, Policy::ONE_WEEK);

        $sieDefault = min(max(0, $policy->staleIfError), Policy::ONE_WEEK);
        $sie = $this->validInt('stale_if_error', $this->safeFilter('stale_if_error', $sieDefault, $type), $sieDefault);
        $sie = $this->clamp('stale_if_error', $sie, Policy::ONE_WEEK);

        return [$ttl, $swr, $sie];
    }

    private function clamp(string $name, int $value, int $max): int
    {
        if ($value > $max) {
            $this->notices[] = $name . ': clamped to ' . $max;
            return $max;
        }
        return $value;
    }

    /**
     * Accept a non-negative int or a string of digits; anything else gives the default.
     */
    private function validInt(string $name, mixed $value, int $default): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && $value !== '' && ctype_digit($value) && strlen($value) < 12) {
            return (int) $value;
        }
        $this->notices[] = $name . ': invalid value ignored';
        return $default;
    }

    private function safeFilter(string $name, mixed $value, mixed ...$args): mixed
    {
        try {
            return $this->hooks->filter($name, $value, ...$args);
        } catch (\Throwable $e) {
            $this->errors[] = $name . ': ' . get_class($e);
            return $value;
        }
    }

    private function filterDecision(Decision $decision, RequestFacts $facts): Decision
    {
        $filtered = $this->safeFilter('decision', $decision, $facts);
        if ($filtered instanceof Decision) {
            $decision = $filtered;
        } else {
            $this->notices[] = 'decision: invalid value ignored';
        }
        if ($this->errors !== [] && $decision->outcome !== Outcome::PRIVATE && $decision->outcome !== Outcome::SKIP) {
            $decision = $decision->with([
                'outcome' => Outcome::PRIVATE,
                'reasons' => array_values(array_unique(array_merge($decision->reasons, ['error']))),
            ]);
        }
        return $decision;
    }

    private function withHeaders(Decision $decision, RequestFacts $facts, Policy $policy): Decision
    {
        $built = $this->headersFor($decision, $facts, $policy);
        $filtered = $this->safeFilter('headers', $built->headers, $built);
        if ($this->errors !== [] && $built->outcome !== Outcome::PRIVATE) {
            // The headers filter threw: fail closed.
            $closed = $built->with([
                'outcome' => Outcome::PRIVATE,
                'reasons' => array_values(array_unique(array_merge($built->reasons, ['error']))),
            ]);
            return $this->headersFor($closed, $facts, $policy)->with(['notices' => $this->errorNotices($built)]);
        }
        if (!is_array($filtered)) {
            $this->notices[] = 'headers: invalid value ignored';
            return $built->with(['notices' => $this->errorNotices($built)]);
        }
        $clean = [];
        foreach ($filtered as $name => $value) {
            if (!is_string($name) || !CacheControl::isHeaderName($name) || !is_scalar($value)) {
                $this->notices[] = 'headers: invalid entry ignored';
                continue;
            }
            $value = (string) $value;
            if (preg_match('/[\r\n\0]/', $value) === 1) {
                $this->notices[] = 'headers: value with a line break ignored';
                continue;
            }
            $clean[$name] = $value;
        }
        return $built->with(['headers' => $clean, 'notices' => $this->errorNotices($built)]);
    }

    /**
     * @return list<string>
     */
    private function errorNotices(Decision $decision): array
    {
        $errors = array_map(static fn (string $e): string => 'error: ' . $e, $this->errors);
        return array_values(array_unique(array_merge($decision->notices, $this->notices, $errors)));
    }

    /**
     * @return list<string>
     */
    private function varyList(RequestFacts $facts, Policy $policy): array
    {
        $names = [];
        foreach (array_merge($facts->queuedVary, $policy->vary) as $name) {
            if (!CacheControl::isHeaderName($name)) {
                continue;
            }
            if (!$policy->varyIsHonoured && strcasecmp($name, 'Accept-Encoding') !== 0) {
                $this->notices[] = 'vary: ' . CacheControl::safeToken($name) . ' dropped because caches do not honour Vary';
                continue;
            }
            $names[] = $name;
        }
        return CacheControl::uniqueCaseInsensitive($names);
    }
}
