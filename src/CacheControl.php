<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders;

/**
 * Small helpers for reading header lines and Cache-Control values.
 */
final class CacheControl
{
    /**
     * Does a Cache-Control value forbid shared caching?
     *
     * True for no-store, no-cache, private, or max-age=0 without s-maxage.
     */
    public static function isNoCache(string $value): bool
    {
        $directives = self::parse($value);
        if (isset($directives['no-store']) || isset($directives['no-cache']) || isset($directives['private'])) {
            return true;
        }
        if (isset($directives['s-maxage'])) {
            return $directives['s-maxage'] === '0';
        }
        return isset($directives['max-age']) && $directives['max-age'] === '0';
    }

    /**
     * @return array<string, string> Lower-case directive to value ('' when it has none).
     */
    public static function parse(string $value): array
    {
        $out = [];
        foreach (explode(',', $value) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $pieces = explode('=', $part, 2);
            $name = strtolower(trim($pieces[0]));
            $out[$name] = isset($pieces[1]) ? trim($pieces[1], " \t\"") : '';
        }
        return $out;
    }

    /**
     * Split raw header lines ("Name: value") into name and value pairs.
     *
     * @param list<string> $lines
     * @return list<array{0: string, 1: string}>
     */
    public static function splitLines(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $out[] = [trim(substr($line, 0, $pos)), trim(substr($line, $pos + 1))];
        }
        return $out;
    }

    /**
     * Values of every header with this name, case-insensitive.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    public static function values(array $lines, string $name): array
    {
        $out = [];
        foreach (self::splitLines($lines) as [$key, $value]) {
            if (strcasecmp($key, $name) === 0) {
                $out[] = $value;
            }
        }
        return $out;
    }

    /**
     * Cookie names from Set-Cookie values. Values are discarded.
     *
     * @param list<string> $setCookieValues
     * @return list<string>
     */
    public static function cookieNames(array $setCookieValues): array
    {
        $names = [];
        foreach ($setCookieValues as $value) {
            $pos = strpos($value, '=');
            $name = trim($pos === false ? $value : substr($value, 0, $pos));
            if ($name !== '') {
                $names[] = self::safeToken($name);
            }
        }
        return array_values(array_unique($names));
    }

    /**
     * Header names listed in Vary values.
     *
     * @param list<string> $varyValues
     * @return list<string>
     */
    public static function varyNames(array $varyValues): array
    {
        $names = [];
        foreach ($varyValues as $value) {
            foreach (explode(',', $value) as $name) {
                $name = trim($name);
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }
        return self::uniqueCaseInsensitive($names);
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    public static function uniqueCaseInsensitive(array $names): array
    {
        $seen = [];
        $out = [];
        foreach ($names as $name) {
            $key = strtolower($name);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $name;
            }
        }
        return $out;
    }

    /**
     * Reduce a string to characters that are safe in a header value and a log line.
     */
    public static function safeToken(string $value): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_.\-]/', '', $value);
        return substr($clean ?? '', 0, 64);
    }

    /**
     * Is this a valid HTTP header field name?
     */
    public static function isHeaderName(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9\-]{0,63}$/', $name) === 1;
    }
}
