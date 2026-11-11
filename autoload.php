<?php

/**
 * Autoloader for use without Composer: one require of this file is enough.
 *
 * @package wp-edge-cache-headers
 */

declare(strict_types=1);

spl_autoload_register(
    static function (string $class): void {
        $prefix = 'IndianOpinion\\EdgeCacheHeaders\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
);

require_once __DIR__ . '/src/functions.php';
