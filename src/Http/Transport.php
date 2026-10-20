<?php

declare(strict_types=1);

namespace Mnd\DmPlatform\Http;

use Closure;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\RequestOptions;
use Mnd\DmPlatform\Endpoint;
use Mnd\DmPlatform\Exceptions\ApiErrorException;
use Mnd\DmPlatform\Exceptions\ServiceUnavailableException;
use Psr\Http\Message\ResponseInterface;
use Ramsey\Uuid\Uuid;

/**
 * Calls one installation. A timeout, a rate limit and a 5xx are retried with the same
 * X-Request-Id and Idempotency-Key. Other 4xx are the installation's answer and stop here.
 * Only a 5xx and a network failure count toward the circuit breaker: a 429 means the
 * installation is up and is limiting this key. A 2xx that is not JSON counts as an outage too.
 */
final class Transport
{
    private const RETRIABLE_STATUSES = [408, 425, 429];

    /**
     * @param list<int>    $retryDelaysMs delays before each retry; their count is the number of retries
     * @param Closure|null $sleeper       receives the delay in milliseconds; production sleeps
     */
    public function __construct(
        private readonly ClientInterface $http,
        private readonly Endpoint $endpoint,
        private readonly CircuitBreaker $circuit,
        private readonly array $retryDelaysMs = [200, 500],
        private readonly float $timeout = 5.0,
        private readonly float $connectTimeout = 2.0,
        private readonly int $retryAfterCapSeconds = 2,
        private readonly ?Closure $sleeper = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function send(OutgoingRequest $request): array
    {
        $response = $this->dispatch($request);
        $body     = (string) $response->getBody();

        if ($body === '') {
            return [];
        }

        $data = json_decode($body, true);

        if (! is_array($data)) {
            // A proxy or an error page answered instead of the API: not an answer of the installation.
            $this->circuit->recordFailure();

            throw new ServiceUnavailableException(
                "Installation {$this->endpoint->code} answered not JSON to {$request->method} {$request->path}"
            );
        }

        return $data;
    }

    private function dispatch(OutgoingRequest $request): ResponseInterface
    {
        $this->circuit->assertClosed();

        $requestId      = Uuid::uuid7()->toString();
        $idempotencyKey = $this->idempotencyKey($request);

        for ($attempt = 0;; $attempt++) {
            try {
                $response = $this->attempt($request, $requestId, $idempotencyKey);
            } catch (ServiceUnavailableException $e) {
                $this->circuit->recordFailure();
                if ($this->giveUp($attempt)) {
                    throw $e;
                }

                $this->pause($this->retryDelaysMs[$attempt] ?? 0);
                continue;
            }

            $status = $response->getStatusCode();

            if ($status >= 500) {
                $this->circuit->recordFailure();
                if ($this->giveUp($attempt)) {
                    throw new ServiceUnavailableException(
                        "Installation {$this->endpoint->code} answered {$status}"
                    );
                }

                $this->pause($this->retryDelaysMs[$attempt] ?? 0);
                continue;
            }

            // Any other status is an answer, so this installation is up.
            $this->circuit->recordSuccess();

            if (in_array($status, self::RETRIABLE_STATUSES, true)) {
                if ($this->giveUp($attempt)) {
                    throw $this->apiError($response);
                }

                $delay = $status === 429
                    ? $this->retryAfterMs($response, $attempt)
                    : ($this->retryDelaysMs[$attempt] ?? 0);
                $this->pause($delay);
                continue;
            }

            if ($status >= 400) {
                throw $this->apiError($response);
            }

            return $response;
        }
    }

    private function attempt(
        OutgoingRequest $request,
        string $requestId,
        ?string $idempotencyKey,
    ): ResponseInterface {
        $headers = [
            'Accept'        => 'application/json',
            'Authorization' => 'Bearer ' . $this->endpoint->apiKey,
            'X-Request-Id'  => $requestId,
        ];

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $options = [
            RequestOptions::HEADERS         => $headers,
            RequestOptions::TIMEOUT         => $this->timeout,
            RequestOptions::CONNECT_TIMEOUT => $this->connectTimeout,
            RequestOptions::HTTP_ERRORS     => false,
        ];

        if ($request->query !== []) {
            $options[RequestOptions::QUERY] = $request->query;
        }

        if ($request->body !== null) {
            $options[RequestOptions::JSON] = $request->body;
        }

        try {
            return $this->http->request($request->method, $this->endpoint->url($request->path), $options);
        } catch (TransferException $e) {
            // Only a network failure or a timeout. A wrong option or URL is a bug of the caller
            // and must not be retried or open the breaker.
            throw new ServiceUnavailableException(
                "Installation {$this->endpoint->code} did not answer",
                0,
                $e
            );
        }
    }

    private function idempotencyKey(OutgoingRequest $request): ?string
    {
        if ($request->idempotencyKey !== null && $request->idempotencyKey !== '') {
            return $request->idempotencyKey;
        }

        if ($request->method === 'POST') {
            return Uuid::uuid7()->toString();
        }

        return null;
    }

    private function giveUp(int $attempt): bool
    {
        return $attempt >= count($this->retryDelaysMs) || $this->circuit->isOpen();
    }

    private function pause(int $delayMs): void
    {
        if ($delayMs <= 0) {
            return;
        }

        if ($this->sleeper !== null) {
            ($this->sleeper)($delayMs);

            return;
        }

        usleep($delayMs * 1000);
    }

    /**
     * A huge Retry-After must not stall the caller. The header is honored up to the cap;
     * a missing or non-numeric value falls back to the retry schedule.
     */
    private function retryAfterMs(ResponseInterface $response, int $attempt): int
    {
        $header = $response->getHeaderLine('Retry-After');

        if ($header === '' || ! ctype_digit($header)) {
            return $this->retryDelaysMs[$attempt] ?? 0;
        }

        return min((int) $header, $this->retryAfterCapSeconds) * 1000;
    }

    private function apiError(ResponseInterface $response): ApiErrorException
    {
        $payload = json_decode((string) $response->getBody(), true);
        $error   = is_array($payload) && is_array($payload['error'] ?? null) ? $payload['error'] : [];
        $code    = is_string($error['code'] ?? null) ? $error['code'] : $this->fallbackCode($response);
        $message = is_string($error['message'] ?? null) ? $error['message'] : 'Installation refused the request';
        $fields  = is_array($error['fields'] ?? null) ? $error['fields'] : [];

        return new ApiErrorException($message, $response->getStatusCode(), $code, $fields);
    }

    private function fallbackCode(ResponseInterface $response): string
    {
        return $response->getStatusCode() === 429 ? 'rate_limited' : 'bad_request';
    }
}
