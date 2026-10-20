<?php

declare(strict_types=1);

namespace Mnd\DmPlatform\Dto;

final class OwnerActivation
{
    public function __construct(
        public readonly string $url,
        public readonly string $expiresAt,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            Payload::string($payload, 'url'),
            Payload::string($payload, 'expires_at'),
        );
    }
}
