<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders;

/**
 * Hooks that change nothing. Used outside WordPress and in tests.
 */
final class NullHooks implements Hooks
{
    public function filter(string $name, mixed $value, mixed ...$args): mixed
    {
        return $value;
    }

    public function action(string $name, mixed ...$args): void
    {
    }
}
