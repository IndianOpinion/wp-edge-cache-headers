<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders;

/**
 * Extension points the pure engine calls. The WordPress layer maps these to
 * `apply_filters( 'edge_cache_headers_' . $name, ... )` and `do_action`.
 */
interface Hooks
{
    /**
     * @param mixed ...$args Extra context passed to the filter.
     */
    public function filter(string $name, mixed $value, mixed ...$args): mixed;

    /**
     * @param mixed ...$args
     */
    public function action(string $name, mixed ...$args): void;
}
