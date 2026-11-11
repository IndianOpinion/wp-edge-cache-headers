<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders\WordPress;

/**
 * Runs WP::parse_request() and WP::query_posts() for a URL, without sending
 * any header, then restores the globals it replaced.
 *
 * The 404 rule mirrors core's handle_404() closely but not exactly: an empty
 * archive for a term or author that exists stays 200, anything else with no
 * posts becomes 404.
 */
final class WpQueryResolver implements QueryResolver
{
    public function resolve(string $url, string $requestUri, string $host, array $get): array
    {
        $saved = [
            'wp' => $GLOBALS['wp'] ?? null,
            'wp_query' => $GLOBALS['wp_query'] ?? null,
            'wp_the_query' => $GLOBALS['wp_the_query'] ?? null,
            'post' => $GLOBALS['post'] ?? null,
        ];
        $savedServer = $_SERVER;
        $savedGet = $_GET;

        $restore = static function () use ($saved, $savedServer, $savedGet): void {
            foreach ($saved as $key => $value) {
                $GLOBALS[$key] = $value;
            }
            $_SERVER = $savedServer;
            $_GET = $savedGet;
        };

        try {
            $_SERVER['REQUEST_URI'] = $requestUri;
            $_SERVER['HTTP_HOST'] = $host;
            $_SERVER['REQUEST_METHOD'] = 'GET';
            unset($_SERVER['PATH_INFO']);
            $_GET = $get;

            $query = new \WP_Query();
            $GLOBALS['wp_the_query'] = $query;
            $GLOBALS['wp_query'] = $query;
            $wp = new \WP();
            $GLOBALS['wp'] = $wp;
            $wp->parse_request();
            $wp->query_posts();

            $status = 200;
            if ($query->is_404()) {
                $status = 404;
            } elseif (
                count($query->posts) === 0
                && !$query->is_robots()
                && !$query->is_favicon()
                && !$query->is_search()
                && !$query->is_home()
                && !($query->is_archive() && $query->get_queried_object() !== null)
                && (string) get_query_var('sitemap') === ''
            ) {
                $query->set_404();
                $status = 404;
            }

            $redirect = null;
            if ($status === 200 && !is_admin() && function_exists('redirect_canonical')) {
                $target = redirect_canonical($url, false);
                if (is_string($target) && $target !== '' && $target !== $url) {
                    $status = 301;
                    $redirect = $target;
                }
            }
        } catch (\Throwable $e) {
            $restore();
            throw new ExplainFailed('WordPress could not resolve the URL: ' . $e->getMessage(), 0, $e);
        }

        return ['status' => $status, 'redirect' => $redirect, 'restore' => $restore];
    }
}
