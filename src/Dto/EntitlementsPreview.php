<?php

declare(strict_types=1);

namespace Mnd\DmPlatform\Dto;

final class EntitlementsPreview
{
    public function __construct(
        public readonly bool $allowed,
        public readonly ?string $reason,
        public readonly int $usersUsage,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            Payload::bool($payload, 'allowed'),
            Payload::nullableString($payload, 'reason'),
            Payload::int($payload, 'users_usage'),
        );
    }
}
