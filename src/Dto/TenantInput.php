<?php

declare(strict_types=1);

namespace Mnd\DmPlatform\Dto;

/**
 * The body of PUT /tenants/{school_id}. The owner has no password: they set it by the activation link.
 */
final class TenantInput
{
    public function __construct(
        public readonly string $techName,
        public readonly string $name,
        public readonly string $country,
        public readonly string $currency,
        public readonly string $timezone,
        public readonly string $ownerName,
        public readonly string $ownerEmail,
        public readonly ?string $ownerPhone = null,
        public readonly ?string $site = null,
        public readonly ?Entitlements $entitlements = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tech_name'    => $this->techName,
            'name'         => $this->name,
            'site'         => $this->site,
            'country'      => $this->country,
            'currency'     => $this->currency,
            'timezone'     => $this->timezone,
            'owner'        => [
                'name'  => $this->ownerName,
                'email' => $this->ownerEmail,
                'phone' => $this->ownerPhone,
            ],
            'entitlements' => $this->entitlements?->toArray(),
        ];
    }
}
