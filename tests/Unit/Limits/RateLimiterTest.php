<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Unit\Limits;

use Mfonte\ImageConversionApi\Exception\RuntimeException;
use Mfonte\ImageConversionApi\Limits\RateLimiter;
use Mfonte\ImageConversionApi\Tests\Fixtures\Limits\MemoryRateLimitStore;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ImageConversionApi\Limits\RateLimiter
 */
#[CoversClass(RateLimiter::class)]
final class RateLimiterTest extends TestCase
{
    /**
     * @var int microseconds
     */
    private $now = 1000000000000000;

    public function testAllowsTheBurstThenRefusesUntilTheNextToken(): void
    {
        $limiter = $this->limiter(new MemoryRateLimitStore(), 60, 5);

        for ($request = 0; $request < 5; ++$request) {
            self::assertSame(0, $limiter->consume('client'), 'request ' . $request . ' of the burst');
        }
        self::assertSame(1, $limiter->consume('client'));

        $this->now += 999999;
        self::assertSame(1, $limiter->consume('client'), 'one token per second, not yet');
        $this->now += 1;
        self::assertSame(0, $limiter->consume('client'));
        self::assertSame(1, $limiter->consume('client'));
    }

    public function testTellsHowLongToWait(): void
    {
        $limiter = $this->limiter(new MemoryRateLimitStore(), 6, 1);

        self::assertSame(0, $limiter->consume('client'));
        self::assertSame(10, $limiter->consume('client'), 'six per minute: one every ten seconds');
        $this->now += 2500000;
        self::assertSame(8, $limiter->consume('client'), '7.5 seconds rounded up');
    }

    public function testARefusedRequestTakesNoToken(): void
    {
        $limiter = $this->limiter(new MemoryRateLimitStore(), 60, 1);

        self::assertSame(0, $limiter->consume('client'));
        for ($request = 0; $request < 100; ++$request) {
            $limiter->consume('client');
        }
        $this->now += 1000000;

        self::assertSame(0, $limiter->consume('client'), 'hammering does not push the next token further');
    }

    public function testTheBucketRefillsUpToTheBurstOnly(): void
    {
        $limiter = $this->limiter(new MemoryRateLimitStore(), 60, 3);
        $limiter->consume('client');
        $this->now += 3600 * 1000000;

        $allowed = 0;
        for ($request = 0; $request < 10; ++$request) {
            $allowed += $limiter->consume('client') === 0 ? 1 : 0;
        }

        self::assertSame(3, $allowed);
    }

    public function testEveryClientHasItsOwnBucket(): void
    {
        $limiter = $this->limiter(new MemoryRateLimitStore(), 60, 1);

        self::assertSame(0, $limiter->consume('first'));
        self::assertSame(1, $limiter->consume('first'));
        self::assertSame(0, $limiter->consume('second'));
    }

    /**
     * The wall clock steps back by an hour after the burst was spent: the
     * key waits at most one interval more, not the hour.
     */
    public function testAClockThatStepsBackDoesNotLockTheKeyOut(): void
    {
        $limiter = $this->limiter(new MemoryRateLimitStore(), 60, 3);
        for ($request = 0; $request < 3; ++$request) {
            self::assertSame(0, $limiter->consume('client'));
        }

        $this->now -= 3600000000;

        self::assertSame(1, $limiter->consume('client'), 'one interval, not 3600 seconds');
        $this->now += 1000000;
        self::assertSame(0, $limiter->consume('client'));
        self::assertSame(1, $limiter->consume('client'), 'the bucket counts again from the new time');
    }

    public function testThrowsWhenTheStoreFails(): void
    {
        $this->expectException(RuntimeException::class);

        $this->limiter(new MemoryRateLimitStore(true), 60, 10)->consume('client');
    }

    public function testAverageRateOverAMinute(): void
    {
        $limiter = $this->limiter(new MemoryRateLimitStore(), 120, 10);

        $allowed = 0;
        for ($tick = 0; $tick < 600; ++$tick) {
            $allowed += $limiter->consume('client') === 0 ? 1 : 0;
            $this->now += 100000;
        }

        self::assertGreaterThanOrEqual(129, $allowed, 'the burst plus two per second over 60 seconds');
        self::assertLessThanOrEqual(130, $allowed);
    }

    private function limiter(MemoryRateLimitStore $store, int $perMinute, int $burst): RateLimiter
    {
        return new RateLimiter($store, $perMinute, $burst, function (): int {
            return $this->now;
        });
    }
}
