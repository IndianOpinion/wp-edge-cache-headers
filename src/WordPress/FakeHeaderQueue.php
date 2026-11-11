<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders\WordPress;

/**
 * In-memory HeaderQueue. Used by the explain tool (so a simulation never
 * touches the real response) and by tests.
 */
final class FakeHeaderQueue implements HeaderQueue
{
    /** @var list<array{0: string, 1: string}> */
    private array $headers = [];

    public bool $isSent = false;
    public string $sentFile = '';
    public int $sentLine = 0;
    public int $code = 200;

    /** @var list<string> Every call made, for assertions. */
    public array $calls = [];

    /**
     * @param list<string> $lines
     */
    public function __construct(array $lines = [])
    {
        foreach ($lines as $line) {
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $this->headers[] = [trim(substr($line, 0, $pos)), trim(substr($line, $pos + 1))];
            }
        }
    }

    public function lines(): array
    {
        return array_map(static fn (array $h): string => $h[0] . ': ' . $h[1], $this->headers);
    }

    public function set(string $name, string $value): void
    {
        $this->calls[] = 'set ' . $name;
        $this->drop($name);
        $this->headers[] = [$name, $value];
    }

    public function add(string $name, string $value): void
    {
        $this->calls[] = 'add ' . $name;
        $this->headers[] = [$name, $value];
    }

    public function remove(string $name): void
    {
        $this->calls[] = 'remove ' . $name;
        $this->drop($name);
    }

    /**
     * @param-out string $file
     * @param-out int $line
     */
    public function sent(?string &$file = null, ?int &$line = null): bool
    {
        $file = $this->sentFile;
        $line = $this->sentLine;
        return $this->isSent;
    }

    public function status(): int
    {
        return $this->code;
    }

    /**
     * @return list<string>
     */
    public function values(string $name): array
    {
        $out = [];
        foreach ($this->headers as [$key, $value]) {
            if (strcasecmp($key, $name) === 0) {
                $out[] = $value;
            }
        }
        return $out;
    }

    private function drop(string $name): void
    {
        $this->headers = array_values(array_filter(
            $this->headers,
            static fn (array $h): bool => strcasecmp($h[0], $name) !== 0,
        ));
    }
}
