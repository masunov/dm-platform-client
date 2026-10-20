<?php

declare(strict_types=1);

namespace Mnd\DmPlatform\Dto;

final class Domain
{
    public function __construct(
        public readonly string $host,
        public readonly string $kind,
        public readonly string $status,
        public readonly bool $isPrimary,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            Payload::string($payload, 'host'),
            Payload::string($payload, 'kind'),
            Payload::string($payload, 'status'),
            Payload::bool($payload, 'is_primary'),
        );
    }
}
