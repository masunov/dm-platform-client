<?php

declare(strict_types=1);

namespace Mnd\DmPlatform;

use GuzzleHttp\ClientInterface;
use Mnd\DmPlatform\Http\CircuitBreakers;

/**
 * Settings shared by every installation. The application keeps one instance and asks it
 * for a client of the installation it has chosen.
 */
final class DmPlatformClients
{
    /**
     * @param list<int> $retryDelaysMs
     */
    public function __construct(
        private readonly CircuitBreakers $circuits,
        private readonly ?ClientInterface $http = null,
        private readonly array $retryDelaysMs = [200, 500],
        private readonly int $retryAfterCapSeconds = 2,
        private readonly float $timeout = 5.0,
        private readonly float $connectTimeout = 2.0,
        private readonly int $webhookToleranceSeconds = 300,
    ) {
    }

    public function for(Endpoint $endpoint): DmPlatformClient
    {
        return DmPlatformClient::connect(
            $endpoint,
            $this->circuits,
            $this->http,
            $this->retryDelaysMs,
            $this->retryAfterCapSeconds,
            $this->timeout,
            $this->connectTimeout,
            $this->webhookToleranceSeconds,
        );
    }
}
