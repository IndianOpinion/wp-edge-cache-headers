<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders\Tests\Stubs;

/**
 * State behind the WordPress function stubs. Reset before every test.
 */
final class WpStub
{
    /** @var array<string, array<int, list<callable>>> */
    public static array $filters = [];
    /** @var array<string, int> */
    public static array $didActions = [];
    /** @var array<string, bool> Conditional tag name (without is_) to result. */
    public static array $conditions = [];
    /** @var array<string, mixed> */
    public static array $queryVars = [];
    public static mixed $queriedObject = null;
    public static bool $loggedIn = false;
    public static bool $isAdmin = false;
    public static bool $doingAjax = false;
    public static bool $doingCron = false;
    public static bool $multisite = false;
    public static bool $subdomain = false;
    public static string $home = 'https://example.com/';
    public static int $currentBlog = 1;
    /** @var array<int, array<string, mixed>> Blog id to options. */
    public static array $options = [];
    /** @var array<string, mixed> */
    public static array $siteOptions = [];
    /** @var list<\WP_Site> */
    public static array $sites = [];
    /** @var array<string, bool> */
    public static array $caps = [];
    public static bool $nonceValid = true;
    public static int $dbCalls = 0;
    /** @var list<string> */
    public static array $output = [];
    public static ?int $haltCode = null;
    /** @var list<string> */
    public static array $errorLog = [];

    public static function reset(): void
    {
        self::$filters = [];
        self::$didActions = ['init' => 1];
        self::$conditions = [];
        self::$queryVars = [];
        self::$queriedObject = null;
        self::$loggedIn = false;
        self::$isAdmin = false;
        self::$doingAjax = false;
        self::$doingCron = false;
        self::$multisite = false;
        self::$subdomain = false;
        self::$home = 'https://example.com/';
        self::$currentBlog = 1;
        self::$options = [];
        self::$siteOptions = [];
        self::$sites = [];
        self::$caps = ['manage_options' => true, 'manage_network_options' => true];
        self::$nonceValid = true;
        self::$dbCalls = 0;
        self::$output = [];
        self::$haltCode = null;
        self::$errorLog = [];
        $GLOBALS['wp_query'] = new \WP_Query();
    }

    /**
     * Set conditional tags; also marks the query as 404 when given.
     *
     * @param list<string> $names
     */
    public static function conditions(array $names): void
    {
        self::$conditions = array_fill_keys($names, true);
        $query = new \WP_Query();
        $query->is404 = in_array('404', $names, true);
        $GLOBALS['wp_query'] = $query;
    }

    public static function addFilter(string $hook, callable $callback, int $priority = 10): void
    {
        self::$filters[$hook][$priority][] = $callback;
    }

    public static function applyFilters(string $hook, mixed $value, mixed ...$args): mixed
    {
        if (!isset(self::$filters[$hook])) {
            return $value;
        }
        ksort(self::$filters[$hook]);
        foreach (self::$filters[$hook] as $callbacks) {
            foreach ($callbacks as $callback) {
                $value = $callback($value, ...$args);
            }
        }
        return $value;
    }

    public static function &blogOptions(): array
    {
        if (!isset(self::$options[self::$currentBlog])) {
            self::$options[self::$currentBlog] = [];
        }
        return self::$options[self::$currentBlog];
    }
}
