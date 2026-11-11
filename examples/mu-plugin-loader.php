<?php

/**
 * Plugin Name: Edge Cache Headers (must-use loader)
 * Description: Loads wp-edge-cache-headers from its directory in mu-plugins.
 *
 * Copy this file to wp-content/mu-plugins/wp-edge-cache-headers-loader.php and
 * the plugin directory to wp-content/mu-plugins/wp-edge-cache-headers/.
 * WordPress loads only top-level files in mu-plugins, so this stub is needed.
 *
 * @package wp-edge-cache-headers
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once WPMU_PLUGIN_DIR . '/wp-edge-cache-headers/wp-edge-cache-headers.php';
