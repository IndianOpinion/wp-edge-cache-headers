<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders\WordPress;

use IndianOpinion\EdgeCacheHeaders\CacheControl;
use IndianOpinion\EdgeCacheHeaders\Policy;
use IndianOpinion\EdgeCacheHeaders\Profile;

/**
 * Stored settings: one autoloaded option per site and one network option.
 *
 * Resolution order, later wins: built-in defaults, network defaults, the
 * site's own settings, constants. Filters are applied afterwards when the
 * Policy is built.
 */
final class Settings
{
    public const OPTION = 'edge_cache_headers_settings';
    public const NETWORK_OPTION = 'edge_cache_headers_network';

    public const CONST_DISABLED = 'EDGE_CACHE_HEADERS_DISABLED';
    public const CONST_PROFILE = 'EDGE_CACHE_HEADERS_PROFILE';
    public const CONST_POSTS_PER_HOUR = 'EDGE_CACHE_HEADERS_POSTS_PER_HOUR';
    public const CONST_DEBUG = 'EDGE_CACHE_HEADERS_DEBUG';
    public const CONST_DEBUG_TOKEN = 'EDGE_CACHE_HEADERS_DEBUG_TOKEN';
    public const CONST_STRICT = 'EDGE_CACHE_HEADERS_STRICT';

    /** Keys a network or site option may hold. */
    public const DEFAULTS = [
        'profile' => '',
        'posts_per_hour' => null,
        'ttls' => [],
        'post_type_ttls' => [],
        'browser_max_age' => 0,
        'stale_while_revalidate' => null,
        'stale_if_error' => Policy::DEFAULT_STALE_IF_ERROR,
        'send_cdn_cache_control' => false,
        'send_surrogate_control' => false,
        'send_x_accel_expires' => true,
        'send_custom_header' => true,
        'custom_header_name' => Policy::DEFAULT_CUSTOM_HEADER,
        'extra_cookie_prefixes' => [],
        'extra_paths' => [],
        'extra_query_args' => [],
        'respect_upstream_public' => false,
        'vary_is_honoured' => false,
        'strict_mode' => false,
        'debug' => false,
        'debug_token_hash' => '',
        'live_token_hash' => '',
        'live_token_expires' => 0,
    ];

    /** Keys that live only in the site option (never inherited from the network). */
    private const SITE_ONLY = ['debug_token_hash', 'live_token_hash', 'live_token_expires'];

    /** @var array<string, mixed> */
    public readonly array $values;
    /** @var array<string, string> key => default|network|site|constant */
    public readonly array $sources;
    /** @var list<string> */
    public readonly array $notices;

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $sources
     * @param list<string> $notices
     */
    public function __construct(
        array $values,
        array $sources,
        public readonly bool $corrupt = false,
        public readonly bool $enabled = true,
        public readonly ?string $constantDebugToken = null,
        array $notices = [],
    ) {
        $this->values = $values;
        $this->sources = $sources;
        $this->notices = array_values($notices);
    }

    public static function load(): self
    {
        $notices = [];
        $corrupt = false;
        $values = self::DEFAULTS;
        $sources = array_fill_keys(array_keys(self::DEFAULTS), 'default');

        if (function_exists('is_multisite') && is_multisite()) {
            $network = get_site_option(self::NETWORK_OPTION, []);
            if (is_array($network)) {
                foreach (self::sanitize($network, $notices) as $key => $value) {
                    if (in_array($key, self::SITE_ONLY, true)) {
                        continue;
                    }
                    $values[$key] = $value;
                    $sources[$key] = 'network';
                }
            } elseif ($network !== false) {
                $corrupt = true;
            }
        }

        $site = get_option(self::OPTION, []);
        if (is_array($site)) {
            foreach (self::sanitize($site, $notices) as $key => $value) {
                $values[$key] = $value;
                $sources[$key] = 'site';
            }
        } elseif ($site !== false) {
            $corrupt = true;
        }

        $enabled = true;
        if (defined(self::CONST_DISABLED) && constant(self::CONST_DISABLED)) {
            $enabled = false;
        }
        if (defined(self::CONST_PROFILE)) {
            $profile = constant(self::CONST_PROFILE);
            if (is_string($profile) && Profile::isValid($profile)) {
                $values['profile'] = $profile;
                $sources['profile'] = 'constant';
            } else {
                $notices[] = self::CONST_PROFILE . ': invalid value ignored';
            }
        }
        if (defined(self::CONST_POSTS_PER_HOUR)) {
            $rate = constant(self::CONST_POSTS_PER_HOUR);
            if (is_numeric($rate) && (float) $rate >= 0) {
                $values['posts_per_hour'] = (float) $rate;
                $sources['posts_per_hour'] = 'constant';
            } else {
                $notices[] = self::CONST_POSTS_PER_HOUR . ': invalid value ignored';
            }
        }
        if (defined(self::CONST_DEBUG)) {
            $values['debug'] = (bool) constant(self::CONST_DEBUG);
            $sources['debug'] = 'constant';
        }
        if (defined(self::CONST_STRICT)) {
            $values['strict_mode'] = (bool) constant(self::CONST_STRICT);
            $sources['strict_mode'] = 'constant';
        }
        $token = null;
        if (defined(self::CONST_DEBUG_TOKEN)) {
            $raw = constant(self::CONST_DEBUG_TOKEN);
            if (is_string($raw) && strlen($raw) >= 32) {
                $token = $raw;
                $sources['debug_token_hash'] = 'constant';
            } else {
                $notices[] = self::CONST_DEBUG_TOKEN . ': must be a string of at least 32 characters';
            }
        }

        return new self($values, $sources, $corrupt, $enabled, $token, $notices);
    }

