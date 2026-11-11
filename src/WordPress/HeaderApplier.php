<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders\WordPress;

use IndianOpinion\EdgeCacheHeaders\CacheControl;
use IndianOpinion\EdgeCacheHeaders\Decision;
use IndianOpinion\EdgeCacheHeaders\Outcome;

/**
 * Writes a decision into the header array core is about to send, or into the
 * PHP header queue. Replaces, never appends. Touches only the headers it owns.
 */
final class HeaderApplier
{
    /**
     * Merge a decision into the `wp_headers` array.
     *
     * @param array<string, mixed> $headers
     * @return array<string, mixed>
     */
    public static function toArray(Decision $decision, array $headers): array
    {
        if ($decision->outcome === Outcome::SKIP) {
            return $headers;
        }
        foreach ($decision->removeHeaders as $name) {
            $headers = self::unsetKey($headers, $name);
        }
        foreach ($decision->headers as $name => $value) {
            $headers = self::unsetKey($headers, $name);
            $headers[$name] = $value;
        }
        return $headers;
    }

    /**
     * Apply a decision to the PHP header queue.
     *
     * @param string|null $ownCacheControl The Cache-Control this plugin queued earlier. When the
     *        new decision has no Cache-Control (RESPECT), only that value is removed, so the
     *        other component's header stays.
     */
    public static function toQueue(Decision $decision, HeaderQueue $queue, ?string $ownCacheControl = null): void
    {
        if ($decision->outcome === Outcome::SKIP) {
            return;
        }
        foreach ($decision->removeHeaders as $name) {
            $queue->remove($name);
        }
        if ($decision->header('Cache-Control') === null && $ownCacheControl !== null) {
            $keep = array_values(array_filter(
                CacheControl::values($queue->lines(), 'Cache-Control'),
                static fn (string $v): bool => $v !== $ownCacheControl,
            ));
            $queue->remove('Cache-Control');
            foreach ($keep as $value) {
                $queue->add('Cache-Control', $value);
            }
        }
        foreach ($decision->headers as $name => $value) {
            $queue->set($name, $value);
        }
    }

    /**
     * @param array<string, mixed> $headers
     * @return array<string, mixed>
     */
    private static function unsetKey(array $headers, string $name): array
    {
        foreach (array_keys($headers) as $key) {
            if (strcasecmp((string) $key, $name) === 0) {
                unset($headers[$key]);
            }
        }
        return $headers;
    }
}
