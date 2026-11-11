<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders\WordPress;

use IndianOpinion\EdgeCacheHeaders\Decision;
use IndianOpinion\EdgeCacheHeaders\Explain\ComparisonResult;

/**
 * What the explain tool found.
 */
final class ExplainResult
{
    public const MODE_SIMULATED = 'simulated';
    public const MODE_LIVE = 'live';

    /**
     * @param list<string> $notes What could not be simulated, and fallbacks taken.
     * @param array<string, string> $liveHeaders
     */
    public function __construct(
        public readonly string $url,
        public readonly Decision $decision,
        public readonly string $mode,
        public readonly array $notes = [],
        public readonly ?ComparisonResult $comparison = null,
        public readonly array $liveHeaders = [],
    ) {
    }

    /** 0 on match (or simulation only), 1 on a live mismatch. */
    public function exitCode(): int
    {
        return $this->comparison !== null && !$this->comparison->matches ? 1 : 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'mode' => $this->mode,
            'decision' => $this->decision->toArray(),
            'notes' => $this->notes,
            'live' => $this->comparison === null ? null : [
                'matches' => $this->comparison->matches,
                'differences' => $this->comparison->differences,
                'observed' => $this->comparison->observed,
            ],
        ];
    }
}
