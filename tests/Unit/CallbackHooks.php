<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders\Tests\Unit;

use IndianOpinion\EdgeCacheHeaders\Hooks;

/**
 * Hooks backed by closures, keyed by filter name without prefix.
 */
final class CallbackHooks implements Hooks
{
    /** @var list<array{0: string, 1: list<mixed>}> */
    public array $calls = [];

    /**
     * @param array<string, \Closure> $filters
     */
    public function __construct(private array $filters = [])
    {
    }

    public function filter(string $name, mixed $value, mixed ...$args): mixed
    {
        $this->calls[] = [$name, $args];
        if (isset($this->filters[$name])) {
            return ($this->filters[$name])($value, ...$args);
        }
        return $value;
    }

    public function action(string $name, mixed ...$args): void
    {
    }
}
