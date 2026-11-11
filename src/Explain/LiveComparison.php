<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders\Explain;

use IndianOpinion\EdgeCacheHeaders\Decision;
use IndianOpinion\EdgeCacheHeaders\DecisionEngine;
use IndianOpinion\EdgeCacheHeaders\Outcome;
use IndianOpinion\EdgeCacheHeaders\Policy;

/**
 * Compares a simulated decision with the headers a live request returned.
 *
 * The live request carries a one-time debug token, so it is always forced
 * PRIVATE; the comparison uses the would-be outcome from its debug header.
 */
final class LiveComparison
{
    private const FIELDS = ['type', 'ttl', 'swr', 'sie', 'status'];

    /**
     * Response headers worth showing when present, lower case.
     */
    public const OBSERVED = ['cf-cache-status', 'age', 'x-cache-status', 'cache-control', 'cdn-cache-control', 'x-accel-expires'];

    /**
     * @param array<string, string> $liveHeaders Response headers, any case.
     */
    public static function compare(Decision $simulated, array $liveHeaders): ComparisonResult
    {
        $lower = [];
        foreach ($liveHeaders as $name => $value) {
            $lower[strtolower($name)] = $value;
        }
        $observed = [];
        foreach (self::OBSERVED as $name) {
            if (isset($lower[$name])) {
                $observed[$name] = $lower[$name];
            }
        }

        $debug = $lower[strtolower(Policy::DEBUG_HEADER)] ?? null;
        if ($debug === null) {
            return new ComparisonResult(false, [
                'The live response had no ' . Policy::DEBUG_HEADER . ' header. It was served from a cache that '
                . 'ignores the debug token, or the plugin did not run (headers already sent, page-cache plugin, disabled).',
            ], $observed);
        }

        $live = DecisionEngine::parseDebugValue($debug);
        $expected = DecisionEngine::parseDebugValue(DecisionEngine::debugValue($simulated));
        $diff = [];

        $liveOutcome = $live['would-be'] ?? ($live['outcome'] ?? '');
        $simOutcome = ($simulated->wouldBe ?? $simulated->outcome)->value;
        if ($liveOutcome !== $simOutcome) {
            $diff[] = sprintf('outcome: simulated %s, live %s', $simOutcome, $liveOutcome === '' ? '(missing)' : $liveOutcome);
        }
        if ($simOutcome !== Outcome::SKIP->value) {
            foreach (self::FIELDS as $field) {
                $want = $expected[$field] ?? '';
                $got = $live[$field] ?? '';
                if ($want !== $got) {
                    $diff[] = sprintf('%s: simulated %s, live %s', $field, $want, $got === '' ? '(missing)' : $got);
                }
            }
        }
        return new ComparisonResult($diff === [], $diff, $observed);
    }
}
