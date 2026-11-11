<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders\WordPress;

/**
 * The live explain request could not reach the site (blocked loopback, DNS, TLS, timeout).
 */
final class LoopbackFailed extends \RuntimeException
{
}
