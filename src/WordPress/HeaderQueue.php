<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders\WordPress;

/**
 * The response header queue. PHP's own functions in production, a fake in tests.
 */
interface HeaderQueue
{
    /**
     * Raw queued header lines, "Name: value".
     *
     * @return list<string>
     */
    public function lines(): array;

    /** Replace every header with this name. */
    public function set(string $name, string $value): void;

    /** Add a header without replacing others of the same name. */
    public function add(string $name, string $value): void;

    public function remove(string $name): void;

    /**
     * Has output started? Fills $file and $line with where it started.
     *
     * @param-out string $file
     * @param-out int $line
     */
    public function sent(?string &$file = null, ?int &$line = null): bool;

    /** Current response status, 200 when none is set. */
    public function status(): int;
}
