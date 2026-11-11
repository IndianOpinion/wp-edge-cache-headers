<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders\WordPress;

use IndianOpinion\EdgeCacheHeaders\RequestFacts;

/**
 * Watches core's no-cache helper through its `nocache_headers` filter.
 *
 * A call while the visitor is logged in, or for a 404 before the main
 * decision, is core's own and owned by this plugin (C30, C31). Any other
 * call is a third-party no-cache, which the plugin respects (C32).
 */
final class NocacheObserver
{
    private bool $thirdParty = false;
    private bool $decided = false;

    /** @var list<string> Cache-Control values core's owned calls produced. */
    private array $ownedValues = [];

    /**
     * Filter callback. Returns the headers unchanged.
     *
     * @param mixed $headers
     * @return mixed
     */
    public function observe($headers)
    {
        $value = is_array($headers) && isset($headers['Cache-Control']) && is_string($headers['Cache-Control'])
            ? $headers['Cache-Control']
            : null;

        if ($this->isOwned()) {
            if ($value !== null) {
                $this->ownedValues[] = $value;
            }
        } else {
            $this->thirdParty = true;
        }
        return $headers;
    }

    /** Called once the main decision is made: later 404 calls are not core's. */
    public function markDecided(): void
    {
        $this->decided = true;
    }

    public function kind(): string
    {
        if ($this->thirdParty) {
            return RequestFacts::NOCACHE_THIRD_PARTY;
        }
        return $this->ownedValues === [] ? RequestFacts::NOCACHE_NONE : RequestFacts::NOCACHE_CORE;
    }

    public function isOwnedValue(string $value): bool
    {
        return in_array($value, $this->ownedValues, true);
    }

    public function reset(): void
    {
        $this->thirdParty = false;
        $this->decided = false;
        $this->ownedValues = [];
    }

    private function isOwned(): bool
    {
        if (function_exists('is_user_logged_in') && did_action('init') > 0 && is_user_logged_in()) {
            return true;
        }
        if ($this->decided) {
            return false;
        }
        global $wp_query;
        return isset($wp_query) && $wp_query instanceof \WP_Query && $wp_query->is_404();
    }
}
