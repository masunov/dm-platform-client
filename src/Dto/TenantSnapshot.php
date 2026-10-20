<?php

declare(strict_types=1);

namespace Mnd\DmPlatform\Dto;

use Mnd\DmPlatform\Exceptions\DmPlatformException;

final class TenantSnapshot
{
    /**
     * @param list<Domain> $domains
     */
    public function __construct(
        public readonly string $schoolId,
        public readonly string $state,
        public readonly string $techName,
        public readonly string $name,
        public readonly array $domains,
        public readonly ?string $adminUserId,
        public readonly ?string $adminActivatedAt,
        public readonly int $usersUsage,
        public readonly ?Entitlements $entitlements,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $domains = [];

        foreach (Payload::array($payload, 'domains') as $domain) {
            if (! is_array($domain)) {
                throw new DmPlatformException('Platform response field domains is not a list of objects');
            }

            $domains[] = Domain::fromArray($domain);
        }

        $entitlements = $payload['entitlements'] ?? null;

        if ($entitlements !== null && ! is_array($entitlements)) {
            throw new DmPlatformException('Platform response field entitlements is not an object');
        }

        return new self(
            Payload::string($payload, 'school_id'),
            Payload::string($payload, 'state'),
            Payload::string($payload, 'tech_name'),
            Payload::string($payload, 'name'),
            $domains,
            Payload::nullableString($payload, 'admin_user_id'),
            Payload::nullableString($payload, 'admin_activated_at'),
            Payload::int($payload, 'users_usage'),
            is_array($entitlements) ? Entitlements::fromArray($entitlements) : null,
        );
    }
}
