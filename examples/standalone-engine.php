<?php

/**
 * The decision engine has no WordPress calls. Run this file with:
 *
 *     php examples/standalone-engine.php
 *
 * It prints the headers for a few typical requests.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use IndianOpinion\EdgeCacheHeaders\DecisionEngine;
use IndianOpinion\EdgeCacheHeaders\Policy;
use IndianOpinion\EdgeCacheHeaders\Profile;
use IndianOpinion\EdgeCacheHeaders\RequestFacts;

$engine = new DecisionEngine();
$policy = Policy::forProfile(Profile::fromPostsPerHour(5.0))->with(['debug' => true]);

$requests = [
    'anonymous post' => new RequestFacts(conditions: ['singular', 'single'], postType: 'post'),
    'expired login cookie' => new RequestFacts(conditions: ['singular', 'single'], cookieNames: ['wordpress_logged_in_0a1b2c']),
    'theme sets a cookie' => new RequestFacts(conditions: ['singular', 'single'], setCookieNames: ['view_counter']),
    'search' => new RequestFacts(conditions: ['search']),
    'canonical 301' => new RequestFacts(conditions: ['category'], status: 301),
    '404' => new RequestFacts(conditions: ['404'], status: 404),
];

foreach ($requests as $label => $facts) {
    $decision = $engine->decide($facts, $policy);
    echo '== ', $label, ' => ', $decision->outcome->value, "\n";
    foreach ($decision->headers as $name => $value) {
        echo '   ', $name, ': ', $value, "\n";
    }
}
