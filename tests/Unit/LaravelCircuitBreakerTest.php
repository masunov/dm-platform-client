<?php

declare(strict_types=1);

namespace Mnd\DmPlatform\Tests\Unit;

use Carbon\Carbon;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Mnd\DmPlatform\Exceptions\CircuitOpenException;
use Mnd\DmPlatform\Http\CircuitBreakers;
use PHPUnit\Framework\TestCase;

/**
 * The breaker on a Laravel cache: failures are counted with increment() and the probe is claimed with add().
 */
class LaravelCircuitBreakerTest extends TestCase
{
    /** @var list<string> */
    private array $calls = [];

    protected function setUp(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(1_700_000_000));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
    }

    public function testFailuresAreCountedWithIncrement(): void
    {
        $circuit = $this->circuits(threshold: 3)->for('ru-1');

        $circuit->recordFailure();
        $circuit->recordFailure();
        $this->assertFalse($circuit->isOpen());

        $circuit->recordFailure();
        $this->assertTrue($circuit->isOpen());
        $this->assertSame(3, count(array_keys($this->calls, 'increment:dmplatform:circuit:ru-1:failures', true)));
    }

    public function testTwoBreakersOfOneInstallationShareTheCounter(): void
    {
        $circuits = $this->circuits(threshold: 2);

        $circuits->for('ru-1')->recordFailure();
        $circuits->for('ru-1')->recordFailure();

        $this->assertTrue($circuits->for('ru-1')->isOpen());
        $this->assertFalse($circuits->for('kz-1')->isOpen());
    }

    public function testTheCounterWindowStartsAtTheFirstFailure(): void
    {
        $circuit = $this->circuits(threshold: 2, cooldown: 30)->for('ru-1');

        $circuit->recordFailure();
        Carbon::setTestNow(Carbon::now()->addSeconds(31));
        $circuit->recordFailure();

        $this->assertFalse($circuit->isOpen());
    }

    public function testOnlyOneProbeIsClaimedAfterTheCooldown(): void
    {
        $circuits = $this->circuits(threshold: 1, cooldown: 30);
        $circuits->for('ru-1')->recordFailure();

        Carbon::setTestNow(Carbon::now()->addSeconds(31));
        $circuits->for('ru-1')->assertClosed();

        $this->assertContains('add:dmplatform:circuit:ru-1:probe', $this->calls);
        $this->expectException(CircuitOpenException::class);
        $circuits->for('ru-1')->assertClosed();
    }

    public function testASuccessClearsTheCounter(): void
    {
        $circuit = $this->circuits(threshold: 2)->for('ru-1');

        $circuit->recordFailure();
        $circuit->recordSuccess();
        $circuit->recordFailure();

        $this->assertFalse($circuit->isOpen());
    }

    private function circuits(int $threshold = 5, int $cooldown = 30): CircuitBreakers
    {
        $calls = &$this->calls;
        $cache = new class (new ArrayStore(), $calls) extends Repository {
            /**
             * @param list<string> $calls
             */
            public function __construct(ArrayStore $store, private array &$calls)
            {
                parent::__construct($store);
            }

            public function add($key, $value, $ttl = null)
            {
                $this->calls[] = 'add:' . $key;

                return parent::add($key, $value, $ttl);
            }

            public function increment($key, $value = 1)
            {
                $this->calls[] = 'increment:' . $key;

                return parent::increment($key, $value);
            }
        };

        return new CircuitBreakers($cache, $threshold, $cooldown, static fn (): int => Carbon::now()->getTimestamp());
    }
}
