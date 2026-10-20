<?php

declare(strict_types=1);

namespace Mnd\DmPlatform\Tests\Unit\Laravel;

use Illuminate\Support\ServiceProvider;
use Mnd\DmPlatform\Endpoint;
use Mnd\DmPlatform\Exceptions\InvalidSignatureException;
use Mnd\DmPlatform\Exceptions\DmPlatformException;
use Mnd\DmPlatform\Laravel\DmPlatformServiceProvider;
use Mnd\DmPlatform\DmPlatformClients;
use Mnd\DmPlatform\Webhook\WebhookVerifier;
use Orchestra\Testbench\TestCase;
use ReflectionProperty;

class DmPlatformServiceProviderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [DmPlatformServiceProvider::class];
    }

    public function testTheDefaultsComeFromThePackageConfig(): void
    {
        $clients = $this->app->make(DmPlatformClients::class);

        $this->assertSame($clients, $this->app->make(DmPlatformClients::class));
        $this->assertSame([200, 500], $this->setting($clients, 'retryDelaysMs'));
        $this->assertSame(5.0, $this->setting($clients, 'timeout'));
        $this->assertSame(300, $this->setting($clients, 'webhookToleranceSeconds'));
    }

    public function testTheCircuitUsesTheConfiguredLaravelCacheStore(): void
    {
        config(['dmplatform.cache_store' => 'array']);

        $circuits = $this->setting($this->app->make(DmPlatformClients::class), 'circuits');
        $cache    = (new ReflectionProperty($circuits, 'cache'))->getValue($circuits);

        $this->assertSame($this->app->make('cache')->store('array'), $cache);
    }

    public function testTheDelaysAreReadFromAnEnvString(): void
    {
        config(['dmplatform.retry.delays_ms' => ' 100, 300 ']);

        $this->assertSame([100, 300], $this->setting($this->app->make(DmPlatformClients::class), 'retryDelaysMs'));
    }

    public function testAnEmptyDelayListTurnsRetriesOff(): void
    {
        config(['dmplatform.retry.delays_ms' => '']);

        $this->assertSame([], $this->setting($this->app->make(DmPlatformClients::class), 'retryDelaysMs'));
    }

    public function testADelayThatIsNotANumberIsRefused(): void
    {
        config(['dmplatform.retry.delays_ms' => '200,soon']);

        $this->expectException(DmPlatformException::class);
        $this->expectExceptionMessage('soon');
        $this->app->make(DmPlatformClients::class);
    }

    public function testTheWebhookToleranceReachesTheVerifier(): void
    {
        config(['dmplatform.webhooks.tolerance' => 10]);
        $endpoint = new Endpoint('ru-1', 'https://ru-1.school.example', 'key-1', 'whsec-1');
        $body     = '{}';
        $header   = WebhookVerifier::sign('whsec-1', $body, 1_700_000_000);

        $this->expectException(InvalidSignatureException::class);
        $this->expectExceptionMessage('too old');
        $this->app->make(DmPlatformClients::class)->for($endpoint)->webhooks()->verify($header, $body, 1_700_000_011);
    }

    public function testTheConfigCanBePublished(): void
    {
        $paths = ServiceProvider::pathsToPublish(DmPlatformServiceProvider::class, 'dmplatform-config');

        $this->assertContains($this->app->configPath('dmplatform.php'), $paths);
    }

    private function setting(DmPlatformClients $clients, string $name): mixed
    {
        return (new ReflectionProperty($clients, $name))->getValue($clients);
    }
}
