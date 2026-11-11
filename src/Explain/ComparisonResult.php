<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders\Explain;

/**
 * Outcome of a live comparison.
 */
final class ComparisonResult
{
    /**
     * @param list<string> $differences One line per field that differs.
     * @param array<string, string> $observed Cache status headers seen on the live response.
     */
    public function __construct(
        public readonly bool $matches,
        public readonly array $differences,
        public readonly array $observed = [],
    ) {
    }
}
