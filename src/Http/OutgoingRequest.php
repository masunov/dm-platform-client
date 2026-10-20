<?php

declare(strict_types=1);

namespace Mnd\DmPlatform\Http;

/**
 * One call. The request id and the idempotency key stay the same across retries
 * of this call, so the installation treats them as one attempt.
 */
final class OutgoingRequest
{
    /**
     * @param array<string, scalar|null> $query
     * @param array<string, mixed>|null  $body
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly ?array $body = null,
        public readonly ?string $idempotencyKey = null,
    ) {
    }
}
