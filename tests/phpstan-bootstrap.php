<?php

declare(strict_types=1);

// Constants WordPress defines at runtime, for static analysis only.
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!defined('WP_UNINSTALL_PLUGIN')) {
    define('WP_UNINSTALL_PLUGIN', 'wp-edge-cache-headers/wp-edge-cache-headers.php');
}
