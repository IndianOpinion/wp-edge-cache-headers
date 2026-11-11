<?php

/**
 * Plugin Name:       Edge Cache Headers
 * Plugin URI:        https://github.com/IndianOpinion/wp-edge-cache-headers
 * Description:       Per-template Cache-Control for WordPress so Cloudflare and nginx can cache anonymous HTML safely.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Indian Opinion
 * Author URI:        https://indianopinion.org
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       wp-edge-cache-headers
 * Network:           true
 *
 * Works as a normal plugin and as a must-use plugin (see README).
 *
 * @package wp-edge-cache-headers
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (defined('EDGE_CACHE_HEADERS_LOADED')) {
    return;
}
define('EDGE_CACHE_HEADERS_LOADED', __FILE__);

if (version_compare(PHP_VERSION, '8.1', '<')) {
    return;
}

if (!class_exists(\IndianOpinion\EdgeCacheHeaders\WordPress\Plugin::class)) {
    require_once __DIR__ . '/autoload.php';
}

\IndianOpinion\EdgeCacheHeaders\WordPress\Plugin::boot();
