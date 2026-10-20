<?php

declare(strict_types=1);

namespace Mnd\DmPlatform\Webhook;

use Mnd\DmPlatform\Exceptions\DmPlatformException;

/**
 * One event an installation posts to the cabinet. The application stores it and ignores a repeat
 * of the same event_id, and a version that is not newer than the one already applied.
 */
final class WebhookEvent
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $eventId,
        public readonly string $type,
        public readonly string $tenantExternalId,
        public readonly string $instance,
        public readonly string $occurredAt,
        public readonly int $version,
        public readonly array $data,
    ) {
    }

    public static function fromJson(string $body): self
    {
        $payload = json_decode($body, true);

        if (
            ! is_array($payload)
            || ! isset(
                $payload['event_id'],
                $payload['type'],
                $payload['tenant_external_id'],
                $payload['instance'],
                $payload['occurred_at'],
                $payload['version'],
                $payload['data']
            )
            || ! is_string($payload['event_id'])
            || ! is_string($payload['type'])
            || ! is_string($payload['tenant_external_id'])
            || ! is_string($payload['instance'])
            || ! is_string($payload['occurred_at'])
            || ! is_int($payload['version'])
            || ! is_array($payload['data'])
        ) {
            throw new DmPlatformException('Webhook body is not a platform event');
        }

        return new self(
            $payload['event_id'],
            $payload['type'],
            $payload['tenant_external_id'],
            $payload['instance'],
            $payload['occurred_at'],
            $payload['version'],
            $payload['data'],
        );
    }
}
