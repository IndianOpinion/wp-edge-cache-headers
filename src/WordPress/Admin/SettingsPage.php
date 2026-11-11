<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders\WordPress\Admin;

use IndianOpinion\EdgeCacheHeaders\DebugToken;
use IndianOpinion\EdgeCacheHeaders\Profile;
use IndianOpinion\EdgeCacheHeaders\WordPress\Cli\Command;
use IndianOpinion\EdgeCacheHeaders\WordPress\ExplainFailed;
use IndianOpinion\EdgeCacheHeaders\WordPress\Explainer;
use IndianOpinion\EdgeCacheHeaders\WordPress\ExplainOptions;
use IndianOpinion\EdgeCacheHeaders\WordPress\Plugin;
use IndianOpinion\EdgeCacheHeaders\WordPress\Settings;

/**
 * Settings > Edge cache headers (per site) and Network Settings > Edge cache headers.
 *
 * Forms post back to the page itself, so a rotated token can be shown once
 * without putting it in a URL. Every form has a nonce and a capability check.
 */
final class SettingsPage
{
    public const SLUG = 'edge-cache-headers';
    public const NETWORK_SLUG = 'edge-cache-headers-network';
    public const NONCE_SAVE = 'edge_cache_headers_save';
    public const NONCE_EXPLAIN = 'edge_cache_headers_explain';

    /** Form fields that map one to one onto settings keys. */
    private const BOOL_FIELDS = [
        'send_x_accel_expires' => 'Send X-Accel-Expires (nginx per-URL TTL)',
        'send_custom_header' => 'Send the bypass signal header',
        'send_cdn_cache_control' => 'Send CDN-Cache-Control',
        'send_surrogate_control' => 'Send Surrogate-Control (Fastly, Varnish)',
        'respect_upstream_public' => 'Respect a cacheable Cache-Control set by another plugin',
        'vary_is_honoured' => 'My caches honour Vary on HTML',
        'strict_mode' => 'Strict mode: buffer output to catch late cookies and status changes',
        'debug' => 'Debug header on every response',
    ];

    private ?Explainer $explainer;

    public function __construct(?Explainer $explainer = null)
    {
        $this->explainer = $explainer;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addPage']);
        add_action('network_admin_menu', [$this, 'addNetworkPage']);
        add_action('admin_notices', [$this, 'corruptNotice']);
    }

    public function addPage(): void
    {
        add_options_page('Edge cache headers', 'Edge cache headers', 'manage_options', self::SLUG, [$this, 'render']);
    }

    public function addNetworkPage(): void
    {
        add_submenu_page(
            'settings.php',
            'Edge cache headers',
            'Edge cache headers',
            'manage_network_options',
            self::NETWORK_SLUG,
            [$this, 'renderNetwork'],
        );
    }

