<?php

declare(strict_types=1);

namespace Mnd\DmPlatform\Dto;

final class EntitlementsResult
{
    public function __construct(
        public readonly bool $applied,
        public readonly int $version,
        public readonly int $usersUsage,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            Payload::bool($payload, 'applied'),
            Payload::int($payload, 'version'),
            Payload::int($payload, 'users_usage'),
        );
    }
}
