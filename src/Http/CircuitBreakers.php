<?php

declare(strict_types=1);

namespace Mnd\DmPlatform\Http;

use Closure;
use Psr\SimpleCache\CacheInterface;

/**
 * Breakers share one cache, so two clients of ru-1 see the same open state,
 * while kz-1 keeps a separate counter.
 */
final class CircuitBreakers
{
    /**
     * @param Closure|null $clock returns the current unix time; production uses time()
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly int $threshold = 5,
        private readonly int $cooldownSeconds = 30,
        private readonly ?Closure $clock = null,
    ) {
    }

    public function for(string $code): CircuitBreaker
    {
        return new CircuitBreaker($this->cache, $code, $this->threshold, $this->cooldownSeconds, $this->clock);
    }
}
