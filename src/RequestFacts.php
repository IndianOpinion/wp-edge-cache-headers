<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders;

/**
 * Everything the decision engine needs to know about one request.
 *
 * The WordPress layer fills this from conditional tags, superglobals and the
 * header queue. Tests and the explain tool build it directly. It holds names
 * and flags only: never cookie values, query values, nonces or user ids.
 */
final class RequestFacts
{
    public const CONTEXT_FRONT = 'front';
    public const CONTEXT_REST = 'rest';
    public const CONTEXT_ADMIN = 'admin';
    public const CONTEXT_AJAX = 'ajax';
    public const CONTEXT_CRON = 'cron';
    public const CONTEXT_XMLRPC = 'xmlrpc';
    public const CONTEXT_CLI = 'cli';
    public const CONTEXT_NONE = 'none';

    public const NOCACHE_NONE = 'none';
    public const NOCACHE_CORE = 'core';
    public const NOCACHE_THIRD_PARTY = 'third-party';

    /** @var list<string> */
    public readonly array $cookieNames;
    /** @var list<string> */
    public readonly array $queryArgNames;
    /** @var list<string> */
    public readonly array $conditions;
    /** @var list<string> */
    public readonly array $queuedCacheControl;
    /** @var list<string> */
    public readonly array $setCookieNames;
    /** @var list<string> */
    public readonly array $queuedVary;
    /** @var list<string> */
    public readonly array $errors;
    public readonly string $method;

    /**
     * @param list<string> $cookieNames Names of request cookies (values are never passed in).
     * @param list<string> $queryArgNames Names of query arguments.
     * @param list<string> $conditions WordPress conditional tags that are true, without the
     *        `is_` prefix: robots, sitemap, feed, embed, search, front_page, home, singular,
     *        single, page, attachment, category, tag, tax, author, date, post_type_archive,
     *        404, customize_preview.
     * @param list<string> $queuedCacheControl Cache-Control values already queued by someone
     *        other than this plugin and core's owned no-cache helper calls.
     * @param list<string> $setCookieNames Names of cookies the response is about to set.
     * @param list<string> $queuedVary Header names in any queued Vary header.
     * @param list<string> $errors Problems found while gathering facts (for example a
     *        never-cache condition that threw). Any error makes the response PRIVATE.
     */
    public function __construct(
        public readonly string $context = self::CONTEXT_FRONT,
        string $method = 'GET',
        public readonly int $status = 200,
        public readonly bool $loggedIn = false,
        array $cookieNames = [],
        array $queryArgNames = [],
        public readonly bool $hasAuthorization = false,
        public readonly bool $hasRestNonce = false,
        public readonly bool $debugTokenValid = false,
        public readonly string $path = '/',
        array $conditions = [],
        public readonly ?string $postType = null,
        public readonly int $paged = 1,
        public readonly bool $postHasPassword = false,
        public readonly ?string $postStatus = null,
        public readonly bool $doNotCachePage = false,
        public readonly bool $neverCacheMatched = false,
        array $queuedCacheControl = [],
        public readonly string $nocacheHelper = self::NOCACHE_NONE,
        array $setCookieNames = [],
        array $queuedVary = [],
        public readonly ?string $restRoute = null,
        public readonly ?string $restNamespace = null,
        array $errors = [],
    ) {
        $this->method = strtoupper($method);
        $this->cookieNames = array_values($cookieNames);
        $this->queryArgNames = array_values($queryArgNames);
        $this->conditions = array_values($conditions);
        $this->queuedCacheControl = array_values($queuedCacheControl);
        $this->setCookieNames = array_values($setCookieNames);
        $this->queuedVary = array_values($queuedVary);
        $this->errors = array_values($errors);
    }

    public function is(string $condition): bool
    {
        return in_array($condition, $this->conditions, true);
    }

    /**
     * Copy with some fields replaced.
     *
     * @param array<string, mixed> $changes Constructor argument names to new values.
     */
    public function with(array $changes): self
    {
        $args = [
            'context' => $this->context,
            'method' => $this->method,
            'status' => $this->status,
            'loggedIn' => $this->loggedIn,
            'cookieNames' => $this->cookieNames,
            'queryArgNames' => $this->queryArgNames,
            'hasAuthorization' => $this->hasAuthorization,
            'hasRestNonce' => $this->hasRestNonce,
            'debugTokenValid' => $this->debugTokenValid,
            'path' => $this->path,
            'conditions' => $this->conditions,
            'postType' => $this->postType,
            'paged' => $this->paged,
            'postHasPassword' => $this->postHasPassword,
            'postStatus' => $this->postStatus,
            'doNotCachePage' => $this->doNotCachePage,
            'neverCacheMatched' => $this->neverCacheMatched,
            'queuedCacheControl' => $this->queuedCacheControl,
            'nocacheHelper' => $this->nocacheHelper,
            'setCookieNames' => $this->setCookieNames,
            'queuedVary' => $this->queuedVary,
            'restRoute' => $this->restRoute,
            'restNamespace' => $this->restNamespace,
            'errors' => $this->errors,
        ];
        foreach ($changes as $key => $value) {
            if (!array_key_exists($key, $args)) {
                throw new \InvalidArgumentException(sprintf('Unknown request fact "%s".', $key));
            }
            $args[$key] = $value;
        }
        return new self(...$args);
    }
}
