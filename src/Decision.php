<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders;

/**
 * The result of classifying one request. Read-only.
 */
final class Decision
{
    /** @var list<string> */
    public readonly array $reasons;
    /** @var array<string, string> */
    public readonly array $headers;
    /** @var list<string> */
    public readonly array $removeHeaders;
    /** @var list<string> */
    public readonly array $notices;

    /**
     * @param list<string> $reasons Short reason strings, in the order they were found.
     * @param array<string, string> $headers Headers to send, name to value.
     * @param list<string> $removeHeaders Header names to remove from the queue (Expires, Pragma).
     * @param list<string> $notices Invalid filter values that were replaced by defaults.
     */
    public function __construct(
        public readonly Outcome $outcome,
        public readonly string $type,
        public readonly int $ttl,
        public readonly int $browserMaxAge,
        public readonly int $staleWhileRevalidate,
        public readonly int $staleIfError,
        public readonly int $status,
        array $reasons,
        public readonly string $hook,
        array $headers = [],
        array $removeHeaders = [],
        public readonly ?Outcome $wouldBe = null,
        array $notices = [],
    ) {
        $this->reasons = array_values($reasons);
        $this->headers = $headers;
        $this->removeHeaders = array_values($removeHeaders);
        $this->notices = array_values($notices);
    }

    public static function skip(string $hook, string $reason, int $status = 200): self
    {
        return new self(Outcome::SKIP, 'none', 0, 0, 0, 0, $status, [$reason], $hook);
    }

    /**
     * Copy with some fields replaced.
     *
     * @param array<string, mixed> $changes Constructor argument names to new values.
     */
    public function with(array $changes): self
    {
        $args = get_object_vars($this);
        foreach ($changes as $key => $value) {
            if (!array_key_exists($key, $args)) {
                throw new \InvalidArgumentException(sprintf('Unknown decision field "%s".', $key));
            }
            $args[$key] = $value;
        }
        return new self(...$args);
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome->value,
            'would_be' => $this->wouldBe?->value,
            'type' => $this->type,
            'ttl' => $this->ttl,
            'browser_max_age' => $this->browserMaxAge,
            'stale_while_revalidate' => $this->staleWhileRevalidate,
            'stale_if_error' => $this->staleIfError,
            'status' => $this->status,
            'reasons' => $this->reasons,
            'hook' => $this->hook,
            'headers' => $this->headers,
            'remove_headers' => $this->removeHeaders,
        ];
    }
}
