<?php

declare(strict_types=1);

namespace Mnd\DmPlatform\Laravel;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Mnd\DmPlatform\Exceptions\DmPlatformException;
use Mnd\DmPlatform\Http\CircuitBreakers;
use Mnd\DmPlatform\DmPlatformClients;

/**
 * Builds DmPlatformClients from config/dmplatform.php. The rest of the package does not depend on Laravel.
 */
final class DmPlatformServiceProvider extends ServiceProvider
{
    private const CONFIG = __DIR__ . '/../../config/dmplatform.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG, 'dmplatform');

        $this->app->singleton(
            DmPlatformClients::class,
            static function (Application $app): DmPlatformClients {
                $config = $app->make(Config::class);
                $store  = $config->get('dmplatform.cache_store');

                return new DmPlatformClients(
                    new CircuitBreakers(
                        $app->make(CacheFactory::class)->store(is_string($store) && $store !== '' ? $store : null),
                        (int) $config->get('dmplatform.circuit.threshold', 5),
                        (int) $config->get('dmplatform.circuit.cooldown', 30),
                    ),
                    retryDelaysMs: self::delays($config->get('dmplatform.retry.delays_ms', [200, 500])),
                    retryAfterCapSeconds: (int) $config->get('dmplatform.retry.retry_after_cap', 2),
                    timeout: (float) $config->get('dmplatform.timeout', 5),
                    connectTimeout: (float) $config->get('dmplatform.connect_timeout', 2),
                    webhookToleranceSeconds: (int) $config->get('dmplatform.webhooks.tolerance', 300),
                );
            }
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([self::CONFIG => $this->app->configPath('dmplatform.php')], 'dmplatform-config');
        }
    }

    /**
     * The delays come from .env as "200,500" or from a published config as a list.
     *
     * @return list<int>
     */
    private static function delays(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $items  = is_array($value) ? $value : explode(',', (string) $value);
        $delays = [];

        foreach ($items as $item) {
            $item = trim((string) $item);

            if (! ctype_digit($item)) {
                throw new DmPlatformException("dmplatform.retry.delays_ms has a delay that is not a number: '{$item}'");
            }

            $delays[] = (int) $item;
        }

        return $delays;
    }
}
