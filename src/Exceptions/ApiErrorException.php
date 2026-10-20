<?php

declare(strict_types=1);

namespace Mnd\DmPlatform\Exceptions;

/**
 * A response the installation refused. It is not retried: repeating a validation error
 * or an idempotency conflict does not change the outcome.
 */
class ApiErrorException extends DmPlatformException
{
    /**
     * @param array<string, mixed> $fields
     */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly string $errorCode,
        public readonly array $fields = [],
    ) {
        parent::__construct($message);
    }
}