    public function corruptNotice(): void
    {
        if (!current_user_can('manage_options') || !Plugin::instance()->settings()->corrupt) {
            return;
        }
        echo '<div class="notice notice-warning"><p>'
            . esc_html__('Edge cache headers: the stored settings are corrupt. Built-in defaults are in use until you save the settings page.', 'wp-edge-cache-headers')
            . '</p></div>';
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage these settings.', 'wp-edge-cache-headers'));
        }
        $message = '';
        $explainHtml = '';
        // Nonces are verified inside each handler.
        $post = self::postData();
        if (isset($post['edge_cache_headers_action'])) {
            if ($post['edge_cache_headers_action'] === 'save') {
                $message = $this->handleSave($post, false);
            } elseif ($post['edge_cache_headers_action'] === 'explain') {
                $explainHtml = $this->explainResponse($post);
            }
        }
        $this->page(false, $message, $explainHtml, $post);
    }

    public function renderNetwork(): void
    {
        if (!current_user_can('manage_network_options')) {
            wp_die(esc_html__('You do not have permission to manage these settings.', 'wp-edge-cache-headers'));
        }
        $message = '';
        $post = self::postData();
        if (($post['edge_cache_headers_action'] ?? '') === 'save') {
            $message = $this->handleSave($post, true);
        }
        $this->page(true, $message, '', []);
    }

    /**
     * Unslashed POST data.
     *
     * @return array<mixed>
     */
    private static function postData(): array
    {
        $post = wp_unslash($_POST);
        return is_array($post) ? $post : [];
    }

    /**
     * Save the form. Returns an HTML message.
     *
     * @param array<mixed> $post Unslashed POST data.
     */
    public function handleSave(array $post, bool $network): string
    {
        $capability = $network ? 'manage_network_options' : 'manage_options';
        if (!current_user_can($capability)) {
            return $this->error(__('You do not have permission to change these settings.', 'wp-edge-cache-headers'));
        }
        $nonce = isset($post['_wpnonce']) && is_string($post['_wpnonce']) ? $post['_wpnonce'] : '';
        if (!wp_verify_nonce($nonce, self::NONCE_SAVE)) {
            return $this->error(__('The form expired. Reload the page and try again.', 'wp-edge-cache-headers'));
        }

        $settings = Plugin::instance()->settings();
        $notices = [];
        $clean = Settings::sanitize(self::formToRaw($post, $settings, $network), $notices);

        $message = __('Settings saved.', 'wp-edge-cache-headers');
        $tokenHtml = '';
        if ($network) {
            update_site_option(Settings::NETWORK_OPTION, $clean);
        } else {
            // Store only what differs from the network defaults, so a later change to
            // a network default still reaches this site.
            $inherited = Settings::inherited();
            foreach ($clean as $key => $value) {
                if (array_key_exists($key, $inherited) && $inherited[$key] === $value) {
                    unset($clean[$key]);
                }
            }
            $existing = get_option(Settings::OPTION, []);
            $existing = is_array($existing) ? $existing : [];
            foreach (['debug_token_hash', 'live_token_hash', 'live_token_expires'] as $key) {
                if (isset($existing[$key])) {
                    $clean[$key] = $existing[$key];
                }
            }
            if (!empty($post['rotate_token']) && !$settings->locked('debug_token_hash')) {
                $token = DebugToken::generate();
                $clean['debug_token_hash'] = DebugToken::hash($token);
                $tokenHtml = '<p>' . esc_html__('New debug token (shown once, store it now):', 'wp-edge-cache-headers')
                    . ' <code>' . esc_html($token) . '</code></p>';
            }
            update_option(Settings::OPTION, $clean, true);
        }
        Plugin::instance()->reset();

        $html = '<div class="notice notice-success"><p>' . esc_html($message) . '</p>' . $tokenHtml . '</div>';
        foreach ($notices as $notice) {
            $html .= $this->error($notice);
        }
        return $html;
    }

    /**
     * Run the explain tool for the admin page. Returns escaped HTML.
     *
     * @param array<mixed> $post Unslashed POST data.
     */
    public function explainResponse(array $post): string
    {
        if (!current_user_can('manage_options')) {
            return $this->error(__('You do not have permission to use the explain tool.', 'wp-edge-cache-headers'));
        }
        $nonce = isset($post['_wpnonce']) && is_string($post['_wpnonce']) ? $post['_wpnonce'] : '';
        if (!wp_verify_nonce($nonce, self::NONCE_EXPLAIN)) {
            return $this->error(__('The form expired. Reload the page and try again.', 'wp-edge-cache-headers'));
        }
        $url = isset($post['explain_url']) && is_string($post['explain_url']) ? trim($post['explain_url']) : '';
        try {
            $headers = [];
            if (isset($post['explain_header']) && is_string($post['explain_header']) && trim($post['explain_header']) !== '') {
                $headers = ExplainOptions::parseHeaders([trim($post['explain_header'])]);
            }
            $cookies = [];
            if (isset($post['explain_cookies']) && is_string($post['explain_cookies'])) {
                $cookies = preg_split('/[\s,]+/', $post['explain_cookies'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
            }
            $options = new ExplainOptions(
                method: isset($post['explain_method']) && is_string($post['explain_method']) && $post['explain_method'] !== '' ? $post['explain_method'] : 'GET',
                cookies: $cookies,
                loggedIn: !empty($post['explain_logged_in']),
                headers: $headers,
                live: !empty($post['explain_live']),
            );
            $explainer = $this->explainer ?? new Explainer(Plugin::instance());
            $result = $explainer->explain($url, $options);
        } catch (ExplainFailed $e) {
            return $this->error($e->getMessage());
        }

        $d = $result->decision;
        $rows = [
            'Mode' => $result->mode,
            'Outcome' => $d->outcome->value,
            'Type' => $d->type,
            'TTL' => (string) $d->ttl,
            'Stale while revalidate' => (string) $d->staleWhileRevalidate,
            'Stale if error' => (string) $d->staleIfError,
            'Status' => (string) $d->status,
            'Reasons' => $d->reasons === [] ? 'none' : implode(', ', $d->reasons),
        ];
        $html = '<h3>' . esc_html__('Result for', 'wp-edge-cache-headers') . ' <code>' . esc_html($result->url) . '</code></h3>';
        $html .= '<table class="widefat striped"><tbody>';
        foreach ($rows as $label => $value) {
            $html .= '<tr><th scope="row">' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
        }
        $html .= '</tbody></table><h4>' . esc_html__('Headers', 'wp-edge-cache-headers') . '</h4><pre>';
        $html .= esc_html(implode("\n", Explainer::headerLines($d))) . '</pre>';
        foreach ($result->notes as $note) {
            $html .= '<p>' . esc_html($note) . '</p>';
        }
        if ($result->comparison !== null) {
            $html .= '<h4>' . esc_html__('Live comparison', 'wp-edge-cache-headers') . '</h4>';
            $html .= '<p>' . esc_html($result->comparison->matches ? 'Match.' : 'Mismatch.') . '</p><ul>';
            foreach ($result->comparison->differences as $line) {
                $html .= '<li>' . esc_html($line) . '</li>';
            }
            foreach ($result->comparison->observed as $name => $value) {
                $html .= '<li>' . esc_html($name . ': ' . $value) . '</li>';
            }
            $html .= '</ul>';
        }
        return $html;
    }

    /**
     * Turn posted form fields into a raw settings array.
     *
     * @param array<mixed> $post
     * @return array<string, mixed>
     */
    public static function formToRaw(array $post, Settings $settings, bool $network): array
    {
        $raw = [];
        $str = static fn (string $key): string => isset($post[$key]) && is_string($post[$key]) ? trim($post[$key]) : '';

        if (!$settings->locked('profile')) {
            $raw['profile'] = $str('profile');
        }
        if (!$settings->locked('posts_per_hour')) {
            $raw['posts_per_hour'] = $str('posts_per_hour');
        }
        $ttls = [];
        if (isset($post['ttls']) && is_array($post['ttls'])) {
            foreach ($post['ttls'] as $type => $value) {
                if (is_string($type) && is_string($value) && trim($value) !== '') {
                    $ttls[$type] = trim($value);
                }
            }
        }
        $raw['ttls'] = $ttls;
        foreach (['browser_max_age', 'stale_if_error'] as $key) {
            if ($str($key) !== '') {
                $raw[$key] = $str($key);
            }
        }
        $raw['stale_while_revalidate'] = $str('stale_while_revalidate');
        foreach (array_keys(self::BOOL_FIELDS) as $key) {
            if ($settings->locked($key)) {
                continue;
            }
            $raw[$key] = !empty($post[$key]);
        }
        if ($str('custom_header_name') !== '') {
            $raw['custom_header_name'] = $str('custom_header_name');
        }
        foreach (['extra_cookie_prefixes', 'extra_paths', 'extra_query_args'] as $key) {
            $raw[$key] = $str($key);
        }
        return $raw;
    }

    /**
     * @param array<mixed> $post
     */
    private function page(bool $network, string $message, string $explainHtml, array $post): void
    {
        $plugin = Plugin::instance();
        $settings = $plugin->settings();
        $policy = $plugin->policy();
        // The network page edits the network defaults, not the current site's own values.
        $v = $network ? Settings::inherited() : $settings->values;
        $slug = $network ? self::NETWORK_SLUG : self::SLUG;
        $action = $network ? network_admin_url('settings.php?page=' . $slug) : admin_url('options-general.php?page=' . $slug);

        echo '<div class="wrap"><h1>' . esc_html__('Edge cache headers', 'wp-edge-cache-headers') . '</h1>';
        echo $message; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.

        if ($network) {
            echo '<p>' . esc_html__('Network defaults. Each site can override them on its own settings page.', 'wp-edge-cache-headers') . '</p>';
        }
        if ($settings->corrupt) {
            echo $this->error(__('The stored settings are corrupt; built-in defaults are in use.', 'wp-edge-cache-headers')); // phpcs:ignore WordPress.Security.EscapeOutput
        }
        foreach (Command::warnings($policy->sendXAccelExpires) as $warning) {
            echo $this->error($warning); // phpcs:ignore WordPress.Security.EscapeOutput
        }

        echo '<form method="post" action="' . esc_url($action) . '">';
        wp_nonce_field(self::NONCE_SAVE);
        echo '<input type="hidden" name="edge_cache_headers_action" value="save">';
        echo '<table class="form-table" role="presentation"><tbody>';

        $locked = static fn (string $key): string => $settings->locked($key) ? ' disabled' : '';
        $lockNote = static fn (string $key): string => $settings->locked($key)
            ? ' <em>' . esc_html__('(set by a constant in wp-config.php)', 'wp-edge-cache-headers') . '</em>'
            : '';

        echo '<tr><th scope="row"><label for="ech-profile">' . esc_html__('Profile', 'wp-edge-cache-headers') . '</label></th><td>';
        echo '<select id="ech-profile" name="profile"' . $locked('profile') . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
        echo '<option value="">' . esc_html__('From posts per hour', 'wp-edge-cache-headers') . '</option>';
        foreach (Profile::ALL as $profile) {
            echo '<option value="' . esc_attr($profile) . '"' . selected($v['profile'], $profile, false) . '>' . esc_html($profile) . '</option>';
        }
        echo '</select>' . $lockNote('profile') . ' '; // phpcs:ignore WordPress.Security.EscapeOutput
        echo esc_html(sprintf('Active: %s.', $settings->profile())) . '</td></tr>';

        echo '<tr><th scope="row"><label for="ech-pph">' . esc_html__('Posts per hour', 'wp-edge-cache-headers') . '</label></th><td>';
        echo '<input id="ech-pph" type="number" step="0.01" min="0" name="posts_per_hour" value="' . esc_attr((string) $v['posts_per_hour']) . '"' . $locked('posts_per_hour') . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
        echo $lockNote('posts_per_hour'); // phpcs:ignore WordPress.Security.EscapeOutput
        echo '<p class="description">' . esc_html__('Under 1 is quiet, 1 to 19 steady, 20 or more busy. "wp edge-cache suggest-rate" measures it.', 'wp-edge-cache-headers') . '</p></td></tr>';

        echo '<tr><th scope="row">' . esc_html__('TTL per type (seconds)', 'wp-edge-cache-headers') . '</th><td>';
        echo '<p class="description">' . esc_html__('Leave blank for the profile default. 0 means never cache. The robots TTL applies only when WordPress serves robots.txt; a physical file is served by the web server.', 'wp-edge-cache-headers') . '</p>';
        $overrides = is_array($v['ttls']) ? $v['ttls'] : [];
        foreach (Profile::TYPES as $type) {
            echo '<label style="display:inline-block;min-width:16em">' . esc_html($type) . ' <input type="number" min="0" name="ttls[' . esc_attr($type) . ']" value="'
                . esc_attr(isset($overrides[$type]) ? (string) $overrides[$type] : '') . '" placeholder="' . esc_attr((string) ($policy->ttls[$type] ?? '')) . '" style="width:7em"></label> ';
        }
        echo '</td></tr>';

        $numbers = [
            'browser_max_age' => __('Browser max-age', 'wp-edge-cache-headers'),
            'stale_while_revalidate' => __('Stale while revalidate (blank: 5 x TTL)', 'wp-edge-cache-headers'),
            'stale_if_error' => __('Stale if error', 'wp-edge-cache-headers'),
        ];
        foreach ($numbers as $key => $label) {
            echo '<tr><th scope="row"><label for="ech-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>';
            echo '<input id="ech-' . esc_attr($key) . '" type="number" min="0" name="' . esc_attr($key) . '" value="' . esc_attr((string) $v[$key]) . '"></td></tr>';
        }

        foreach (self::BOOL_FIELDS as $key => $label) {
            echo '<tr><th scope="row">' . esc_html($label) . '</th><td><label><input type="checkbox" name="' . esc_attr($key) . '" value="1"'
                . checked((bool) $v[$key], true, false) . $locked($key) . '> ' . esc_html__('On', 'wp-edge-cache-headers') . '</label>' . $lockNote($key) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
        }

        echo '<tr><th scope="row"><label for="ech-header">' . esc_html__('Bypass signal header name', 'wp-edge-cache-headers') . '</label></th><td>';
        echo '<input id="ech-header" type="text" name="custom_header_name" value="' . esc_attr((string) $v['custom_header_name']) . '"></td></tr>';

        $lists = [
            'extra_cookie_prefixes' => __('Extra bypass cookie prefixes', 'wp-edge-cache-headers'),
            'extra_paths' => __('Extra bypass path prefixes', 'wp-edge-cache-headers'),
            'extra_query_args' => __('Extra bypass query arguments', 'wp-edge-cache-headers'),
        ];
        foreach ($lists as $key => $label) {
            $value = is_array($v[$key]) ? implode("\n", $v[$key]) : '';
            echo '<tr><th scope="row"><label for="ech-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>';
            echo '<textarea id="ech-' . esc_attr($key) . '" name="' . esc_attr($key) . '" rows="3" cols="40">' . esc_textarea($value) . '</textarea>';
            echo '<p class="description">' . esc_html__('One per line. Regenerate the nginx and Cloudflare snippets after changing these.', 'wp-edge-cache-headers') . '</p></td></tr>';
        }

        if (!$network) {
            echo '<tr><th scope="row">' . esc_html__('Debug token', 'wp-edge-cache-headers') . '</th><td>';
            if ($settings->locked('debug_token_hash')) {
                echo esc_html__('Set by a constant in wp-config.php.', 'wp-edge-cache-headers');
            } else {
                echo '<label><input type="checkbox" name="rotate_token" value="1"> ' . esc_html__('Rotate on save (the new token is shown once)', 'wp-edge-cache-headers') . '</label>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        submit_button();
        echo '</form>';

        if (!$network) {
            $this->explainForm($explainHtml, $post);
        }
        echo '</div>';
    }

    /**
     * @param array<mixed> $post
     */
    private function explainForm(string $explainHtml, array $post): void
    {
        $url = isset($post['explain_url']) && is_string($post['explain_url']) ? $post['explain_url'] : home_url('/');
        echo '<hr><h2>' . esc_html__('Explain a URL', 'wp-edge-cache-headers') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('options-general.php?page=' . self::SLUG)) . '">';
        wp_nonce_field(self::NONCE_EXPLAIN);
        echo '<input type="hidden" name="edge_cache_headers_action" value="explain">';
        echo '<p><input type="url" name="explain_url" class="regular-text" value="' . esc_attr($url) . '" required></p>';
        echo '<p><label>' . esc_html__('Method', 'wp-edge-cache-headers') . ' <input type="text" name="explain_method" value="GET" size="7"></label> ';
        echo '<label>' . esc_html__('Cookie names', 'wp-edge-cache-headers') . ' <input type="text" name="explain_cookies" value=""></label> ';
        echo '<label>' . esc_html__('Header', 'wp-edge-cache-headers') . ' <input type="text" name="explain_header" placeholder="Authorization: Bearer x"></label> ';
        echo '<label><input type="checkbox" name="explain_logged_in" value="1"> ' . esc_html__('Logged in', 'wp-edge-cache-headers') . '</label> ';
        echo '<label><input type="checkbox" name="explain_live" value="1"> ' . esc_html__('Live request', 'wp-edge-cache-headers') . '</label></p>';
        submit_button(__('Explain', 'wp-edge-cache-headers'), 'secondary');
        echo '</form>';
        echo $explainHtml; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
    }

    private function error(string $message): string
    {
        return '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
    }
}
