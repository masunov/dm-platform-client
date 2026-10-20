<?php

declare(strict_types=1);

namespace Mnd\DmPlatform\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use Mnd\DmPlatform\Endpoint;
use Mnd\DmPlatform\Exceptions\ApiErrorException;
use Mnd\DmPlatform\Exceptions\CircuitOpenException;
use Mnd\DmPlatform\Exceptions\ServiceUnavailableException;
use Mnd\DmPlatform\Http\CircuitBreakers;
use Mnd\DmPlatform\Http\OutgoingRequest;
use Mnd\DmPlatform\Http\Transport;
use PHPUnit\Framework\TestCase;

class TransportTest extends TestCase
{
    use MocksHttp;

    public function testARefusedRequestIsNotRetried(): void
    {
        $transport = $this->transport();
        $this->mock->append($this->apiError(409, 'tech_name_taken', 'Имя занято'));

        try {
            $transport->send(new OutgoingRequest('PUT', '/tenants/school-1', body: ['tech_name' => 'drift']));
            $this->fail('A 409 was expected to refuse the call');
        } catch (ApiErrorException $e) {
            $this->assertSame(409, $e->status);
            $this->assertSame('tech_name_taken', $e->errorCode);
            $this->assertSame('Имя занято', $e->getMessage());
        }

        $this->assertCount(1, $this->history);
    }

    public function testAServerErrorIsRetriedAndOpensOnlyThisInstallation(): void
    {
        $cache    = new ArrayCache();
        $circuits = new CircuitBreakers($cache, 2, 60);
        $ru       = $this->transport($circuits, 'ru-1');
        $this->mock->append(
            new Response(500),
            new Response(500),
            $this->jsonResponse(200, ['available' => true, 'reason' => null]),
        );

        try {
            $ru->send(new OutgoingRequest('GET', '/tenants/availability', ['tech_name' => 'drift']));
            $this->fail('Two 500 answers were expected to fail the call');
        } catch (ServiceUnavailableException $e) {
            $this->assertStringContainsString('ru-1 answered 500', $e->getMessage());
        }

        $this->assertCount(2, $this->history);

        $this->expectException(CircuitOpenException::class);
        $ru->send(new OutgoingRequest('GET', '/tenants/availability', ['tech_name' => 'drift']));
    }

    public function testAnotherInstallationStaysOpenWhenOneCircuitOpens(): void
    {
        $cache    = new ArrayCache();
        $circuits = new CircuitBreakers($cache, 1, 60);
        $ruHttp   = $this->mockHttp();
        $this->mock->append(new Response(500));
        $ru = $this->transport($circuits, 'ru-1', $ruHttp, [0]);

        $ruFailed = false;

        try {
            $ru->send(new OutgoingRequest('GET', '/tenants/availability'));
        } catch (ServiceUnavailableException) {
            $ruFailed = true;
        }

        $this->assertTrue($ruFailed);

        $kzHttp = $this->mockHttp();
        $this->mock->append($this->jsonResponse(200, ['available' => false, 'reason' => 'taken']));
        $kz = $this->transport($circuits, 'kz-1', $kzHttp, []);

        $body = $kz->send(new OutgoingRequest('GET', '/tenants/availability', ['tech_name' => 'drift']));

        $this->assertFalse($body['available']);
        $this->assertSame('https://kz-1.school.example/api/v1/tenants/availability', (string) $this->sentRequest()->getUri()->withQuery(''));
    }

