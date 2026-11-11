<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders\WordPress;

/**
 * Removes the plugin's two options, and nothing else.
 */
final class Uninstaller
{
    public static function run(): void
    {
        if (function_exists('is_multisite') && is_multisite()) {
            $ids = get_sites(['fields' => 'ids', 'number' => 0]);
            foreach ($ids as $id) {
                delete_blog_option((int) $id, Settings::OPTION);
            }
            delete_site_option(Settings::NETWORK_OPTION);
            return;
        }
        delete_option(Settings::OPTION);
    }
}
