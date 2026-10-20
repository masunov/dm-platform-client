<?php

declare(strict_types=1);

namespace Mnd\DmPlatform\Dto;

final class DeletionAccepted
{
    public function __construct(
        public readonly string $schoolId,
        public readonly string $requestedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            Payload::string($payload, 'school_id'),
            Payload::string($payload, 'requested_at'),
        );
    }
}
