<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders\WordPress;

/**
 * HttpClient on the WordPress HTTP API. No redirects are followed.
 */
final class WpHttpClient implements HttpClient
{
    public function head(string $url, array $headers): array
    {
        $response = wp_remote_request($url, [
            'method' => 'HEAD',
            'headers' => $headers,
            'redirection' => 0,
            'timeout' => 10,
            'user-agent' => 'wp-edge-cache-headers/' . Plugin::VERSION . ' explain',
        ]);
        if (is_wp_error($response)) {
            throw new LoopbackFailed($response->get_error_message());
        }
        $out = [];
        $raw = wp_remote_retrieve_headers($response);
        $iterable = is_object($raw) && method_exists($raw, 'getAll') ? $raw->getAll() : (array) $raw;
        foreach ($iterable as $name => $value) {
            $out[strtolower((string) $name)] = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
        }
        return $out;
    }
}