    public function testRateLimitIsRetriedWithoutOpeningTheCircuit(): void
    {
        $slept     = [];
        $transport = $this->transport(
            sleeper: function (int $delay) use (&$slept): void {
                $slept[] = $delay;
            },
            retryAfterCapSeconds: 2
        );
        $this->mock->append(
            $this->jsonResponse(
                429,
                [
                'message' => 'Слишком часто',
                'error'   => ['code' => 'rate_limited', 'message' => 'Слишком часто'],
                ],
                ['Retry-After' => '50']
            ),
            $this->jsonResponse(200, ['available' => true, 'reason' => null]),
        );

        $body = $transport->send(
            new OutgoingRequest(
                'POST',
                '/tenants/school-1/entitlements/preview',
                body: ['users_limit' => 10],
                idempotencyKey: 'idem-key-1',
            )
        );

        $this->assertTrue($body['available']);
        $this->assertSame([2000], $slept);
        $this->assertCount(2, $this->history);
        $this->assertSame(
            $this->sentRequest(0)->getHeaderLine('X-Request-Id'),
            $this->sentRequest(1)->getHeaderLine('X-Request-Id')
        );
        $this->assertSame('idem-key-1', $this->sentRequest(0)->getHeaderLine('Idempotency-Key'));
        $this->assertSame('idem-key-1', $this->sentRequest(1)->getHeaderLine('Idempotency-Key'));
        $this->assertSame('Bearer key-1', $this->sentRequest(0)->getHeaderLine('Authorization'));

        $this->mock->append($this->jsonResponse(200, ['ok' => true]));
        $this->assertSame(['ok' => true], $transport->send(new OutgoingRequest('GET', '/tenants/school-1')));
    }

    public function testANetworkErrorIsRetried(): void
    {
        $transport = $this->transport();
        $this->mock->append($this->connectFailure(), $this->jsonResponse(200, ['ok' => true]));

        $body = $transport->send(new OutgoingRequest('GET', '/tenants/school-1'));

        $this->assertSame(['ok' => true], $body);
        $this->assertCount(2, $this->history);
    }

    public function testRequestTimeoutAndTooEarlyAreRetried(): void
    {
        $transport = $this->transport();
        $this->mock->append(new Response(408), new Response(425), $this->jsonResponse(200, ['ok' => true]));

        $this->assertSame(['ok' => true], $transport->send(new OutgoingRequest('GET', '/tenants/school-1')));
        $this->assertCount(3, $this->history);
    }

    public function testASpentRateLimitIsAnApiErrorAndKeepsTheCircuitClosed(): void
    {
        $circuits  = new CircuitBreakers(new ArrayCache(), 1, 60);
        $transport = $this->transport($circuits);
        $this->mock->append(new Response(429), new Response(429), new Response(429));

        try {
            $transport->send(new OutgoingRequest('GET', '/tenants/school-1'));
            $this->fail('Three 429 answers were expected to refuse the call');
        } catch (ApiErrorException $e) {
            $this->assertSame(429, $e->status);
            $this->assertSame('rate_limited', $e->errorCode);
        }

        $this->assertCount(3, $this->history);
        $this->assertFalse($circuits->for('ru-1')->isOpen());
    }

    public function testARetryAfterDateFallsBackToTheSchedule(): void
    {
        $slept     = [];
        $transport = $this->transport(
            delays: [150],
            sleeper: function (int $delay) use (&$slept): void {
                $slept[] = $delay;
            },
        );
        $this->mock->append(
            new Response(429, ['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT']),
            $this->jsonResponse(200, ['ok' => true]),
        );

        $transport->send(new OutgoingRequest('GET', '/tenants/school-1'));

        $this->assertSame([150], $slept);
    }

    public function testAnEmptyBodyIsAnEmptyAnswer(): void
    {
        $transport = $this->transport();
        $this->mock->append(new Response(204));

        $this->assertSame([], $transport->send(new OutgoingRequest('DELETE', '/tenants/school-1')));
    }

    public function testABodyThatIsNotJsonCountsTowardTheCircuit(): void
    {
        $circuits  = new CircuitBreakers(new ArrayCache(), 1, 60);
        $transport = $this->transport($circuits);
        $this->mock->append(new Response(200, ['Content-Type' => 'text/html'], '<html>Bad gateway</html>'));

        try {
            $transport->send(new OutgoingRequest('GET', '/tenants/school-1'));
            $this->fail('An HTML answer was expected to fail the call');
        } catch (ServiceUnavailableException $e) {
            $this->assertStringContainsString('not JSON', $e->getMessage());
        }

        $this->assertTrue($circuits->for('ru-1')->isOpen());
    }

