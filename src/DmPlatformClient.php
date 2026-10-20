<?php

declare(strict_types=1);

namespace Mnd\DmPlatform;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Mnd\DmPlatform\Dto\Availability;
use Mnd\DmPlatform\Dto\DeletionAccepted;
use Mnd\DmPlatform\Dto\Entitlements;
use Mnd\DmPlatform\Dto\EntitlementsPreview;
use Mnd\DmPlatform\Dto\EntitlementsResult;
use Mnd\DmPlatform\Dto\OwnerActivation;
use Mnd\DmPlatform\Dto\TenantInput;
use Mnd\DmPlatform\Dto\TenantSnapshot;
use Mnd\DmPlatform\Http\CircuitBreakers;
use Mnd\DmPlatform\Http\OutgoingRequest;
use Mnd\DmPlatform\Http\Transport;
use Mnd\DmPlatform\Webhook\WebhookVerifier;

/**
 * Platform API v1 for one installation. The application chooses the installation and passes it in.
 */
final class DmPlatformClient
{
    private function __construct(
        private readonly Transport $transport,
        private readonly Endpoint $endpoint,
        private readonly int $webhookToleranceSeconds,
    ) {
    }

    /**
     * @param list<int> $retryDelaysMs
     */
    public static function connect(
        Endpoint $endpoint,
        CircuitBreakers $circuits,
        ?ClientInterface $http = null,
        array $retryDelaysMs = [200, 500],
        int $retryAfterCapSeconds = 2,
        float $timeout = 5.0,
        float $connectTimeout = 2.0,
        int $webhookToleranceSeconds = 300,
    ): self {
        return new self(
            new Transport(
                $http ?? new Client(['http_errors' => false]),
                $endpoint,
                $circuits->for($endpoint->code),
                $retryDelaysMs,
                $timeout,
                $connectTimeout,
                $retryAfterCapSeconds,
            ),
            $endpoint,
            $webhookToleranceSeconds,
        );
    }

    /**
     * Sends a request the application stored in its outbox. The typed methods build the same request
     * for a direct call; the worker repeats the stored one, including its idempotency key.
     *
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    public function sendStored(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->transport->send(
            new OutgoingRequest(
                $method,
                $path,
                body: $body,
                idempotencyKey: $idempotencyKey,
            )
        );
    }

    public function availability(string $techName): Availability
    {
        return Availability::fromArray(
            $this->transport->send(
                new OutgoingRequest(
                    'GET',
                    '/tenants/availability',
                    ['tech_name' => $techName],
                )
            )
        );
    }

    public function putTenant(string $schoolId, TenantInput $input): TenantSnapshot
    {
        return TenantSnapshot::fromArray(
            $this->transport->send(
                new OutgoingRequest(
                    'PUT',
                    $this->tenantPath($schoolId),
                    body: $input->toArray(),
                )
            )
        );
    }

    public function getTenant(string $schoolId): TenantSnapshot
    {
        return TenantSnapshot::fromArray(
            $this->transport->send(
                new OutgoingRequest(
                    'GET',
                    $this->tenantPath($schoolId),
                )
            )
        );
    }

    public function deleteTenant(string $schoolId): DeletionAccepted
    {
        return DeletionAccepted::fromArray(
            $this->transport->send(
                new OutgoingRequest(
                    'DELETE',
                    $this->tenantPath($schoolId),
                )
            )
        );
    }

    public function putEntitlements(string $schoolId, Entitlements $entitlements): EntitlementsResult
    {
        return EntitlementsResult::fromArray(
            $this->transport->send(
                new OutgoingRequest(
                    'PUT',
                    $this->tenantPath($schoolId) . '/entitlements',
                    body: $entitlements->toArray(),
                )
            )
        );
    }

    public function previewEntitlements(
        string $schoolId,
        ?int $usersLimit,
        ?string $idempotencyKey = null,
    ): EntitlementsPreview {
        return EntitlementsPreview::fromArray(
            $this->transport->send(
                new OutgoingRequest(
                    'POST',
                    $this->tenantPath($schoolId) . '/entitlements/preview',
                    body: ['users_limit' => $usersLimit],
                    idempotencyKey: $idempotencyKey,
                )
            )
        );
    }

    public function createOwnerActivation(string $schoolId, ?string $idempotencyKey = null): OwnerActivation
    {
        return OwnerActivation::fromArray(
            $this->transport->send(
                new OutgoingRequest(
                    'POST',
                    $this->tenantPath($schoolId) . '/owner-activation',
                    idempotencyKey: $idempotencyKey,
                )
            )
        );
    }

    public function webhooks(): WebhookVerifier
    {
        return new WebhookVerifier($this->endpoint->webhookSecret, $this->webhookToleranceSeconds);
    }

    private function tenantPath(string $schoolId): string
    {
        return '/tenants/' . rawurlencode($schoolId);
    }
}
