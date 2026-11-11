<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders\WordPress;

/**
 * Minimal HTTP client for the live explain mode.
 */
interface HttpClient
{
    /**
     * Send a HEAD request and return the response headers.
     *
     * @param array<string, string> $headers
     * @return array<string, string> Response headers, lower-case names.
     * @throws LoopbackFailed When the request cannot be made.
     */
    public function head(string $url, array $headers): array;
}
