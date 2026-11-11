<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders;

/**
 * Per-request debug token: generation, storage form and constant-time checks.
 *
 * Settings store only a SHA-256 hash, so a token cannot be shown again after
 * rotation. A token set by constant is compared directly.
 */
final class DebugToken
{
    public const BYTES = 32;

    /**
     * A new random token as 64 hex characters.
     */
    public static function generate(): string
    {
        return bin2hex(random_bytes(self::BYTES));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Does a presented token match a stored hash? Constant time.
     */
    public static function matchesHash(?string $presented, ?string $storedHash): bool
    {
        if ($presented === null || $presented === '' || $storedHash === null || strlen($storedHash) !== 64) {
            return false;
        }
        return hash_equals($storedHash, self::hash($presented));
    }

    /**
     * Does a presented token match a plain token (from a constant)? Constant time.
     */
    public static function matchesPlain(?string $presented, ?string $plain): bool
    {
        if ($presented === null || $presented === '' || $plain === null || strlen($plain) < self::BYTES) {
            return false;
        }
        return hash_equals(self::hash($plain), self::hash($presented));
    }
}
