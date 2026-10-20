<?php

declare(strict_types=1);

namespace Mnd\DmPlatform\Http;

use Closure;
use Illuminate\Contracts\Cache\Repository as LaravelCache;
use Mnd\DmPlatform\Exceptions\CircuitOpenException;
use Psr\SimpleCache\CacheInterface;

/**
 * Stops calling one installation after repeated outages. A rate limit is not an outage:
 * 429 is answered by the installation and must not open this breaker.
 *
 * After the cooldown the breaker is half-open: one call probes the installation while the others
 * are still refused. A success closes the breaker, a failure opens it for another cooldown.
 *
 * A Laravel cache counts failures with increment() and claims the probe with add(), which are atomic
 * in the Redis, Memcached and database stores. There the counter window starts at the first failure.
 * A plain PSR-16 cache has no atomic operations, so under a race a few extra probes or lost failures
 * are possible, and each failure extends its window. The package does not require Laravel:
 * without it the instanceof check is simply false.
 */
final class CircuitBreaker
{
    /**
     * @param Closure|null $clock returns the current unix time; production uses time()
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly string $code,
        private readonly int $threshold = 5,
        private readonly int $cooldownSeconds = 30,
        private readonly ?Closure $clock = null,
    ) {
    }

    public function isOpen(): bool
    {
        $openUntil = $this->openUntil();

        return $openUntil !== null && $openUntil > $this->now();
    }

    public function assertClosed(): void
    {
        if ($this->isOpen()) {
            throw new CircuitOpenException("Installation {$this->code} is not accepting calls");
        }

        if ($this->openUntil() === null) {
            return;
        }

        // Half-open: the cooldown is over, but the installation has not answered yet.
        if (! $this->claimProbe()) {
            throw new CircuitOpenException("Installation {$this->code} is being probed");
        }
    }

    public function recordSuccess(): void
    {
        $this->cache->deleteMultiple([$this->key('failures'), $this->key('open_until'), $this->key('probe')]);
    }

    public function recordFailure(): void
    {
        $failures = $this->countFailure();

        // A failed probe reopens at once: its failure counter has already expired with the cooldown.
        if ($failures >= $this->threshold || $this->openUntil() !== null) {
            // open_until outlives the cooldown, so after it the breaker is half-open, not closed.
            $this->cache->set(
                $this->key('open_until'),
                $this->now() + $this->cooldownSeconds,
                $this->cooldownSeconds * 2,
            );
            $this->cache->delete($this->key('probe'));
        }
    }

    private function countFailure(): int
    {
        $key = $this->key('failures');

        if ($this->cache instanceof LaravelCache) {
            // add() starts the window with a TTL only when the counter is missing. If it expires right
            // before increment(), the counter lives without a TTL until the next success deletes it.
            $this->cache->add($key, 0, $this->cooldownSeconds);
            $failures = $this->cache->increment($key);

            if (is_int($failures)) {
                return $failures;
            }
        }

        $failures = ($this->int('failures') ?? 0) + 1;
        $this->cache->set($key, $failures, $this->cooldownSeconds);

        return $failures;
    }

    private function claimProbe(): bool
    {
        $key = $this->key('probe');

        if ($this->cache instanceof LaravelCache) {
            return $this->cache->add($key, $this->now(), $this->cooldownSeconds);
        }

        if ($this->cache->has($key)) {
            return false;
        }

        $this->cache->set($key, $this->now(), $this->cooldownSeconds);

        return true;
    }

    private function openUntil(): ?int
    {
        return $this->int('open_until');
    }

    /**
     * Laravel's Redis store keeps numbers unserialized and returns them as strings.
     */
    private function int(string $name): ?int
    {
        $value = $this->cache->get($this->key($name));

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }

    private function key(string $name): string
    {
        return 'dmplatform:circuit:' . $this->code . ':' . $name;
    }
}