    public function testAConfigurationErrorIsNotRetriedAndKeepsTheCircuitClosed(): void
    {
        $circuits  = new CircuitBreakers(new ArrayCache(), 1, 60);
        $transport = $this->transport($circuits);
        $this->mock->append(new \GuzzleHttp\Exception\InvalidArgumentException('Invalid option'));

        try {
            $transport->send(new OutgoingRequest('GET', '/tenants/school-1'));
            $this->fail('A configuration error was expected to reach the caller');
        } catch (\GuzzleHttp\Exception\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->assertCount(1, $this->history);
        $this->assertFalse($circuits->for('ru-1')->isOpen());
    }

    public function testAfterTheCooldownOneProbeIsAllowedAndItsSuccessClosesTheCircuit(): void
    {
        $now       = 1_700_000_000;
        $clock     = function () use (&$now): int {
            return $now;
        };
        $circuits  = new CircuitBreakers(new ArrayCache($clock), 1, 30, $clock);
        $transport = $this->transport($circuits, delays: []);
        $this->mock->append(new Response(500));

        try {
            $transport->send(new OutgoingRequest('GET', '/tenants/school-1'));
        } catch (ServiceUnavailableException) {
            $this->addToAssertionCount(1);
        }

        $now    += 31;
        $circuit = $circuits->for('ru-1');
        $circuit->assertClosed();

        try {
            $circuit->assertClosed();
            $this->fail('A second call was expected to wait for the probe');
        } catch (CircuitOpenException) {
            $this->addToAssertionCount(1);
        }

        $circuit->recordSuccess();
        $this->mock->append($this->jsonResponse(200, ['ok' => true]));
        $this->assertSame(['ok' => true], $transport->send(new OutgoingRequest('GET', '/tenants/school-1')));
    }

    public function testTheCircuitOpensWhenTheCacheReturnsNumbersAsStrings(): void
    {
        $circuits  = new CircuitBreakers(new ArrayCache(numbersAsStrings: true), 2, 60);
        $transport = $this->transport($circuits, delays: [0]);
        $this->mock->append(new Response(500), new Response(500));

        try {
            $transport->send(new OutgoingRequest('GET', '/tenants/school-1'));
            $this->fail('Two 500 answers were expected to fail the call');
        } catch (ServiceUnavailableException $e) {
            $this->assertStringContainsString('answered 500', $e->getMessage());
        }

        $this->assertTrue($circuits->for('ru-1')->isOpen());
    }

    public function testAFailedProbeOpensTheCircuitForAnotherCooldown(): void
    {
        $now      = 1_700_000_000;
        $clock    = function () use (&$now): int {
            return $now;
        };
        $circuits = new CircuitBreakers(new ArrayCache($clock), 3, 30, $clock);
        $circuit  = $circuits->for('ru-1');
        $circuit->recordFailure();
        $circuit->recordFailure();
        $circuit->recordFailure();

        $now      += 31;
        $transport = $this->transport($circuits, delays: [0, 0]);
        $this->mock->append(new Response(503));

        try {
            $transport->send(new OutgoingRequest('GET', '/tenants/school-1'));
            $this->fail('The probe was expected to fail');
        } catch (ServiceUnavailableException $e) {
            $this->assertStringContainsString('answered 503', $e->getMessage());
        }

        // The probe is not retried: one failure reopens the breaker.
        $this->assertCount(1, $this->history);
        $this->assertTrue($circuit->isOpen());

        $now += 31;
        $circuit->assertClosed();
        $this->assertFalse($circuit->isOpen());
    }

    /**
     * @param list<int>|null $delays
     */
    private function transport(
        ?CircuitBreakers $circuits = null,
        string $code = 'ru-1',
        ?\GuzzleHttp\ClientInterface $http = null,
        ?array $delays = null,
        int $retryAfterCapSeconds = 2,
        ?\Closure $sleeper = null,
    ): Transport {
        return new Transport(
            $http ?? $this->mockHttp(),
            new Endpoint($code, "https://{$code}.school.example", 'key-1', 'whsec-1'),
            ($circuits ?? new CircuitBreakers(new ArrayCache()))->for($code),
            $delays ?? [0, 0],
            retryAfterCapSeconds: $retryAfterCapSeconds,
            sleeper: $sleeper,
        );
    }
}