    /**
     * Built-in defaults overlaid with the network defaults: what a site gets
     * for every key it does not set itself.
     *
     * @return array<string, mixed>
     */
    public static function inherited(): array
    {
        $values = self::DEFAULTS;
        if (function_exists('is_multisite') && is_multisite()) {
            $network = get_site_option(self::NETWORK_OPTION, []);
            if (is_array($network)) {
                foreach (self::sanitize($network) as $key => $value) {
                    if (!in_array($key, self::SITE_ONLY, true)) {
                        $values[$key] = $value;
                    }
                }
            }
        }
        return $values;
    }

    /**
     * Is a setting locked by a constant?
     */
    public function locked(string $key): bool
    {
        return ($this->sources[$key] ?? '') === 'constant';
    }

    public function profile(): string
    {
        $profile = $this->values['profile'];
        if (is_string($profile) && Profile::isValid($profile)) {
            return $profile;
        }
        $rate = $this->values['posts_per_hour'];
        return Profile::fromPostsPerHour(is_float($rate) || is_int($rate) ? (float) $rate : null);
    }

    /**
     * Keep only known keys with valid values. Invalid values are dropped with a notice.
     *
     * @param array<mixed> $raw
     * @param list<string> $notices
     * @return array<string, mixed>
     */
    public static function sanitize(array $raw, array &$notices = []): array
    {
        $out = [];
        foreach ($raw as $key => $value) {
            if (!is_string($key) || !array_key_exists($key, self::DEFAULTS)) {
                continue;
            }
            $clean = self::sanitizeValue($key, $value);
            if ($clean === self::INVALID) {
                $notices[] = 'setting ' . $key . ': invalid value ignored';
                continue;
            }
            $out[$key] = $clean;
        }
        return $out;
    }

    private const INVALID = "\0invalid";

    private static function sanitizeValue(string $key, mixed $value): mixed
    {
        switch ($key) {
            case 'profile':
                return is_string($value) && ($value === '' || Profile::isValid($value)) ? $value : self::INVALID;
            case 'posts_per_hour':
                if ($value === null || $value === '') {
                    return null;
                }
                return is_numeric($value) && (float) $value >= 0 && (float) $value < 100000 ? (float) $value : self::INVALID;
            case 'ttls':
            case 'post_type_ttls':
                if (!is_array($value)) {
                    return self::INVALID;
                }
                $map = [];
                foreach ($value as $type => $ttl) {
                    if (!is_string($type) || preg_match('/^[a-z0-9_\-]{1,40}$/', $type) !== 1) {
                        continue;
                    }
                    $int = self::nonNegativeInt($ttl);
                    if ($int !== null) {
                        $map[$type] = min($int, Policy::ONE_YEAR);
                    }
                }
                return $map;
            case 'stale_while_revalidate':
                if ($value === null || $value === '') {
                    return null;
                }
                $int = self::nonNegativeInt($value);
                return $int === null ? self::INVALID : min($int, Policy::ONE_WEEK);
            case 'browser_max_age':
            case 'stale_if_error':
            case 'live_token_expires':
                $int = self::nonNegativeInt($value);
                return $int === null ? self::INVALID : $int;
            case 'send_cdn_cache_control':
            case 'send_surrogate_control':
            case 'send_x_accel_expires':
            case 'send_custom_header':
            case 'respect_upstream_public':
            case 'vary_is_honoured':
            case 'strict_mode':
            case 'debug':
                return is_bool($value) ? $value : (in_array($value, [0, 1, '0', '1'], true) ? (bool) $value : self::INVALID);
            case 'custom_header_name':
                return is_string($value) && CacheControl::isHeaderName($value) ? $value : self::INVALID;
            case 'extra_cookie_prefixes':
            case 'extra_query_args':
                return self::stringList($value, '/^[A-Za-z0-9_\-\.\[\]]{1,64}$/');
            case 'extra_paths':
                return self::stringList($value, '#^/[A-Za-z0-9_\-\./~%]{0,127}$#');
            case 'debug_token_hash':
            case 'live_token_hash':
                return is_string($value) && ($value === '' || preg_match('/^[a-f0-9]{64}$/', $value) === 1) ? $value : self::INVALID;
        }
        return self::INVALID;
    }

    private static function nonNegativeInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (is_string($value) && $value !== '' && ctype_digit($value) && strlen($value) < 12) {
            return (int) $value;
        }
        return null;
    }

    /**
     * @return list<string>|string
     */
    private static function stringList(mixed $value, string $pattern): array|string
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($value)) {
            return self::INVALID;
        }
        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && preg_match($pattern, trim($item)) === 1) {
                $out[] = trim($item);
            }
        }
        return array_values(array_unique($out));
    }
}
