<?php

declare(strict_types=1);

namespace Mnd\DmPlatform\Tests\Unit;

use Mnd\DmPlatform\Dto\Entitlements;
use Mnd\DmPlatform\Dto\TenantInput;
use Mnd\DmPlatform\Endpoint;
use Mnd\DmPlatform\Exceptions\DmPlatformException;
use Mnd\DmPlatform\Http\CircuitBreakers;
use Mnd\DmPlatform\DmPlatformClient;
use PHPUnit\Framework\TestCase;

class DmPlatformClientTest extends TestCase
{
    use MocksHttp;

    private const SCHOOL = '01920000-0000-7000-8000-000000000001';

    public function testAvailabilityUsesTheInstallationOriginAndNoIdempotencyKey(): void
    {
        $client = $this->client();
        $this->mock->append($this->jsonResponse(200, ['available' => true, 'reason' => null]));

        $result = $client->availability('drift');

        $this->assertTrue($result->available);
        $this->assertNull($result->reason);
        $request = $this->sentRequest();
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame(
            'https://ru-1.school.example/api/v1/tenants/availability?tech_name=drift',
            (string) $request->getUri()
        );
        $this->assertSame('Bearer key-1', $request->getHeaderLine('Authorization'));
        $this->assertNotSame('', $request->getHeaderLine('X-Request-Id'));
        $this->assertSame('', $request->getHeaderLine('Idempotency-Key'));
    }

    public function testPutTenantReadsTheSnapshot(): void
    {
        $client = $this->client();
        $this->mock->append($this->jsonResponse(201, $this->snapshot()));

        $snapshot = $client->putTenant(
            self::SCHOOL,
            new TenantInput(
                'drift',
                'Дрифт',
                'RU',
                'RUB',
                'Europe/Moscow',
                'Иван',
                'ivan@example.com',
            )
        );

        $this->assertSame(self::SCHOOL, $snapshot->schoolId);
        $this->assertSame('drift', $snapshot->techName);
        $this->assertSame('school.example', $snapshot->domains[0]->host);
        $this->assertTrue($snapshot->domains[0]->isPrimary);
        $this->assertSame(4, $snapshot->entitlements?->usersLimit);
        $request = $this->sentRequest();
        $this->assertSame('PUT', $request->getMethod());
        $this->assertSame(
            'https://ru-1.school.example/api/v1/tenants/' . self::SCHOOL,
            (string) $request->getUri()
        );
        $this->assertSame('', $request->getHeaderLine('Idempotency-Key'));
        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('drift', $body['tech_name']);
        $this->assertSame('ivan@example.com', $body['owner']['email']);
    }

    public function testPreviewAndActivationSendAnIdempotencyKey(): void
    {
        $client = $this->client();
        $this->mock->append(
            $this->jsonResponse(200, ['allowed' => false, 'reason' => 'users_limit_below_usage', 'users_usage' => 6]),
            $this->jsonResponse(200, ['url' => 'https://ru-1.school.example/activate/1', 'expires_at' => '2026-10-23T10:00:00+03:00']),
        );

        $preview = $client->previewEntitlements(self::SCHOOL, 3, 'idem-preview');
        $link    = $client->createOwnerActivation(self::SCHOOL);

        $this->assertFalse($preview->allowed);
        $this->assertSame(6, $preview->usersUsage);
        $this->assertSame('idem-preview', $this->sentRequest(0)->getHeaderLine('Idempotency-Key'));
        $this->assertSame('POST', $this->sentRequest(1)->getMethod());
        $this->assertGreaterThanOrEqual(8, strlen($this->sentRequest(1)->getHeaderLine('Idempotency-Key')));
        $this->assertSame('https://ru-1.school.example/activate/1', $link->url);
    }

    public function testDeleteAndEntitlementsUseTheirPaths(): void
    {
        $client = $this->client();
        $this->mock->append(
            $this->jsonResponse(200, ['applied' => true, 'version' => 2, 'users_usage' => 1]),
            $this->jsonResponse(202, ['school_id' => self::SCHOOL, 'requested_at' => '2026-10-20T10:00:00+03:00']),
        );

        $applied  = $client->putEntitlements(self::SCHOOL, new Entitlements(2, 'active', 10, null, null, null));
        $deletion = $client->deleteTenant(self::SCHOOL);

        $this->assertTrue($applied->applied);
        $this->assertSame(
            'https://ru-1.school.example/api/v1/tenants/' . self::SCHOOL . '/entitlements',
            (string) $this->sentRequest(0)->getUri()
        );
        $this->assertSame(self::SCHOOL, $deletion->schoolId);
        $this->assertSame('DELETE', $this->sentRequest(1)->getMethod());
    }

    public function testGetTenantReadsTheSnapshotWithoutEntitlements(): void
    {
        $client = $this->client();
        $this->mock->append($this->jsonResponse(200, ['entitlements' => null] + $this->snapshot()));

        $snapshot = $client->getTenant(self::SCHOOL);

        $this->assertNull($snapshot->entitlements);
        $this->assertSame('GET', $this->sentRequest()->getMethod());
    }

    public function testABrokenSnapshotIsRefused(): void
    {
        $client   = $this->client();
        $snapshot = $this->snapshot();
        unset($snapshot['users_usage']);
        $this->mock->append($this->jsonResponse(200, $snapshot));

        $this->expectException(DmPlatformException::class);
        $this->expectExceptionMessage('users_usage');
        $client->getTenant(self::SCHOOL);
    }

    public function testSendStoredRepeatsTheStoredIdempotencyKey(): void
    {
        $client = $this->client();
        $this->mock->append($this->jsonResponse(200, ['url' => 'https://ru-1.school.example/activate/1']));

        $body = $client->sendStored('POST', '/tenants/' . self::SCHOOL . '/owner-activation', null, 'idem-stored');

        $this->assertSame('https://ru-1.school.example/activate/1', $body['url']);
        $this->assertSame('idem-stored', $this->sentRequest()->getHeaderLine('Idempotency-Key'));
    }

    public function testTheEndpointHidesItsSecretsFromDumps(): void
    {
        $dump = print_r(new Endpoint('ru-1', 'https://ru-1.school.example', 'key-1', 'whsec-1'), true);

        $this->assertStringNotContainsString('key-1', $dump);
        $this->assertStringNotContainsString('whsec-1', $dump);
    }

    private function client(): DmPlatformClient
    {
        return DmPlatformClient::connect(
            new Endpoint('ru-1', 'https://ru-1.school.example/', 'key-1', 'whsec-1'),
            new CircuitBreakers(new ArrayCache()),
            $this->mockHttp(),
            [0],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        return [
            'school_id'          => self::SCHOOL,
            'state'              => 'active',
            'tech_name'          => 'drift',
            'name'               => 'Дрифт',
            'domains'            => [
                ['host' => 'school.example', 'kind' => 'system', 'status' => 'active', 'is_primary' => true],
            ],
            'admin_user_id'      => null,
            'admin_activated_at' => null,
            'users_usage'        => 1,
            'entitlements'       => [
                'version'     => 1,
                'state'       => 'active',
                'users_limit' => 4,
                'paid_until'  => null,
                'grace_until' => null,
                'delete_after' => null,
                'features'    => null,
            ],
        ];
    }
}
