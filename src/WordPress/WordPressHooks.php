<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders\WordPress;

use IndianOpinion\EdgeCacheHeaders\Hooks;

/**
 * Maps engine hooks to WordPress filters and actions prefixed `edge_cache_headers_`.
 */
final class WordPressHooks implements Hooks
{
    public const PREFIX = 'edge_cache_headers_';

    public function filter(string $name, mixed $value, mixed ...$args): mixed
    {
        return apply_filters(self::PREFIX . $name, $value, ...$args);
    }

    public function action(string $name, mixed ...$args): void
    {
        do_action(self::PREFIX . $name, ...$args);
    }
}
