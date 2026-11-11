<?php

// phpcs:ignoreFile

declare(strict_types=1);

namespace WP_CLI\Utils;

use IndianOpinion\EdgeCacheHeaders\Tests\Stubs\WpStub;

/**
 * @param list<array<string, mixed>> $items
 * @param list<string> $fields
 */
function format_items(string $format, array $items, array $fields): void
{
    foreach ($items as $item) {
        $cells = [];
        foreach ($fields as $field) {
            $cells[] = (string) ($item[$field] ?? '');
        }
        WpStub::$output[] = implode("\t", $cells);
    }
}
