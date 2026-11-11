<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders;

/**
 * Turns a count of recent posts into a posts-per-hour suggestion.
 */
final class RateAdvisor
{
    /**
     * @return array{posts_per_hour: float, profile: string}
     */
    public static function suggest(int $posts, float $hours): array
    {
        if ($posts < 0) {
            throw new \InvalidArgumentException('Post count must not be negative.');
        }
        if ($hours <= 0) {
            throw new \InvalidArgumentException('The measured window must be longer than zero hours.');
        }
        $rate = round($posts / $hours, 2);
        return ['posts_per_hour' => $rate, 'profile' => Profile::fromPostsPerHour($rate)];
    }
}
