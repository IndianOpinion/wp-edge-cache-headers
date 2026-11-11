<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders\WordPress;

/**
 * HeaderQueue backed by header(), header_remove() and headers_list().
 */
final class PhpHeaderQueue implements HeaderQueue
{
    public function lines(): array
    {
        return headers_list();
    }

    public function set(string $name, string $value): void
    {
        header($name . ': ' . $value, true);
    }

    public function add(string $name, string $value): void
    {
        header($name . ': ' . $value, false);
    }

    public function remove(string $name): void
    {
        header_remove($name);
    }

    /**
     * @param-out string $file
     * @param-out int $line
     */
    public function sent(?string &$file = null, ?int &$line = null): bool
    {
        $f = '';
        $l = 0;
        $sent = headers_sent($f, $l);
        $file = $f;
        $line = $l;
        return $sent;
    }

    public function status(): int
    {
        $code = http_response_code();
        return is_int($code) && $code > 0 ? $code : 200;
    }
}
