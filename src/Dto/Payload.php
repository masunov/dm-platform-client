<?php

declare(strict_types=1);

namespace Mnd\DmPlatform\Dto;

use Mnd\DmPlatform\Exceptions\DmPlatformException;

/**
 * Reads one field of a Platform API body. A missing field is a broken installation, not a domain answer.
 */
final class Payload
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function string(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new DmPlatformException("Platform response has no {$key}");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function nullableString(array $payload, string $key): ?string
    {
        if (! array_key_exists($key, $payload) || $payload[$key] === null) {
            return null;
        }

        if (! is_string($payload[$key])) {
            throw new DmPlatformException("Platform response field {$key} is not a string");
        }

        return $payload[$key];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function int(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;

        if (! is_int($value)) {
            throw new DmPlatformException("Platform response has no {$key}");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function nullableInt(array $payload, string $key): ?int
    {
        if (! array_key_exists($key, $payload) || $payload[$key] === null) {
            return null;
        }

        if (! is_int($payload[$key])) {
            throw new DmPlatformException("Platform response field {$key} is not an integer");
        }

        return $payload[$key];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function bool(array $payload, string $key): bool
    {
        $value = $payload[$key] ?? null;

        if (! is_bool($value)) {
            throw new DmPlatformException("Platform response has no {$key}");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function array(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;

        if (! is_array($value)) {
            throw new DmPlatformException("Platform response has no {$key}");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function nullableBool(array $payload, string $key): ?bool
    {
        if (! array_key_exists($key, $payload) || $payload[$key] === null) {
            return null;
        }

        if (! is_bool($payload[$key])) {
            throw new DmPlatformException("Platform response field {$key} is not a boolean");
        }

        return $payload[$key];
    }
}
