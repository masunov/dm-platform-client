<?php

declare(strict_types=1);

namespace Mnd\DmPlatform\Tests\Unit;

use Closure;
use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;

/**
 * A process-local cache so two clients of one installation share the circuit breaker in tests.
 * It honors the TTL against the clock the test controls.
 */
final class ArrayCache implements CacheInterface
{
    /** @var array<string, array{value: mixed, expiresAt: int|null}> */
    private array $values = [];

    /**
     * @param Closure|null $clock           returns the current unix time; time() by default
     * @param bool         $numbersAsStrings returns numbers as strings, as Laravel's Redis store does
     */
    public function __construct(
        private readonly ?Closure $clock = null,
        private readonly bool $numbersAsStrings = false,
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (! $this->has($key)) {
            return $default;
        }

        $value = $this->values[$key]['value'];

        return $this->numbersAsStrings && is_int($value) ? (string) $value : $value;
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $this->assertKey($key);
        $this->values[$key] = ['value' => $value, 'expiresAt' => $this->expiresAt($ttl)];

        return true;
    }

    public function delete(string $key): bool
    {
        $this->assertKey($key);
        unset($this->values[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->values = [];

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $found = [];

        foreach ($keys as $key) {
            $found[$key] = $this->get($key, $default);
        }

        return $found;
    }

    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        $this->assertKey($key);

        if (! array_key_exists($key, $this->values)) {
            return false;
        }

        $expiresAt = $this->values[$key]['expiresAt'];

        if ($expiresAt !== null && $expiresAt <= $this->now()) {
            unset($this->values[$key]);

            return false;
        }

        return true;
    }

    private function expiresAt(null|int|DateInterval $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }

        if ($ttl instanceof DateInterval) {
            return (new DateTimeImmutable('@' . $this->now()))->add($ttl)->getTimestamp();
        }

        return $this->now() + $ttl;
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }

    private function assertKey(string $key): void
    {
        if ($key === '') {
            throw new InvalidArgumentException('Cache key is empty');
        }
    }
}
