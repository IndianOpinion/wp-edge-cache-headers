<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders\WordPress;

/**
 * Resolves a front-end URL into the main query in-process, the way the front end would.
 */
interface QueryResolver
{
    /**
     * Set up the global query for the URL.
     *
     * @param array<mixed> $get Query arguments.
     * @return array{status: int, redirect: ?string, restore: callable(): void}
     */
    public function resolve(string $url, string $requestUri, string $host, array $get): array;
}
