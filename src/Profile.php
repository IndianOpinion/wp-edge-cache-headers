<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders;

/**
 * Default TTL tables keyed by publishing rate.
 */
final class Profile
{
    public const QUIET = 'quiet';
    public const STEADY = 'steady';
    public const BUSY = 'busy';

    public const ALL = [self::QUIET, self::STEADY, self::BUSY];

    /** Every template type the classifier can return, plus the two TTL-only keys. */
    public const TYPES = [
        'robots', 'sitemap', 'feed', 'embed', 'search', 'front_page', 'home',
        'singular_post', 'page', 'attachment', 'singular_other',
        'category', 'tag', 'taxonomy', 'author', 'date', 'post_type_archive',
        'not_found', 'other', 'rest', 'redirect',
    ];

    /** @var array<string, array<string, int>> */
    private const TABLE = [
        self::QUIET => [
            'front_page' => 900, 'home' => 900,
            'singular_post' => 3600, 'embed' => 3600, 'singular_other' => 3600,
            'page' => 7200, 'attachment' => 7200,
            'category' => 1800, 'tag' => 1800, 'taxonomy' => 1800, 'post_type_archive' => 1800,
            'author' => 1800, 'date' => 1800,
            'search' => 0,
            'not_found' => 300,
            'feed' => 1800,
            'sitemap' => 1800,
            'robots' => 86400,
            'rest' => 300,
            'redirect' => 600,
            'other' => 900,
        ],
        self::STEADY => [
            'front_page' => 300, 'home' => 300,
            'singular_post' => 1800, 'embed' => 1800, 'singular_other' => 1800,
            'page' => 3600, 'attachment' => 3600,
            'category' => 600, 'tag' => 600, 'taxonomy' => 600, 'post_type_archive' => 600,
            'author' => 900, 'date' => 900,
            'search' => 0,
            'not_found' => 60,
            'feed' => 600,
            'sitemap' => 300,
            'robots' => 3600,
            'rest' => 60,
            'redirect' => 600,
            'other' => 300,
        ],
        self::BUSY => [
            'front_page' => 300, 'home' => 300,
            'singular_post' => 1800, 'embed' => 1800, 'singular_other' => 1800,
            'page' => 3600, 'attachment' => 3600,
            'category' => 600, 'tag' => 600, 'taxonomy' => 600, 'post_type_archive' => 600,
            'author' => 600, 'date' => 600,
            'search' => 0,
            'not_found' => 60,
            'feed' => 300,
            'sitemap' => 120,
            'robots' => 3600,
            'rest' => 60,
            'redirect' => 600,
            'other' => 300,
        ],
    ];

    /**
     * Pick a profile from a publishing rate in posts per hour.
     */
    public static function fromPostsPerHour(?float $postsPerHour): string
    {
        if ($postsPerHour === null || $postsPerHour < 0 || is_nan($postsPerHour)) {
            return self::STEADY;
        }
        if ($postsPerHour < 1) {
            return self::QUIET;
        }
        if ($postsPerHour < 20) {
            return self::STEADY;
        }
        return self::BUSY;
    }

    public static function isValid(string $profile): bool
    {
        return in_array($profile, self::ALL, true);
    }

    /**
     * @return array<string, int>
     */
    public static function ttls(string $profile): array
    {
        return self::TABLE[$profile] ?? self::TABLE[self::STEADY];
    }
}
