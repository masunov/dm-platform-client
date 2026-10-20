<?php

declare(strict_types=1);

namespace Mnd\DmPlatform\Dto;

/**
 * Whether a tech name can be claimed. `reason` is invalid, taken, reserved, or null when it is free.
 */
final class Availability
{
    public function __construct(
        public readonly bool $available,
        public readonly ?string $reason,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            Payload::bool($payload, 'available'),
            Payload::nullableString($payload, 'reason'),
        );
    }
}
