<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders;

/**
 * What a shared cache may do with the response.
 */
enum Outcome: string
{
    /** Shared caches may store the response for the TTL. */
    case PUBLIC = 'PUBLIC';

    /** No shared cache may store the response. */
    case PRIVATE = 'PRIVATE';

    /** Another component already set a no-cache policy; leave Cache-Control alone. */
    case RESPECT = 'RESPECT';

    /** The request is out of scope; send nothing. */
    case SKIP = 'SKIP';

    /**
     * Rank used by the late check: a decision may move to a higher rank, never a lower one.
     */
    public function strictness(): int
    {
        return match ($this) {
            self::SKIP => 0,
            self::PUBLIC => 1,
            self::RESPECT => 2,
            self::PRIVATE => 3,
        };
    }
}
