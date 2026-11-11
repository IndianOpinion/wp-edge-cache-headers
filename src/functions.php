<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders;

use IndianOpinion\EdgeCacheHeaders\WordPress\Explainer;
use IndianOpinion\EdgeCacheHeaders\WordPress\ExplainOptions;
use IndianOpinion\EdgeCacheHeaders\WordPress\Plugin;

if (!function_exists(__NAMESPACE__ . '\\current_decision')) {
    /**
     * The decision for the current request, or null before it is made.
     */
    function current_decision(): ?Decision
    {
        return Plugin::instance()->currentDecision();
    }
}

if (!function_exists(__NAMESPACE__ . '\\explain')) {
    /**
     * Explain a URL on this site or network with simulation options.
     *
     * @throws WordPress\ExplainFailed On an invalid or foreign URL.
     */
    function explain(string $url, ?ExplainOptions $options = null): Decision
    {
        return (new Explainer(Plugin::instance()))->explain($url, $options ?? new ExplainOptions())->decision;
    }
}
