<?php

/**
 * Removes the plugin's options when it is deleted from the Plugins screen.
 * Must-use installs have no uninstall step; see README for the option names.
 *
 * @package wp-edge-cache-headers
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (!class_exists(\IndianOpinion\EdgeCacheHeaders\WordPress\Uninstaller::class)) {
    require_once __DIR__ . '/autoload.php';
}

\IndianOpinion\EdgeCacheHeaders\WordPress\Uninstaller::run();
