<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders\WordPress\Cli;

use IndianOpinion\EdgeCacheHeaders\Config\CloudflareGenerator;
use IndianOpinion\EdgeCacheHeaders\Config\NginxGenerator;
use IndianOpinion\EdgeCacheHeaders\DebugToken;
use IndianOpinion\EdgeCacheHeaders\RateAdvisor;
use IndianOpinion\EdgeCacheHeaders\WordPress\ExplainFailed;
use IndianOpinion\EdgeCacheHeaders\WordPress\Explainer;
use IndianOpinion\EdgeCacheHeaders\WordPress\ExplainOptions;
use IndianOpinion\EdgeCacheHeaders\WordPress\Plugin;
use IndianOpinion\EdgeCacheHeaders\WordPress\Settings;

/**
 * Explain, inspect and generate edge cache configuration.
 */
final class Command
{
    public function __construct(private readonly ?Explainer $explainer = null)
    {
    }

    /**
     * Explain the cache headers a URL gets, and why.
     *
     * ## OPTIONS
     *
     * <url>
     * : Full URL on this site or network.
     *
     * [--method=<method>]
     * : Request method to simulate.
     * ---
     * default: GET
     * ---
     *
     * [--cookie=<name>]
     * : Cookie name the visitor sends. Separate several with commas. Values are never needed.
     *
     * [--logged-in]
     * : Simulate a logged-in visitor.
     *
     * [--header=<name:value>]
     * : Request header to simulate, for example "Authorization: Bearer x".
     *
     * [--live]
     * : Also send a real HEAD request with a one-time debug token and compare.
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     *
     * ## EXIT STATUS
     *
     * 0 when the decision was shown (and, with --live, matched), 1 on a live
     * mismatch, 2 on an error such as an invalid URL.
     *
     * ## EXAMPLES
     *
     *     wp edge-cache explain https://example.com/hello-world/
     *     wp edge-cache explain https://example.com/ --cookie=wordpress_logged_in_x
     *     wp edge-cache explain https://example.com/ --live
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function explain(array $args, array $assoc): void
    {
        try {
            $options = new ExplainOptions(
                method: is_string($assoc['method'] ?? null) ? $assoc['method'] : 'GET',
                cookies: self::names($assoc['cookie'] ?? []),
                loggedIn: (bool) ($assoc['logged-in'] ?? false),
                headers: ExplainOptions::parseHeaders(self::repeatable($assoc['header'] ?? [])),
                live: (bool) ($assoc['live'] ?? false),
            );
            $explainer = $this->explainer ?? new Explainer(Plugin::instance());
            $result = $explainer->explain((string) ($args[0] ?? ''), $options);
        } catch (ExplainFailed $e) {
            \WP_CLI::line('Error: ' . $e->getMessage());
            \WP_CLI::halt(2);
        }

        if (($assoc['format'] ?? 'table') === 'json') {
            \WP_CLI::line((string) json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            \WP_CLI::halt($result->exitCode());
        }

        $d = $result->decision;
        $rows = [
            ['field' => 'url', 'value' => $result->url],
            ['field' => 'mode', 'value' => $result->mode],
            ['field' => 'outcome', 'value' => $d->outcome->value],
            ['field' => 'type', 'value' => $d->type],
            ['field' => 'ttl', 'value' => (string) $d->ttl],
            ['field' => 'stale-while-revalidate', 'value' => (string) $d->staleWhileRevalidate],
            ['field' => 'stale-if-error', 'value' => (string) $d->staleIfError],
            ['field' => 'status', 'value' => (string) $d->status],
            ['field' => 'reasons', 'value' => $d->reasons === [] ? 'none' : implode(', ', $d->reasons)],
        ];
        \WP_CLI\Utils\format_items('table', $rows, ['field', 'value']);
        \WP_CLI::line('');
        \WP_CLI::line('Headers:');
        foreach (Explainer::headerLines($d) as $line) {
            \WP_CLI::line('  ' . $line);
        }
        foreach ($result->notes as $note) {
            \WP_CLI::line('Note: ' . $note);
        }
        if ($result->comparison !== null) {
            foreach ($result->comparison->observed as $name => $value) {
                \WP_CLI::line(sprintf('Live %s: %s', $name, $value));
            }
            if ($result->comparison->matches) {
                \WP_CLI::success('Live response matches the simulation.');
            } else {
                \WP_CLI::warning('Live response differs from the simulation:');
                foreach ($result->comparison->differences as $line) {
                    \WP_CLI::line('  - ' . $line);
                }
            }
        }
        \WP_CLI::halt($result->exitCode());
    }

    /**
     * Print the effective settings with the source of each value, and the TTL table.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function config(array $args, array $assoc): void
    {
        $plugin = Plugin::instance();
        $settings = $plugin->settings();
        $policy = $plugin->policy();

        \WP_CLI::line('Option names: ' . Settings::OPTION . ' (per site), ' . Settings::NETWORK_OPTION . ' (network).');
        \WP_CLI::line('Enabled: ' . ($policy->enabled ? 'yes' : 'no'));
        \WP_CLI::line('Profile: ' . $settings->profile());
        if ($settings->corrupt) {
            \WP_CLI::warning('The stored settings are not an array; built-in defaults are in use.');
        }

        $rows = [];
        foreach ($settings->values as $key => $value) {
            if (in_array($key, ['debug_token_hash', 'live_token_hash'], true)) {
                $value = $value === '' ? '(none)' : '(set)';
            }
            $rows[] = [
                'setting' => $key,
                'value' => is_scalar($value) || $value === null ? var_export($value, true) : (string) json_encode($value),
                'source' => $settings->sources[$key] ?? 'default',
            ];
        }
        \WP_CLI\Utils\format_items('table', $rows, ['setting', 'value', 'source']);

        $ttlRows = [];
        foreach ($policy->ttls as $type => $ttl) {
            $ttlRows[] = ['type' => $type, 'ttl' => $ttl];
        }
        \WP_CLI\Utils\format_items('table', $ttlRows, ['type', 'ttl']);

        foreach ($settings->notices as $notice) {
            \WP_CLI::warning($notice);
        }
        foreach (self::warnings($policy->sendXAccelExpires) as $warning) {
            \WP_CLI::warning($warning);
        }
    }

    /**
     * Print the nginx maps and location fragment for the current settings.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function nginx(array $args, array $assoc): void
    {
        $base = rtrim((string) parse_url(home_url('/'), PHP_URL_PATH), '/');
        $subdirectory = is_multisite() && !is_subdomain_install();
        \WP_CLI::line((new NginxGenerator())->generate(Plugin::instance()->policy(), $base, $subdirectory, Plugin::VERSION));
    }

    /**
     * Print the Cloudflare cache rule expression and settings.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function cloudflare(array $args, array $assoc): void
    {
        $hosts = [strtolower((string) parse_url(home_url('/'), PHP_URL_HOST))];
        if (is_multisite()) {
            $domains = get_sites(['fields' => 'ids', 'number' => 0]);
            foreach ($domains as $id) {
                $site = get_site($id);
                if ($site instanceof \WP_Site) {
                    $hosts[] = strtolower($site->domain);
                }
            }
        }
        $hosts = array_values(array_unique($hosts));
        \WP_CLI::line((new CloudflareGenerator())->generate(Plugin::instance()->policy(), $hosts, Plugin::VERSION));
    }

    /**
     * Rotate the debug token. The new token is shown once and stored only as a hash.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function token(array $args, array $assoc): void
    {
        if (defined(Settings::CONST_DEBUG_TOKEN)) {
            \WP_CLI::error('The debug token is set by the ' . Settings::CONST_DEBUG_TOKEN . ' constant; change it in wp-config.php.');
            return;
        }
        $token = DebugToken::generate();
        $raw = get_option(Settings::OPTION, []);
        $raw = is_array($raw) ? $raw : [];
        $raw['debug_token_hash'] = DebugToken::hash($token);
        update_option(Settings::OPTION, $raw, true);
        Plugin::instance()->reset();
        \WP_CLI::line($token);
        \WP_CLI::success('Debug token rotated. Send it as the X-Edge-Cache-Debug-Token request header. It will not be shown again.');
    }

    /**
     * Measure the publishing rate from recent posts and suggest a posts-per-hour value.
     *
     * ## OPTIONS
     *
     * [--days=<days>]
     * : Days to look back.
     * ---
     * default: 7
     * ---
     *
     * [--post-type=<type>]
     * : Post type to count.
     * ---
     * default: post
     * ---
     *
     * [--apply]
     * : Save the suggested value as this site's posts per hour.
     *
     * @subcommand suggest-rate
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function suggestRate(array $args, array $assoc): void
    {
        $days = (int) ($assoc['days'] ?? 7);
        if ($days < 1 || $days > 365) {
            \WP_CLI::error('--days must be between 1 and 365.');
            return;
        }
        $postType = (string) ($assoc['post-type'] ?? 'post');
        if (!post_type_exists($postType)) {
            \WP_CLI::error(sprintf('Unknown post type "%s".', $postType));
            return;
        }
        $query = new \WP_Query([
            'post_type' => $postType,
            'post_status' => 'publish',
            'fields' => 'ids',
            'posts_per_page' => 1,
            'no_found_rows' => false,
            'date_query' => [['after' => gmdate('Y-m-d H:i:s', time() - $days * 86400), 'column' => 'post_date_gmt']],
        ]);
        $suggestion = RateAdvisor::suggest((int) $query->found_posts, $days * 24.0);
        \WP_CLI::line(sprintf(
            '%d published %s posts in the last %d days: %.2f posts an hour, profile "%s".',
            (int) $query->found_posts,
            $postType,
            $days,
            $suggestion['posts_per_hour'],
            $suggestion['profile'],
        ));
        if (!empty($assoc['apply'])) {
            if (defined(Settings::CONST_POSTS_PER_HOUR)) {
                \WP_CLI::error('Posts per hour is set by the ' . Settings::CONST_POSTS_PER_HOUR . ' constant.');
                return;
            }
            $raw = get_option(Settings::OPTION, []);
            $raw = is_array($raw) ? $raw : [];
            $raw['posts_per_hour'] = $suggestion['posts_per_hour'];
            update_option(Settings::OPTION, $raw, true);
            Plugin::instance()->reset();
            \WP_CLI::success('Saved.');
        }
    }

    /**
     * Configuration warnings shared with the settings page.
     *
     * @return list<string>
     */
    public static function warnings(bool $sendXAccelExpires): array
    {
        $out = [];
        if (!$sendXAccelExpires) {
            $out[] = 'X-Accel-Expires is off. If nginx has "fastcgi_ignore_headers Cache-Control", every URL '
                . 'gets the fixed fastcgi_cache_valid TTL, whatever the plugin decides.';
        }
        return $out;
    }

    /**
     * Cookie names from a comma-separated option (or a list).
     *
     * @return list<string>
     */
    private static function names(mixed $value): array
    {
        $out = [];
        foreach (self::repeatable($value) as $item) {
            foreach (explode(',', $item) as $name) {
                if (trim($name) !== '') {
                    $out[] = trim($name);
                }
            }
        }
        return $out;
    }

    /**
     * @return list<string>
     */
    private static function repeatable(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
