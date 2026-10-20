<?php

declare(strict_types=1);

namespace Mnd\DmPlatform\Dto;

use Mnd\DmPlatform\Exceptions\DmPlatformException;

/**
 * The full subscription snapshot an installation applies when `version` is greater than the stored one.
 */
final class Entitlements
{
    public function __construct(
        public readonly int $version,
        public readonly string $state,
        public readonly ?int $usersLimit,
        public readonly ?string $paidUntil,
        public readonly ?string $graceUntil,
        public readonly ?string $deleteAfter,
        public readonly ?bool $whiteLabel = null,
        public readonly ?int $customDomains = null,
        public readonly ?bool $sms = null,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $features = $payload['features'] ?? null;

        if ($features === null) {
            $features = [];
        }

        if (! is_array($features)) {
            throw new DmPlatformException('Platform response field features is not an object');
        }

        return new self(
            Payload::int($payload, 'version'),
            Payload::string($payload, 'state'),
            Payload::nullableInt($payload, 'users_limit'),
            Payload::nullableString($payload, 'paid_until'),
            Payload::nullableString($payload, 'grace_until'),
            Payload::nullableString($payload, 'delete_after'),
            Payload::nullableBool($features, 'white_label'),
            Payload::nullableInt($features, 'custom_domains'),
            Payload::nullableBool($features, 'sms'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version'      => $this->version,
            'state'        => $this->state,
            'users_limit'  => $this->usersLimit,
            'paid_until'   => $this->paidUntil,
            'grace_until'  => $this->graceUntil,
            'delete_after' => $this->deleteAfter,
            'features'     => [
                'white_label'    => $this->whiteLabel,
                'custom_domains' => $this->customDomains,
                'sms'            => $this->sms,
            ],
        ];
    }
}
