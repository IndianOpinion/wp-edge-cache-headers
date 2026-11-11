<?php

// phpcs:ignoreFile -- stand-ins for the WordPress functions the plugin calls.

declare(strict_types=1);

use IndianOpinion\EdgeCacheHeaders\Tests\Stubs\WpStub;

function add_filter(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
{
    WpStub::addFilter($hook, $callback, $priority);
    return true;
}

function add_action(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
{
    WpStub::addFilter($hook, $callback, $priority);
    return true;
}

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    return WpStub::applyFilters($hook, $value, ...$args);
}

function do_action(string $hook, mixed ...$args): void
{
    WpStub::$didActions[$hook] = (WpStub::$didActions[$hook] ?? 0) + 1;
    if (!isset(WpStub::$filters[$hook])) {
        return;
    }
    ksort(WpStub::$filters[$hook]);
    foreach (WpStub::$filters[$hook] as $callbacks) {
        foreach ($callbacks as $callback) {
            $callback(...$args);
        }
    }
}

function did_action(string $hook): int
{
    return WpStub::$didActions[$hook] ?? 0;
}

function is_user_logged_in(): bool { return WpStub::$loggedIn; }
function is_admin(): bool { return WpStub::$isAdmin; }
function wp_doing_ajax(): bool { return WpStub::$doingAjax; }
function wp_doing_cron(): bool { return WpStub::$doingCron; }

function is_robots(): bool { return WpStub::$conditions['robots'] ?? false; }
function is_feed(): bool { return WpStub::$conditions['feed'] ?? false; }
function is_embed(): bool { return WpStub::$conditions['embed'] ?? false; }
function is_search(): bool { return WpStub::$conditions['search'] ?? false; }
function is_front_page(): bool { return WpStub::$conditions['front_page'] ?? false; }
function is_home(): bool { return WpStub::$conditions['home'] ?? false; }
function is_singular(): bool { return WpStub::$conditions['singular'] ?? false; }
function is_single(): bool { return WpStub::$conditions['single'] ?? false; }
function is_page(): bool { return WpStub::$conditions['page'] ?? false; }
function is_attachment(): bool { return WpStub::$conditions['attachment'] ?? false; }
function is_category(): bool { return WpStub::$conditions['category'] ?? false; }
function is_tag(): bool { return WpStub::$conditions['tag'] ?? false; }
function is_tax(): bool { return WpStub::$conditions['tax'] ?? false; }
function is_author(): bool { return WpStub::$conditions['author'] ?? false; }
function is_date(): bool { return WpStub::$conditions['date'] ?? false; }
function is_post_type_archive(): bool { return WpStub::$conditions['post_type_archive'] ?? false; }
function is_404(): bool { return WpStub::$conditions['404'] ?? false; }
function is_customize_preview(): bool { return WpStub::$conditions['customize_preview'] ?? false; }

function get_queried_object(): mixed { return WpStub::$queriedObject; }
function get_query_var(string $name, mixed $default = ''): mixed { return WpStub::$queryVars[$name] ?? $default; }

function home_url(string $path = ''): string { return rtrim(WpStub::$home, '/') . '/' . ltrim($path, '/'); }
function site_url(string $path = ''): string { return home_url($path); }
function admin_url(string $path = ''): string { return home_url('wp-admin/' . $path); }
function network_admin_url(string $path = ''): string { return home_url('wp-admin/network/' . $path); }

function get_option(string $name, mixed $default = false): mixed
{
    $options = WpStub::blogOptions();
    return array_key_exists($name, $options) ? $options[$name] : $default;
}

function update_option(string $name, mixed $value, mixed $autoload = null): bool
{
    $options = &WpStub::blogOptions();
    $options[$name] = $value;
    return true;
}

function delete_option(string $name): bool
{
    $options = &WpStub::blogOptions();
    unset($options[$name]);
    return true;
}

function delete_blog_option(int $id, string $name): bool
{
    unset(WpStub::$options[$id][$name]);
    return true;
}

function get_site_option(string $name, mixed $default = false): mixed
{
    return array_key_exists($name, WpStub::$siteOptions) ? WpStub::$siteOptions[$name] : $default;
}

function update_site_option(string $name, mixed $value): bool
{
    WpStub::$siteOptions[$name] = $value;
    return true;
}

function delete_site_option(string $name): bool
{
    unset(WpStub::$siteOptions[$name]);
    return true;
}

function is_multisite(): bool { return WpStub::$multisite; }
function is_subdomain_install(): bool { return WpStub::$subdomain; }
function get_current_blog_id(): int { return WpStub::$currentBlog; }

function switch_to_blog(int $id): bool
{
    $GLOBALS['__stub_blog_stack'][] = WpStub::$currentBlog;
    WpStub::$currentBlog = $id;
    return true;
}

function restore_current_blog(): bool
{
    WpStub::$currentBlog = array_pop($GLOBALS['__stub_blog_stack']) ?? 1;
    return true;
}

/**
 * @param array<string, mixed> $args
 * @return list<mixed>
 */
function get_sites(array $args = []): array
{
    $out = [];
    foreach (WpStub::$sites as $site) {
        if (isset($args['domain']) && $site->domain !== $args['domain']) {
            continue;
        }
        $out[] = ($args['fields'] ?? '') === 'ids' ? (int) $site->blog_id : $site;
    }
    return $out;
}

function get_site(int $id): ?WP_Site
{
    foreach (WpStub::$sites as $site) {
        if ((int) $site->blog_id === $id) {
            return $site;
        }
    }
    return null;
}

function get_site_by_path(string $domain, string $path): WP_Site|false
{
    $best = false;
    foreach (WpStub::$sites as $site) {
        if ($site->domain === $domain && str_starts_with($path, $site->path)) {
            if ($best === false || strlen($site->path) > strlen($best->path)) {
                $best = $site;
            }
        }
    }
    return $best;
}

function current_user_can(string $cap): bool { return WpStub::$caps[$cap] ?? false; }
function wp_verify_nonce(string $nonce, string $action): bool { return WpStub::$nonceValid && $nonce !== ''; }
function wp_nonce_field(string $action): void { echo '<input type="hidden" name="_wpnonce" value="stub">'; }
function esc_html(string $text): string { return htmlspecialchars($text, ENT_QUOTES); }
function esc_attr(string $text): string { return htmlspecialchars($text, ENT_QUOTES); }
function esc_url(string $url): string { return htmlspecialchars($url, ENT_QUOTES); }
function esc_textarea(string $text): string { return htmlspecialchars($text, ENT_QUOTES); }
function esc_html__(string $text, string $domain = 'default'): string { return esc_html($text); }
function __(string $text, string $domain = 'default'): string { return $text; }
function wp_die(string $message = ''): void { throw new RuntimeException('wp_die: ' . $message); }
function wp_unslash(mixed $value): mixed { return $value; }
function submit_button(string $text = 'Save', string $type = 'primary'): void { echo '<button>' . esc_html($text) . '</button>'; }
function selected(mixed $a, mixed $b, bool $echo = true): string { return $a === $b ? ' selected' : ''; }
function checked(mixed $a, mixed $b, bool $echo = true): string { return $a === $b ? ' checked' : ''; }
function add_options_page(mixed ...$args): string { return 'page'; }
function add_submenu_page(mixed ...$args): string { return 'page'; }
function is_wp_error(mixed $thing): bool { return false; }
function post_type_exists(string $type): bool { return in_array($type, ['post', 'page'], true); }

function __return_false_stub(): bool { return false; }
